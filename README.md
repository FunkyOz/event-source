# PHP Event Source

**Stream real-time updates from PHP to the browser with [Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html), in plain PHP, Symfony or Laravel.**

[![CI](https://github.com/FunkyOz/event-source/actions/workflows/ci.yml/badge.svg)](https://github.com/FunkyOz/event-source/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/funkyoz/event-source)](https://packagist.org/packages/funkyoz/event-source)
[![PHP version](https://img.shields.io/packagist/dependency-v/funkyoz/event-source/php)](https://packagist.org/packages/funkyoz/event-source)
[![License](https://img.shields.io/github/license/FunkyOz/event-source)](LICENSE)

Server-Sent Events let a server push messages to the browser over a plain HTTP connection. The browser side is
built in (`new EventSource(url)`), reconnects on its own and resumes where it left off. This library is the PHP side:
it keeps the connection open, formats your events correctly, and handles the whole life of the stream for you.

```php
EventStream::create()
    ->onTick(fn (Stream $stream) => $stream->send(new Event('time', date('H:i:s'))))
    ->send();
```

- **No dependencies**: works in plain PHP, and returns native responses in Symfony and Laravel
- **The whole lifecycle handled**: setup and cleanup hooks, a polling interval, heartbeats, a maximum duration,
  disconnections and errors
- **Resuming built in**: `Last-Event-ID` is read for you, and follows the events you send
- **Correct and safe output**: multi-line data, ids, reconnection delays, and no way to corrupt the stream
- **Testable**: run a stream instantly in a unit test and check what it sent

> Previously published as `dessimoney/event-source`. See [Upgrading from 1.x](#upgrading-from-1x).

📖 **[Full documentation](docs/README.md)**: guides, recipes, troubleshooting, production setup and the complete API
reference. This page is an overview.

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Why this library?](#why-this-library)
- [How it works](#how-it-works)
- [Sending events](#sending-events)
- [Controlling the stream](#controlling-the-stream)
- [Symfony, Laravel and other frameworks](#symfony-laravel-and-other-frameworks)
- [Streams from a generator](#streams-from-a-generator)
- [Errors](#errors)
- [Testing](#testing)
- [Running it in production](#running-it-in-production)
- [API reference](#api-reference)
- [Upgrading from 1.x](#upgrading-from-1x)

## Installation

```bash
composer require funkyoz/event-source
```

Requires PHP 8.2 or newer, and nothing else. The Symfony bridge works with `symfony/http-foundation` 6.4 to 8.x, and
the Laravel bridge with Laravel 12 and 13.

## Quick start

**1. The server** (`events.php`) sends the current time every second:

```php
<?php

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/vendor/autoload.php';

EventStream::create()
    ->onTick(function (Stream $stream) {
        $stream->send(new Event('time', date('H:i:s')));
    })
    ->send();
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

For more, clone this repository and run `composer example`: a resuming counter, a chat, a progress bar, a background
job, a dashboard and error handling, plus Symfony, Laravel and testing examples. See [`examples/`](examples).

## Why this library?

Symfony (`EventStreamResponse`, since 7.3) and Laravel (`response()->eventStream()`) can send Server-Sent Events too.
They format the events, and leave the rest to you. This library handles what makes a long-running stream reliable:

- **Resuming**: event ids, and `$stream->lastEventId()` after a reconnection. Laravel's events have no ids, so the
  browser can't tell it what it missed.
- **Timing**: polling at a fixed interval, heartbeats while waiting, and a maximum duration.
- **Lifecycle**: start, stop and error hooks, with the reason the stream ended.
- **Tests**: run a stream in a unit test with a fake clock, so waiting is instant, and simulate disconnections.
- **Portability**: the same code in plain PHP, Symfony and Laravel.

For a stream with a natural end, like the answer of an AI model, Laravel's `eventStream()` is enough. For thousands
of simultaneous clients, use a dedicated server such as [Mercure](https://mercure.rocks). See
[Compared with Symfony and Laravel](docs/comparison.md) for the details, side by side.

## How it works

An `EventStream` is the definition of a stream. When it's sent, it runs your listeners in this order:

```
onStart                  once, when the stream opens
   │
   ▼
┌▶ onTick                every listener, on every tick
│   │
│   ├─ stop() called? ──────────────────yes──┐
│   ├─ client disconnected? ────────────yes──┤
│   ▼                                        │
│  wait for the next tick (1 s by default),  │
│  sending heartbeats if enabled             │
│   │                                        │
└───┴─ maximum duration reached? ───────yes──┤
                                             ▼
onStop                   once, at the end: also after a disconnection or an error
```

Each listener receives a `Stream`, which sends events and tells you about the running stream:

```php
EventStream::create()
    ->onStart(function (Stream $stream) {
        // Once, before the first tick: send the initial state, open resources…
    })
    ->onTick(function (Stream $stream) {
        // On every tick: check for something new (a database row, a queue message, a job's progress) and send it
    })
    ->onStop(function (Stream $stream) {
        // Once, at the end: release resources, log why it ended with $stream->stopReason()
    })
    ->send();
```

You can add as many listeners of each kind as you need. They run in the order you add them.

A definition holds no state from a run, so you can build it once, for example in a service, and send it to every
client.

## Sending events

An event has four fields, all optional:

```php
new Event(
    name: 'order-update',               // the type the browser listens for; null for an unnamed event
    data: '{"id":42,"status":"paid"}',  // the payload, always a string
    id: '42',                           // lets the browser resume from this event after a reconnection
    retry: 5,                           // seconds the browser waits before reconnecting
);
```

`$stream->send($event)` sends it to the browser immediately:

```
event: order-update
id: 42
retry: 5000
data: {"id":42,"status":"paid"}

```

**JSON.** Most payloads are structured, so `Event::json()` encodes them for you:

```php
$stream->send(Event::json('order-update', $order, id: (string) $order->id));
```

```js
source.addEventListener('order-update', (event) => {
    const order = JSON.parse(event.data);
});
```

**Multi-line data** is supported: each line is sent as its own `data:` field, and the browser joins them back.

**Named and unnamed events.** The browser handles unnamed events with `source.onmessage`, and named ones with
`source.addEventListener('name', …)`.

**Comments** are ignored by the browser, but can be useful for debugging: `$stream->comment('cache miss')`.

**Events are immutable.** Use `withName()`, `withData()`, `withId()` and `withRetry()` to get a modified copy.

**Safe by design.** A line break in a name or an id would inject extra fields into the stream, so `Event` rejects
it with an `InvalidArgumentException`. The same goes for line breaks in header values.

## Controlling the stream

### Frequency

Ticks start every second by default. Change it with `every()`, which accepts fractions of a second:

```php
EventStream::create()->every(0.5);
```

The interval is measured from the start of one tick to the start of the next, so a slow tick doesn't shift the
schedule.

### Ending the stream

A stream ends when:

- **a listener calls `$stream->stop()`**. The current listener finishes, then the stream ends;
- **the client disconnects**, for example because the user closed the tab;
- **the maximum duration is reached**, if you set one;
- **there are no tick listeners**: the stream ends right after `onStart`.

```php
->onTick(function (Stream $stream) use ($job) {
    $stream->send(new Event('progress', (string) $job->progress()));

    if ($job->isFinished()) {
        $stream->stop();
    }
})
```

`$stream->stopReason()` tells your stop listeners why the stream ended: `StopReason::Stopped`,
`ClientDisconnected`, `MaxDurationReached` or `Error`.

> [!IMPORTANT]
> When the server ends a stream, the browser reconnects after a few seconds. That's a feature of `EventSource`. To
> finish for good, send a last event and close the connection from the browser:
>
> ```php
> ->onStop(function (Stream $stream) {
>     if ($stream->stopReason() === StopReason::Stopped) {
>         $stream->send(new Event('done'));
>     }
> })
> ```
>
> ```js
> source.addEventListener('done', () => source.close());
> ```
>
> Or, when the browser reconnects and there's nothing left to send, answer with `EventStream::sendNoContent()`
> instead: browsers don't reconnect after a `204 No Content` response.

### Resuming after a reconnection

When the connection drops, the browser reconnects and sends the `id` of the last event it received in the
`Last-Event-ID` header. `$stream->lastEventId()` returns it, and then follows the ids of the events you send, so
"send what's new since last time" needs no extra variable:

```php
->onTick(function (Stream $stream) {
    foreach (Notification::newerThan($stream->lastEventId()) as $notification) {
        $stream->send(Event::json('notification', $notification, id: (string) $notification->id));
    }
})
```

`retry()` tells the browser how long to wait before reconnecting. It's sent once, when the stream starts:

```php
EventStream::create()->retry(5);
```

### Heartbeats

Proxies and load balancers often close connections that look idle, typically after 30 to 60 seconds. With
`heartbeat()`, the stream sends a comment when nothing was written for that long, even between ticks:

```php
EventStream::create()->every(60)->heartbeat(15);
```

### Maximum duration

A stream that runs forever eventually hits `max_execution_time`, or leaks memory. Ending it regularly is the healthy
pattern: the browser reconnects, and with `Last-Event-ID` it doesn't miss anything.

```php
EventStream::create()->maxDuration(300);
```

### Headers

The response is sent with `Content-Type: text/event-stream`, `Cache-Control: no-cache` and
`X-Accel-Buffering: no`. You can add or replace headers, for example to allow another origin:

```php
EventStream::create()
    ->header('Access-Control-Allow-Origin', 'https://app.example.com')
    ->headers(['Access-Control-Allow-Credentials' => 'true']);
```

## Symfony, Laravel and other frameworks

In plain PHP, `$stream->send()` sends the headers and the events. In a framework, return a response instead: the
framework sends it, and middleware, listeners and the profiler keep working.

**Symfony**

```php
use FunkyOz\EventSource\Bridge\Symfony\StreamedResponseFactory;

#[Route('/notifications')]
public function notifications(Request $request): StreamedResponse
{
    $stream = EventStream::create()->onTick(/* … */);

    return StreamedResponseFactory::create($stream, $request); // the request provides Last-Event-ID
}
```

To stop the browser from reconnecting, return `StreamedResponseFactory::noContent()`.

**Laravel**

The package registers itself, and adds a `sse` response macro:

```php
Route::get('/notifications', function () {
    $stream = EventStream::create()->onTick(/* … */);

    return response()->sse($stream); // reads Last-Event-ID from the current request
});
```

To stop the browser from reconnecting, return `response()->noContent()`.

**Other frameworks**

Any framework that accepts Symfony responses, like Laravel or Drupal, can use `StreamedResponseFactory`.
For others, run the stream from the framework's own streamed response, with the `PhpOutput` the library uses:

```php
$stream->run(new PhpOutput(), $lastEventIdFromTheRequest);
```

Send the headers from `$stream->getHeaders()` with the response.

## Streams from a generator

For a stream with a natural end, like the progress of a job, a generator reads well. Every event it yields is sent
immediately:

```php
$stream = EventStream::fromGenerator(function (Stream $stream) use ($job) {
    foreach ($job->run() as $percent) {
        yield Event::json('progress', ['percent' => $percent]);
    }
    yield new Event('done');
});
```

`onStart`, `onStop`, `onError`, `maxDuration()` and `retry()` work the same way. `onTick()`, `every()` and
`heartbeat()` don't apply, since the stream never waits between events: calling them throws a `LogicException`.

## Errors

When a listener or the generator throws:

1. your `onError` listeners are called. They can still send an event to the browser;
2. the `onStop` listeners run, with `StopReason::Error`;
3. the output is closed;
4. without `onError` listeners, the exception is then rethrown, so errors are never silent.

```php
->onError(function (\Throwable $e, Stream $stream) use ($logger) {
    $logger->error('Stream failed', ['exception' => $e]);
    $stream->send(new Event('error', 'Temporary problem, reconnecting…'));
})
```

An exception inside `onStop` or `onError` itself isn't caught: it's a bug in the cleanup code.

## Testing

`StreamTester` runs a stream instantly: it doesn't wait between ticks, and it returns what the browser would have
received.

```php
use FunkyOz\EventSource\Testing\StreamTester;

$result = StreamTester::for($stream)
    ->withLastEventId('41')  // simulate a reconnection
    ->disconnectAfter(3)     // simulate the user leaving after 3 ticks
    ->run();

self::assertCount(2, $result->events('notification'));
self::assertSame('42', $result->events()[0]->id);
self::assertSame(StopReason::ClientDisconnected, $result->stopReason());
self::assertSame([1.0, 1.0], $result->sleeps());
```

A stream that never ends makes `run()` throw after 10,000 iterations, so a test can't hang. Change the limit with
`iterationLimit()`.

For finer control, run a stream yourself with `Testing\MemoryOutput`, which keeps the output and can simulate a
disconnection, and `Testing\FakeClock`, which moves time forward instead of waiting:

```php
$clock = new FakeClock();
$output = new MemoryOutput();

$stream->run($output, lastEventId: null, clock: $clock);

$events = $output->parse()->events;
```

## Running it in production

Each open stream is a PHP request that stays alive, so a few settings matter:

| Topic | What to do |
|---|---|
| **Sessions** | PHP locks the session for the whole request, which blocks the user's other requests. Call `session_write_close()` before sending the stream. |
| **Time limit** | Use `maxDuration()` to end streams before `max_execution_time`, or call `set_time_limit(0)`. |
| **Proxies and load balancers** | Use `heartbeat()` with an interval below their idle timeout. |
| **Workers** | Every connected client keeps a PHP-FPM worker busy. Size `pm.max_children` for your expected number of open streams. |
| **nginx** | Response buffering is disabled by the `X-Accel-Buffering: no` header. Also make sure `gzip` is off for the endpoint. |
| **Apache** | Disable compression for the endpoint (`mod_deflate` buffers the response), e.g. `SetEnv no-gzip 1`. |
| **HTTP/1.1** | Browsers allow only 6 connections per domain, shared by all tabs. Serve the endpoint over HTTP/2 to avoid the limit. |
| **Async servers** | Laravel Octane, Swoole, RoadRunner and ReactPHP aren't supported: the stream waits between ticks, which would block the server. |

Each client is served by one long-running PHP request, which checks for new data on every tick. This fits
notifications, progress bars, dashboards and live feeds. For thousands of simultaneous clients, consider a dedicated
real-time server such as [Mercure](https://mercure.rocks).

## API reference

### `EventStream`

| Method | Description |
|---|---|
| `create()` | A stream that calls the tick listeners at a regular interval |
| `fromGenerator(callable $generator)` | A stream that sends every event the generator yields |
| `every(float $seconds)` | Seconds between the start of two ticks, 1 by default |
| `heartbeat(?float $seconds)` | Send a comment when nothing was written for this long. Off by default |
| `maxDuration(?float $seconds)` | End the stream after this long. No limit by default |
| `retry(?float $seconds)` | Reconnection delay sent to the browser when the stream starts |
| `header(string $name, string $value)` / `headers(array $headers)` | Add or replace response headers |
| `onStart(callable $listener)` | `fn (Stream $stream)`, called once at the start |
| `onTick(callable $listener)` | `fn (Stream $stream)`, called on every tick |
| `onStop(callable $listener)` | `fn (Stream $stream)`, called once at the end |
| `onError(callable $listener)` | `fn (\Throwable $e, Stream $stream)`, called when a listener or the generator throws |
| `send()` | Send the stream with plain PHP: headers, then events |
| `sendNoContent()` | Answer with `204 No Content`, so the browser stops reconnecting |
| `run(Output $output, ?string $lastEventId = null, ?Clock $clock = null): StopReason` | Run against any output, without sending headers. For adapters and tests |
| `getHeaders(): array` | The response headers, including the defaults |

### `Stream`

| Method | Description |
|---|---|
| `send(Event $event)` | Send an event immediately |
| `comment(string $text = '')` | Send a comment, which the browser ignores |
| `stop()` / `isStopped()` | End the stream after the current listener |
| `lastEventId(): ?string` | `Last-Event-ID` from the browser, then the id of the last event sent |
| `iteration(): int` | 0 in `onStart`, then 1, 2, 3… for each tick or generated event |
| `elapsed(): float` | Seconds since the stream started |
| `stopReason(): ?StopReason` | Why the stream ended, in `onStop` |

### `Event`

| Field | Type | Default | Description |
|---|---|---|---|
| `name` | `?string` | `null` | Event type; `null` for an unnamed event |
| `data` | `string` | `''` | Payload; multi-line strings are supported |
| `id` | `?string` | `null` | Sent back by the browser in `Last-Event-ID` after a reconnection |
| `retry` | `?float` | `null` | Seconds the browser waits before reconnecting |

The fields are public and read-only. `Event::json($name, $data, $id, $retry)` encodes the data as JSON.

### Bridges

| Class | Description |
|---|---|
| `Bridge\Symfony\StreamedResponseFactory::create(EventStream $stream, ?Request $request = null)` | A Symfony `StreamedResponse` |
| `Bridge\Symfony\StreamedResponseFactory::noContent()` | A `204 No Content` response |
| `Bridge\Laravel\EventSourceServiceProvider` | Adds `response()->sse($stream)`. Registered automatically |

### Extension points

| Type | Description |
|---|---|
| `Output` | Where a stream is written: `open()`, `write()`, `flush()`, `isConnectionAborted()`, `close()`. Implemented by `PhpOutput` (the PHP output) and `StreamOutput` (any stream resource) |
| `Clock` | `now()` and `sleep()`. Implemented by `SystemClock` |
| `EventFormatter` | Turns an event or a comment into `text/event-stream` text |

## Upgrading from 1.x

Version 2.0 is a new API, published under a new name: `funkyoz/event-source` replaces `dessimoney/event-source`.
The namespaces are different, so both can be installed at the same time while you migrate.

```bash
composer require funkyoz/event-source
composer remove dessimoney/event-source   # when you're done
```

| 1.x | 2.0 |
|---|---|
| `EventSource\` namespace | `FunkyOz\EventSource\` |
| `new EventSender()` | `EventStream::create()` |
| `addStartListener(fn (EventBufferInterface $buffer) => …)` | `onStart(fn (Stream $stream) => …)` |
| `addWriteListener(…)`, return `true` to stop | `onTick(…)`, call `$stream->stop()` |
| `addStopListener(…)` | `onStop(…)`, with `$stream->stopReason()` |
| `$buffer->write($event)` | `$stream->send($event)` |
| `new Event('name', 'data', 'id', 3)` | The same. The default name is now `null` instead of `'message'` |
| `$event->getData()` / `setData()` | `$event->data` / `withData()` |
| `setSleep(1)` | `every(1)` |
| `addHeader()` / `addHeaders()` | `header()` / `headers()` |
| `$sender->send()` | Plain PHP: `$stream->send()`. Symfony: `StreamedResponseFactory::create()`. Laravel: `response()->sse()` |
| `setBuffer()`, `EventBufferInterface`, `EventBuffer` | Removed. See [Extension points](#extension-points) |
| Requires `symfony/http-foundation` | No dependencies |

See the [complete migration guide](docs/upgrading-from-1.x.md), and the [changelog](CHANGELOG.md) for the full list
of changes.

## Documentation

| | |
|---|---|
| [Getting started](docs/getting-started.md) | A first stream, step by step |
| [How it works](docs/how-it-works.md) | The lifecycle of a stream |
| [Events](docs/events.md), [Controlling streams](docs/controlling-streams.md), [Generator streams](docs/generator-streams.md), [Error handling](docs/error-handling.md) | Everything a stream can do |
| [Frameworks](docs/frameworks.md) | Symfony, Laravel and others |
| [Testing](docs/testing.md) | Test streams without a server or waiting |
| [Running in production](docs/production.md) | PHP-FPM, nginx, Apache, sessions, proxies, scaling |
| [Compared with Symfony and Laravel](docs/comparison.md) | Their built-in SSE support, and when to use which |
| [Recipes](docs/recipes.md) | Notifications, job progress, dashboards… |
| [Troubleshooting](docs/troubleshooting.md) | Symptoms, causes and fixes |
| [Extending](docs/extending.md) | Custom outputs, and adapters for other frameworks |
| [API reference](docs/api-reference.md) | Every public class and method |
| [Upgrading from 1.x](docs/upgrading-from-1.x.md) | The complete migration guide |

## Contributing

Bug reports, ideas and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) to get started.

## License

MIT © Lorenzo Dessimoni
