# Recipes

Complete examples for common needs, in plain PHP. In a framework, replace `->send()` with the
[bridge](frameworks.md) and the session code with your framework's authentication.

- [Notifications from a database](#notifications-from-a-database)
- [Progress of a background job](#progress-of-a-background-job)
- [Progress of a task run in the request](#progress-of-a-task-run-in-the-request)
- [A dashboard that sends only changes](#a-dashboard-that-sends-only-changes)
- [Logging the life of streams](#logging-the-life-of-streams)
- [A robust page](#a-robust-page)

## Notifications from a database

Sends each new notification once, and resumes after a reconnection with `Last-Event-ID`.

```php
<?php
// notifications.php

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/vendor/autoload.php';

// Read the user, then release the session lock: see "Running in production"
session_start();
$userId = $_SESSION['user_id'] ?? null;
session_write_close();

if (null === $userId) {
    http_response_code(401); // EventSource won't reconnect after an error status
    exit;
}

$pdo = new PDO('mysql:host=localhost;dbname=app', 'app', 'secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$newer = $pdo->prepare(
    'SELECT id, title, created_at FROM notifications WHERE user_id = :user AND id > :after ORDER BY id LIMIT 50',
);

EventStream::create()
    ->every(2)
    ->heartbeat(15)
    ->maxDuration(300)
    ->retry(5)
    ->onStart(function (Stream $stream) use ($pdo, $userId) {
        // First connection: start from the latest notification, instead of sending the whole history
        if (null === $stream->lastEventId()) {
            $latest = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = :user');
            $latest->execute(['user' => $userId]);
            $stream->send(new Event('ready', id: (string) $latest->fetchColumn()));
        }
    })
    ->onTick(function (Stream $stream) use ($newer, $userId) {
        // lastEventId() follows the ids sent, so this only returns what the browser hasn't seen
        $newer->execute(['user' => $userId, 'after' => (int) $stream->lastEventId()]);

        foreach ($newer->fetchAll(PDO::FETCH_ASSOC) as $notification) {
            $stream->send(Event::json('notification', $notification, id: (string) $notification['id']));
        }
    })
    ->send();
```

```js
const source = new EventSource('/notifications.php');

source.addEventListener('notification', (event) => {
    const notification = JSON.parse(event.data);
    showToast(notification.title);
});
```

**Why it works**

- The ids are the database ids, so after a reconnection `lastEventId()` is the last one the browser received.
- `LIMIT 50` keeps every tick small. If more arrived, the next ticks send the rest.
- `maxDuration(300)` renews the connection every 5 minutes, which frees the database connection and picks up new
  code after a deployment. The browser reconnects after `retry(5)` seconds, and nothing is lost.

## Progress of a background job

A queue worker runs the job and saves its progress. The stream reports it, then tells the browser it's finished.

```php
<?php
// job-progress.php?job=123

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=localhost;dbname=app', 'app', 'secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$find = $pdo->prepare('SELECT progress, status, result_url FROM jobs WHERE id = :id');
$jobId = (int) ($_GET['job'] ?? 0);

$find->execute(['id' => $jobId]);
$job = $find->fetch(PDO::FETCH_ASSOC);

// Unknown or already finished: a 204 tells the browser not to reconnect
if (false === $job || 'finished' === $job['status']) {
    EventStream::sendNoContent();
    exit;
}

$lastProgress = null;
$resultUrl = null;

EventStream::create()
    ->every(1)
    ->heartbeat(15)
    ->maxDuration(600)
    ->onTick(function (Stream $stream) use ($find, $jobId, &$lastProgress, &$resultUrl) {
        $find->execute(['id' => $jobId]);
        $job = $find->fetch(PDO::FETCH_ASSOC);

        if ($job['progress'] !== $lastProgress) {
            $lastProgress = $job['progress'];
            $stream->send(Event::json('progress', ['percent' => (int) $job['progress']]));
        }

        if ('finished' === $job['status']) {
            $resultUrl = $job['result_url'];
            $stream->stop();
        }
    })
    ->onStop(function (Stream $stream) use (&$resultUrl) {
        if (StopReason::Stopped === $stream->stopReason()) {
            $stream->send(Event::json('done', ['url' => $resultUrl]));
        }
    })
    ->send();
```

```js
const source = new EventSource(`/job-progress.php?job=${jobId}`);

source.addEventListener('progress', (event) => {
    progressBar.value = JSON.parse(event.data).percent;
});

source.addEventListener('done', (event) => {
    source.close();
    window.location = JSON.parse(event.data).url;
});
```

**Why it works**

- Progress is sent only when it changes, so a slow job doesn't flood the browser.
- `done` is sent only when the job really finished, not after a disconnection or the maximum duration.
- If the browser reconnects after the job finished, it gets a `204` and stops.

## Progress of a task run in the request

For a task short enough to run within the request, like generating a small report, a
[generator stream](generator-streams.md) does the work and reports it at the same time.

```php
<?php
// export.php

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;

require __DIR__ . '/vendor/autoload.php';

$rows = loadRowsToExport();

EventStream::fromGenerator(function () use ($rows) {
    $file = tempnam(sys_get_temp_dir(), 'export-');
    $handle = fopen($file, 'w');
    $total = count($rows);

    try {
        foreach ($rows as $index => $row) {
            fputcsv($handle, $row);

            // Report every 100 rows, not every row
            if (0 === ($index + 1) % 100 || $index + 1 === $total) {
                yield Event::json('progress', ['done' => $index + 1, 'total' => $total]);
            }
        }
    } finally {
        fclose($handle); // also runs when the user leaves before the end
    }

    yield Event::json('done', ['download' => '/download.php?file=' . basename($file)]);
})
    ->maxDuration(120)
    ->send();
```

If the user closes the tab, the generator isn't resumed and the export stops. For tasks that must finish anyway, or
take minutes, use a [background job](#progress-of-a-background-job).

## A dashboard that sends only changes

Every 10 seconds, the stream computes the figures, and sends them only if they changed. Heartbeats keep the
connection open in between.

```php
<?php
// dashboard.php

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=localhost;dbname=app', 'app', 'secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$previous = null;

EventStream::create()
    ->every(10)
    ->heartbeat(20)
    ->maxDuration(900)
    ->onTick(function (Stream $stream) use ($pdo, &$previous) {
        $stats = [
            'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE created_at >= CURRENT_DATE')->fetchColumn(),
            'revenue' => (float) $pdo->query('SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at >= CURRENT_DATE')->fetchColumn(),
        ];

        if ($stats !== $previous) {
            $previous = $stats;
            $stream->send(Event::json('stats', $stats));
        }
    })
    ->send();
```

With many people watching the same dashboard, cache the figures for a few seconds (APCu, Redis…) so that every
client doesn't run the same queries.

## Logging the life of streams

Start and stop listeners are the place for logs and metrics. `stopReason()`, `elapsed()` and `iteration()` say how
each stream went:

```php
use Psr\Log\LoggerInterface;

function withLogging(EventStream $stream, LoggerInterface $logger, string $name): EventStream
{
    return $stream
        ->onStart(fn (Stream $s) => $logger->info("Stream {$name} opened", ['resumed_from' => $s->lastEventId()]))
        ->onError(fn (\Throwable $e) => $logger->error("Stream {$name} failed", ['exception' => $e]))
        ->onStop(fn (Stream $s) => $logger->info("Stream {$name} closed", [
            'reason' => $s->stopReason()?->name,
            'seconds' => round($s->elapsed(), 1),
            'ticks' => $s->iteration(),
        ]));
}
```

Adding an `onError` listener means exceptions aren't rethrown anymore: log them there. See
[Error handling](error-handling.md).

## A robust page

A page that shows the connection state, reconnects quietly, and stops for good when told to:

```html
<p>Status: <span id="status">connecting…</span></p>
<ul id="items"></ul>

<script>
    const status = document.getElementById('status');
    const source = new EventSource('/notifications.php');

    source.onopen = () => {
        status.textContent = 'live';
    };

    source.onerror = () => {
        // CONNECTING: the browser is reconnecting on its own. CLOSED: it gave up (error status, wrong content type)
        status.textContent = source.readyState === EventSource.CLOSED ? 'offline, reload to retry' : 'reconnecting…';
    };

    source.addEventListener('notification', (event) => {
        const item = document.createElement('li');
        item.textContent = JSON.parse(event.data).title;
        document.getElementById('items').prepend(item);
    });

    source.addEventListener('done', () => {
        source.close();
        status.textContent = 'finished';
    });

    // Close the connection when leaving, so the server notices right away
    window.addEventListener('pagehide', () => source.close());
</script>
```
