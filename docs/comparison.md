# Compared with Symfony and Laravel

Symfony and Laravel both ship a way to send Server-Sent Events: Symfony's `EventStreamResponse` (since 7.3) and
Laravel's `response()->eventStream()`. They're good tools, and for some jobs they're all you need. This page
compares them with this library, and says when to pick which.

## In short

- **Laravel's `eventStream()`** streams values from a generator until it ends. It's made for one-off streams, such
  as the answer of an AI model, especially with Laravel's `useEventStream` hooks for React, Vue and Svelte.
- **Symfony's `EventStreamResponse`** sends `ServerEvent` objects from a generator or a callback, with names, ids,
  `retry` and comments. It's a solid building block: the loop, the resuming and the keep-alives are up to you.
- **This library** handles the whole life of a long-running stream: polling at a fixed interval, resuming after a
  reconnection, heartbeats, a maximum duration, start and stop hooks, errors and tests. It works the same in plain
  PHP, Symfony and Laravel.

## Feature by feature

| | Symfony `EventStreamResponse` | Laravel `eventStream()` | This library |
|---|---|---|---|
| Requires | `symfony/http-foundation` 7.3+ | Laravel | Nothing; bridges for Symfony 6.4+ and Laravel 12+ |
| Event names and data | ✅ | ✅ (named `update` by default) | ✅ |
| Event ids, so the browser can resume | ✅ | ❌ | ✅ |
| `retry` (reconnection delay) | ✅ | ❌ | ✅ |
| Comments | ✅ | ❌ | ✅ |
| Reading `Last-Event-ID` | From the request, yourself | From the request, but without ids it's never sent | `$stream->lastEventId()`, which also follows the ids you send |
| Polling at a fixed interval | Your own loop | Your own loop | `every()`, compensating for the time your code takes |
| Keep-alives while waiting | Yield a comment yourself | ❌ | `heartbeat()`, sent on time even between ticks |
| Ending streams regularly | Your own loop | Your own loop | `maxDuration()` |
| Stops when the client disconnects | ✅, after the next event | ✅, after the next event | ✅, after the next event or heartbeat |
| Start, stop and error hooks | ❌ | ❌ | `onStart()`, `onStop()` with the reason, `onError()` |
| End of stream | When the callback returns | Sends a final `</stream>` event | When the generator ends, `stop()` is called, or the maximum duration is reached |
| Testing in a unit test | Capture `sendContent()`, in real time | `assertStreamedContent()`, in real time | `StreamTester`, with a fake clock and simulated disconnections |
| Frontend helpers | ❌ | `useEventStream` for React, Vue, Svelte | ❌, the native `EventSource` |

No option detects a disconnection while the stream is silent: PHP only notices when it tries to write. That's one of
the reasons for heartbeats.

All three can be tested without a server. The difference is time: Symfony's and Laravel's tests run the stream for
real, so a stream that waits 2 seconds between checks takes that long in the test, and one that only ends when the
client leaves never returns. `StreamTester` replaces the clock, so waiting is instant, and it can simulate the client
leaving after a number of ticks.

## The same stream, three ways

A stream of notifications that checks for new ones every 2 seconds, keeps the connection alive, ends itself after 5
minutes, and resumes without missing anything when the browser reconnects.

### Symfony `EventStreamResponse`

```php
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ServerEvent;

public function notifications(Request $request): EventStreamResponse
{
    $lastId = (int) $request->headers->get('Last-Event-ID');

    return new EventStreamResponse(function () use ($lastId): iterable {
        $endsAt = time() + 300;
        while (time() < $endsAt) {
            $notifications = $this->notifications->newerThan($lastId);
            foreach ($notifications as $notification) {
                yield new ServerEvent(json_encode($notification), type: 'notification', id: (string) $notification['id']);
                $lastId = $notification['id'];
            }
            if ([] === $notifications) {
                yield new ServerEvent(comment: 'keep-alive');
            }
            sleep(2);
        }
    });
}
```

It works, and it's readable. The loop, the timing, the keep-alive and the resume are yours to write and test.

### Laravel `eventStream()`

```php
use Illuminate\Http\StreamedEvent;

Route::get('/notifications', function () {
    return response()->eventStream(function () {
        $lastId = Notification::max('id') ?? 0;
        $endsAt = time() + 300;
        while (time() < $endsAt) {
            foreach (Notification::where('id', '>', $lastId)->get() as $notification) {
                yield new StreamedEvent(event: 'notification', data: $notification->toArray());
                $lastId = $notification->id;
            }
            sleep(2);
        }
    });
});
```

`StreamedEvent` has no id, so the browser never sends `Last-Event-ID`: after a reconnection, the stream can't know
what was missed. It also can't send comments, so a quiet stream gets no keep-alives.

### This library

```php
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

$stream = EventStream::create()
    ->every(2)
    ->heartbeat(15)
    ->maxDuration(300)
    ->onTick(function (Stream $stream) {
        foreach (Notification::newerThan((int) $stream->lastEventId()) as $notification) {
            $stream->send(Event::json('notification', $notification, id: (string) $notification->id));
        }
    });

return response()->sse($stream); // Laravel; StreamedResponseFactory::create($stream, $request) in Symfony
```

The same definition runs in plain PHP with `$stream->send()`. In a test, it runs instantly, although it waits between
ticks and never ends on its own:

```php
$result = StreamTester::for($stream)->withLastEventId('41')->disconnectAfter(1)->run();
```

## When to use which

**Use Laravel's `eventStream()`** when you stream a response that has an end, like the answer of an AI model, and
especially if your frontend uses Laravel's `useEventStream` hooks. Resuming doesn't matter for those streams.

**Use Symfony's `EventStreamResponse`** when you're on Symfony 7.3 or newer, your stream is a simple generator, and
you're happy to write the loop yourself. It has no extra dependency.

**Use this library** when the stream runs for a long time and must survive reconnections: notifications, dashboards,
job progress, live feeds. Also when you want the same code in plain PHP and in a framework, or tests that don't wait
for real time.

**Use a dedicated server such as [Mercure](https://mercure.rocks)** for thousands of simultaneous clients, or to send
the same message to many clients at once. All three options above keep one PHP worker busy per open stream, see
[Running in production](production.md#when-to-use-something-else).
