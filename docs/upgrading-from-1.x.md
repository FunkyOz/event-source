# Upgrading from 1.x

Version 2.0 is a new API, published under a new package name:

| | 1.x | 2.0 |
|---|---|---|
| Package | `dessimoney/event-source` | `funkyoz/event-source` |
| Namespace | `EventSource\` | `FunkyOz\EventSource\` |
| PHP | 8.1 (1.0.8 to 1.0.10) | 8.2 |
| Dependencies | `symfony/http-foundation` 7 | None |

The namespaces are different, so both packages can be installed at the same time. You can migrate one endpoint at a
time, then remove the old package.

```bash
composer require funkyoz/event-source
# migrate your endpoints…
composer remove dessimoney/event-source
```

`dessimoney/event-source` won't receive new versions. Composer marks it as abandoned, and suggests the new package.

## A typical migration

**1.x:**

```php
use EventSource\Event;
use EventSource\EventBufferInterface;
use EventSource\EventSender;

$sender = new EventSender();
$sender->setSleep(2);
$sender->addHeader('Access-Control-Allow-Origin', '*');
$sender->addStartListener(function (EventBufferInterface $buffer) {
    $buffer->write(new Event('hello', 'connected'));
});
$sender->addWriteListener(function (EventBufferInterface $buffer) {
    $buffer->write(new Event('time', date('H:i:s')));

    return isFinished(); // true stopped the stream
});
$sender->addStopListener(function (EventBufferInterface $buffer) {
    // cleanup
});
$sender->send();
```

**2.0:**

```php
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

EventStream::create()
    ->every(2)
    ->header('Access-Control-Allow-Origin', '*')
    ->onStart(function (Stream $stream) {
        $stream->send(new Event('hello', 'connected'));
    })
    ->onTick(function (Stream $stream) {
        $stream->send(new Event('time', date('H:i:s')));

        if (isFinished()) {
            $stream->stop();
        }
    })
    ->onStop(function (Stream $stream) {
        // cleanup, with $stream->stopReason()
    })
    ->send();
```

## Changes, one by one

| 1.x | 2.0 |
|---|---|
| `new EventSender()` | `EventStream::create()` |
| `addStartListener(fn (EventBufferInterface $buffer) => …)` | `onStart(fn (Stream $stream) => …)` |
| `addWriteListener(…)` | `onTick(…)` |
| Returning `true` from a write listener to stop | `$stream->stop()` |
| `addStopListener(…)` | `onStop(…)`, with `$stream->stopReason()` |
| `$buffer->write($event)` | `$stream->send($event)` |
| `$buffer->write($event, true)` to flush | `$stream->send($event)`: always flushed |
| `setSleep($seconds)` | `every($seconds)`, which also accepts fractions |
| `addHeader($name, $value)` / `addHeaders([…])` | `header($name, $value)` / `headers([…])` |
| `getListeners()` | Removed |
| `setBuffer()`, `EventBufferInterface`, `EventBuffer` | Removed. To write somewhere else, see [Extending](extending.md) |
| `$sender->send()`, returning the sent `StreamedResponse` | Plain PHP: `$stream->send()`, which returns nothing. Frameworks: see below |

### Events

The constructor keeps the same order: name, data, id, retry.

| 1.x | 2.0 |
|---|---|
| `new Event('tick', 'data', '1', 3)` | The same |
| `new Event()`: named `message` | `new Event()`: unnamed. The browser handles both with `onmessage` |
| `retry` was `3` by default: every event asked for a 3-second delay | `null` by default: nothing is sent. Use `EventStream::retry(3)` to keep the old delay |
| `$event->getEventName()`, `getData()`, `getId()`, `getRetry()` | `$event->name`, `->data`, `->id`, `->retry` |
| `$event->setData($data)` and the other setters | `$event = $event->withData($data)`: events are immutable |
| A line break in the name or the id corrupted the stream | Throws an `InvalidArgumentException` |

### Frameworks

In 1.x, `send()` streamed immediately and returned the response it had already sent. In 2.0, frameworks get a
response they send themselves, so middleware and listeners work:

| Framework | 2.0 |
|---|---|
| Symfony | `return StreamedResponseFactory::create($stream, $request);` |
| Laravel | `return response()->sse($stream);` |

See [Frameworks](frameworks.md).

## Behavior changes

Some fixes in 2.0 change what your stream does, even with the same code:

- **The stream keeps running.** In 1.x, write listeners ran once, and the connection closed after one second, so the
  browser reconnected every few seconds. In 2.0, the stream runs until it's stopped, the client leaves, or the
  maximum duration is reached. If you relied on the old behavior, call `$stream->stop()`, or set
  `maxDuration()`.
- **Events are sent immediately**, instead of at the end of an iteration.
- **Ticks start at a fixed interval.** In 1.x, the sleep came after the listeners, so the interval grew with the
  time they took.
- **Stop listeners always run**: also after a disconnection, or an exception.
- **Exceptions** reach your error handler after cleanup, unless you add an `onError` listener.
- **`id` and `retry` now belong to their own event.** In 1.x they were written after the end of the event, so they
  applied to the next one.
- **Multi-line data works.** In 1.x, a line break in the data broke the stream.

## New features worth adopting

While migrating, consider:

- `heartbeat()` and `maxDuration()`: see [Running in production](production.md);
- `$stream->lastEventId()` to resume after reconnections: see
  [Controlling streams](controlling-streams.md#resuming-after-a-reconnection);
- `Event::json()` for structured data;
- `StreamTester` to test your streams: see [Testing](testing.md).
