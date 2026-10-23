# PHP Event Source

**Stream real-time updates from PHP to the browser with [Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html).**

[![CI](https://github.com/FunkyOz/event-source/actions/workflows/ci.yml/badge.svg)](https://github.com/FunkyOz/event-source/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/dessimoney/event-source)](https://packagist.org/packages/dessimoney/event-source)
[![PHP version](https://img.shields.io/packagist/dependency-v/dessimoney/event-source/php)](https://packagist.org/packages/dessimoney/event-source)
[![License](https://img.shields.io/github/license/FunkyOz/event-source)](LICENSE)

Server-Sent Events let a server push messages to the browser over a plain HTTP connection. The browser side is
built in (`new EventSource(url)`), reconnects on its own and resumes where it left off. This library is the PHP side:
it keeps the response open, formats your events correctly and stops cleanly when the client goes away.

```php
$sender = new EventSender();
$sender->addWriteListener(function (EventBufferInterface $buffer) {
    $buffer->write(new Event('time', date('H:i:s')));
});
$sender->send();
```

- **Small and focused**: a handful of classes, built on Symfony's `StreamedResponse`
- **Correct output**: follows the specification, including multi-line data, event ids and reconnection delays
- **Works anywhere**: plain PHP, Symfony, Laravel or any framework that understands Symfony responses
- **Clean shutdown**: stop listeners run even when the user closes the tab
- **Testable**: write events to any stream and replace the timing and connection checks in your tests

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [Sending events](#sending-events)
- [Controlling the stream](#controlling-the-stream)
- [Using it in a framework](#using-it-in-a-framework)
- [Customizing the output](#customizing-the-output)
- [Testing your code](#testing-your-code)
- [Running it in production](#running-it-in-production)
- [API reference](#api-reference)
- [Upgrading from 1.x](#upgrading-from-1x)

## Installation

```bash
composer require dessimoney/event-source
```

Requires PHP 8.2 or newer. It works with `symfony/http-foundation` 6.4, 7.x and 8.x.

## Quick start

**1. The server** (`events.php`) sends the current time every second:

```php
<?php

use EventSource\Event;
use EventSource\EventBufferInterface;
use EventSource\EventSender;

require __DIR__ . '/vendor/autoload.php';

$sender = new EventSender();
$sender->addWriteListener(function (EventBufferInterface $buffer) {
    $buffer->write(new Event('time', date('H:i:s')));
});
$sender->send();
```

**2. The browser** listens for those events:

```html
<p>Server time: <span id="time">…</span></p>
<script>
    const source = new EventSource('events.php');
    source.addEventListener('time', (event) => {
        document.getElementById('time').textContent = event.data;
    });
</script>
```

**3. Try it:**

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000
```

Open http://localhost:8000 and the time updates every second. The server needs more than one worker, because each
open stream keeps one busy.

For a complete demo with ids, reconnection and a final event, clone this repository and run `composer example`
(see [`examples/`](examples)).

## How it works

`send()` opens the response and runs your listeners in this order:

```
start listeners          once, when the stream opens
     │
     ▼
┌─▶ write listeners       every listener, on every iteration
│    │
│    ├─ a listener returned true?   ──yes──┐
│    ▼                                     │
│   flush output to the client             │
│    │                                     │
│    ├─ client disconnected?        ──yes──┤
│    ▼                                     │
└── sleep (1 second by default)            │
                                           ▼
stop listeners           once, at the end, also after a disconnection
```

Every listener receives the buffer to write events to. A write listener is typically where you check for something
new, like a database row, a queue message or a job's progress, and send it.

```php
$sender->addStartListener(function (EventBufferInterface $buffer) {
    // Runs once, before the loop: open resources, send an initial state...
});

$sender->addWriteListener(function (EventBufferInterface $buffer) {
    // Runs on every iteration: send what changed since the last one
});

$sender->addStopListener(function (EventBufferInterface $buffer) {
    // Runs once, after the loop: release resources, log the disconnection...
});
```

You can add as many listeners of each kind as you need. They run in the order you add them.

## Sending events

An event has four fields, all optional:

```php
new Event(
    eventName: 'order-update',          // the type the browser listens for, 'message' by default
    data: '{"id":42,"status":"paid"}',  // the payload, always a string
    id: '42',                           // lets the browser resume from this event after a reconnection
    retry: 5,                           // seconds the browser waits before reconnecting
);
```

This is what the browser receives:

```
event: order-update
id: 42
retry: 5000
data: {"id":42,"status":"paid"}

```

**Sending structured data.** The payload is a string, so encode arrays and objects as JSON, then decode them in the
browser:

```php
$buffer->write(new Event('order-update', json_encode($order, JSON_THROW_ON_ERROR)));
```

```js
source.addEventListener('order-update', (event) => {
    const order = JSON.parse(event.data);
});
```

**Multi-line data** is supported: each line is sent as its own `data:` field, and the browser joins them back.

**Named and unnamed events.** The browser handles events named `message` (the default) with `source.onmessage`.
Any other name needs `source.addEventListener('name', ...)`. Pass `null` as the name to omit the `event:` field.

**Sending immediately.** Everything written during an iteration is flushed at the end of it. To send an event
straight away, for example before a slow operation, pass `true` as the second argument:

```php
$buffer->write(new Event('progress', 'Starting the export…'), true);
```

## Controlling the stream

### Frequency

Write listeners run every second by default. Change it with `setSleep()`, which accepts fractions of a second:

```php
$sender->setSleep(0.5);
```

### Ending the stream

A stream ends when:

- **a write listener returns `true`**. Return anything else, or nothing, to keep it going;
- **the client disconnects**, for example because the user closed the tab. The library notices on the next
  flush, stops the loop and runs your stop listeners.

```php
$sender->addWriteListener(function (EventBufferInterface $buffer) use ($job) {
    $buffer->write(new Event('progress', (string) $job->progress()));

    return $job->isFinished();
});
```

> [!IMPORTANT]
> When the server ends a stream, the browser reconnects after a few seconds. That's a feature of `EventSource`. To
> finish for good, send a last event and close the connection from the browser:
>
> ```php
> $sender->addStopListener(function (EventBufferInterface $buffer) {
>     $buffer->write(new Event('done'));
> });
> ```
>
> ```js
> source.addEventListener('done', () => source.close());
> ```
>
> Or, when the client reconnects and there is nothing left to send, answer with `http_response_code(204)` instead
> of opening a stream: browsers don't reconnect after a `204 No Content` response.

### Resuming after a reconnection

When the connection drops, the browser reconnects and sends the `id` of the last event it received in the
`Last-Event-ID` header. Use it to send only what the client missed:

```php
$lastId = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);

$sender->addWriteListener(function (EventBufferInterface $buffer) use (&$lastId) {
    foreach (Notification::newerThan($lastId) as $notification) {
        $buffer->write(new Event('notification', $notification->toJson(), (string) $notification->id));
        $lastId = $notification->id;
    }
});
```

Set `retry` on any event to change how long the browser waits before reconnecting.

### Headers

The response is sent with `Content-Type: text/event-stream`, `Cache-Control: no-cache` and
`X-Accel-Buffering: no`. You can add or replace headers, for example to allow another origin:

```php
$sender->addHeader('Access-Control-Allow-Origin', 'https://app.example.com');

$sender->addHeaders([
    'Access-Control-Allow-Origin' => 'https://app.example.com',
    'Access-Control-Allow-Credentials' => 'true',
]);
```

## Using it in a framework

`send()` streams the events while it runs, then returns the Symfony `StreamedResponse` it sent. Symfony streams a
response only once, so you can return it from a controller.

**Symfony**

```php
#[Route('/events')]
public function events(): StreamedResponse
{
    $sender = new EventSender();
    $sender->addWriteListener(function (EventBufferInterface $buffer) {
        $buffer->write(new Event('time', date('H:i:s')));
    });

    return $sender->send();
}
```

**Laravel**

```php
Route::get('/events', function () {
    $sender = new EventSender();
    $sender->addWriteListener(function (EventBufferInterface $buffer) {
        $buffer->write(new Event('time', date('H:i:s')));
    });

    return $sender->send();
});
```

Because the stream is sent while `send()` runs, middleware that changes the response afterwards, for example to
add headers, has no effect on it. Set headers with `addHeader()` instead.

## Customizing the output

### Writing somewhere else

The default buffer writes to the PHP output. Give it any stream to write somewhere else, for example a file to
record what you send:

```php
use EventSource\EventBuffer;

$sender = new EventSender(new EventBuffer(fopen('/tmp/events.log', 'a')));
```

`EventBuffer::format()` returns an event as text without writing it:

```php
echo EventBuffer::format(new Event('ping', 'hello'));
// event: ping
// data: hello
```

### Your own buffer

Implement `EventBufferInterface` to change how events are written, for example to add a field to every payload:

```php
use EventSource\Event;
use EventSource\EventBuffer;
use EventSource\EventBufferInterface;

final class TimestampedBuffer implements EventBufferInterface
{
    public function __construct(private EventBufferInterface $inner = new EventBuffer()) {}

    public function write(Event $event, bool $flush = false): void
    {
        $data = json_decode($event->getData() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $data['sentAt'] = date(DATE_ATOM);
        $event->setData(json_encode($data, JSON_THROW_ON_ERROR));

        $this->inner->write($event, $flush);
    }
}

$sender = new EventSender(new TimestampedBuffer());
```

## Testing your code

You don't need a browser or a server to test your listeners.

**Capture the events** by writing them to a memory stream:

```php
$stream = fopen('php://memory', 'w+');
$buffer = new EventBuffer($stream);

$listener($buffer); // the listener you want to test

rewind($stream);
self::assertSame("event: time\ndata: 12:00:00\n\n", stream_get_contents($stream));
```

**Test a whole stream** without waiting, by replacing the runtime that flushes, sleeps and checks the connection:

```php
use EventSource\RuntimeInterface;

final class InstantRuntime implements RuntimeInterface
{
    public int $flushes = 0;

    public function flush(): void
    {
        $this->flushes++;
    }

    public function sleep(float $seconds): void {} // don't wait

    public function isConnectionAborted(): bool
    {
        return $this->flushes >= 3; // pretend the client leaves after 3 iterations
    }
}

$stream = fopen('php://memory', 'w+');
$runtime = new InstantRuntime();
$sender = new EventSender(new EventBuffer($stream, $runtime), $runtime);
```

## Running it in production

Each open stream is a PHP request that stays alive, so a few settings matter:

| Topic | What to do |
|---|---|
| **Sessions** | PHP locks the session for the whole request, which blocks the user's other requests. Call `session_write_close()` before `send()`. |
| **Time limit** | Long streams can hit `max_execution_time`. Call `set_time_limit(0)` for long-lived streams, or end the stream regularly and let the browser reconnect. |
| **Workers** | Every connected client keeps a PHP-FPM worker busy. Size `pm.max_children` for your expected number of open streams. |
| **nginx** | Response buffering is disabled by the `X-Accel-Buffering: no` header. Also make sure `gzip` is off for this endpoint. |
| **Apache** | Disable compression for the endpoint (`mod_deflate` buffers the response), e.g. `SetEnv no-gzip 1`. |
| **Proxies and load balancers** | Raise idle timeouts above your `setSleep()` interval, or send a regular event (a heartbeat) so the connection never looks idle. |
| **HTTP/1.1** | Browsers allow only 6 connections per domain, shared by all tabs. Serve the endpoint over HTTP/2 to avoid the limit. |

Each client is served by one long-running PHP request, which checks for new data on every iteration. This fits
notifications, progress bars, dashboards and live feeds. For thousands of simultaneous clients, consider a dedicated
real-time server such as [Mercure](https://mercure.rocks).

## API reference

### `EventSender`

| Method | Description |
|---|---|
| `__construct(?EventBufferInterface $buffer = null, ?RuntimeInterface $runtime = null)` | Uses `EventBuffer` and `PhpRuntime` when not given |
| `addStartListener(callable $listener)` | Called once before the loop, with the buffer |
| `addWriteListener(callable $listener)` | Called on every iteration, with the buffer. Return `true` to end the stream |
| `addStopListener(callable $listener)` | Called once after the loop, with the buffer, also after a disconnection |
| `setSleep(float $seconds)` | Pause between iterations, 1 second by default |
| `addHeader(?string $key, ?string $value)` | Adds or replaces a response header |
| `addHeaders(array $headers)` | Adds or replaces several headers, as `name => value` |
| `setBuffer(EventBufferInterface $buffer)` | Replaces the buffer |
| `getListeners()` | Returns the registered listeners by type (`start`, `write`, `stop`) |
| `send(): StreamedResponse` | Streams the events, then returns the response that was sent |

### `Event`

| Field | Type | Default | Description |
|---|---|---|---|
| `eventName` | `?string` | `'message'` | Event type; `null` omits it |
| `data` | `?string` | `null` | Payload; multi-line strings are supported |
| `id` | `?string` | `null` | Sent back by the browser in `Last-Event-ID` after a reconnection |
| `retry` | `?int` | `null` | Seconds the browser waits before reconnecting |

Each field has a getter and a setter, e.g. `getData()` and `setData()`.

### `EventBuffer` (implements `EventBufferInterface`)

| Method | Description |
|---|---|
| `__construct($stream = null, ?RuntimeInterface $runtime = null)` | Writes to the given stream, or to the PHP output |
| `write(Event $event, bool $flush = false)` | Writes the event, and sends it immediately when `$flush` is `true` |
| `static format(Event $event): string` | Returns the event as `text/event-stream` text |

### `RuntimeInterface`

Flushing, sleeping and disconnection checks: `flush()`, `sleep(float $seconds)` and `isConnectionAborted()`.
`PhpRuntime` is the default implementation; replace it in tests.

## Upgrading from 1.x

Version 2.0 fixes how streams behave, which changes a few things:

- **Streams stay open**: write listeners now run until one returns `true` or the client disconnects. In 1.x they
  ran once. If you relied on that, return `true` from your write listener.
- **No default `retry`**: events no longer ask the browser to wait 3 seconds before reconnecting. Pass
  `retry: 3` to keep the old behavior.
- **Stopping needs `true`**: only `true` ends the stream, not other truthy values like `1`.
- **PHP 8.2** is now the minimum version.

See the [changelog](CHANGELOG.md) for the full list.

## Contributing

Bug reports, ideas and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) to get started.

## License

MIT © Lorenzo Dessimoni
