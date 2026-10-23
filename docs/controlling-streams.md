# Controlling streams

## Frequency

Tick listeners run every second by default. Change it with `every()`, in seconds:

```php
EventStream::create()->every(5);    // every 5 seconds
EventStream::create()->every(0.25); // 4 times per second
```

Ticks start at a fixed interval, whatever your listeners take: with `every(5)` and a tick that takes 2 seconds,
the stream waits 3 seconds. If a tick takes longer than the interval, the next one starts right away.

`every(0)` runs the ticks back to back. That's rarely what you want: it keeps a CPU busy. `every()` rejects
negative values.

### Choosing the interval

Every tick usually means a query or a cache lookup, for every connected client. With 100 clients and `every(1)`,
that's 100 queries per second. Pick the slowest interval your users won't notice:

| Use case | Typical interval |
|---|---|
| Chat, live cursor positions | 0.5 to 1 s |
| Notifications, order status | 2 to 5 s |
| Dashboards, statistics | 5 to 30 s |

## Stopping

A listener ends the stream with `$stream->stop()`. The current listener finishes, then the stop listeners run:

```php
EventStream::create()
    ->onTick(function (Stream $stream) use ($job) {
        $stream->send(new Event('progress', (string) $job->progress()));

        if ($job->isFinished()) {
            $stream->stop();
        }
    });
```

`$stream->isStopped()` tells whether `stop()` was called, for example in a stop listener.

A stream also ends when the client disconnects, when the [maximum duration](#maximum-duration) is reached, and when a
listener throws (see [Error handling](error-handling.md)).

### Stopping for good

When a stream ends, **the browser reconnects** after a few seconds. That's how `EventSource` works, and it's what you
want after a disconnection or a maximum duration. But when there's nothing more to send, it means a new request that
opens a new stream, forever.

There are two ways to tell the browser to stop:

**1. Send a last event, and close the connection from the page:**

```php
->onStop(function (Stream $stream) {
    if ($stream->stopReason() === StopReason::Stopped) {
        $stream->send(new Event('done'));
    }
})
```

```js
source.addEventListener('done', () => source.close());
```

**2. Answer the reconnection with `204 No Content`.** Browsers don't reconnect after a 204:

```php
if ($job->isFinished()) {
    EventStream::sendNoContent();
    exit;
}

EventStream::create()->/* … */->send();
```

In Symfony, return `StreamedResponseFactory::noContent()`; in Laravel, `response()->noContent()`. See
[Frameworks](frameworks.md).

The first way is faster, as the page closes the connection right away. The second also works when the page doesn't
know about your final event. Using both is the most robust.

## Resuming after a reconnection

When the connection drops (network change, server restart, maximum duration), the browser reconnects and sends the
id of the last event it received in the `Last-Event-ID` header.

`$stream->lastEventId()` returns that header at first. Then it follows the ids of the events you send. So the
common "send what's new since last time" pattern needs no other variable:

```php
EventStream::create()
    ->onTick(function (Stream $stream) {
        $after = (int) ($stream->lastEventId() ?? 0);

        foreach (Notification::after($after) as $notification) {
            $stream->send(Event::json('notification', $notification, id: (string) $notification->id));
        }
    });
```

On the first connection, `lastEventId()` is `null`. You decide what that means: everything, nothing, or only the
recent items. For example, to start from the newest item instead of sending the whole history:

```php
->onStart(function (Stream $stream) {
    if (null === $stream->lastEventId()) {
        $stream->send(new Event('ready', id: (string) Notification::latestId()));
    }
})
```

The `Last-Event-ID` header is read by `send()` in plain PHP, and by the [framework bridges](frameworks.md).

> [!WARNING]
> `Last-Event-ID` comes from the client, so treat it as user input: cast it, validate it, and never use it to
> decide what the user is allowed to see.

### Reconnection delay

Browsers wait about 3 seconds before reconnecting. `retry()` changes that, for the whole stream. It's sent once,
when the stream starts:

```php
EventStream::create()->retry(10);
```

A longer delay protects the server after a restart, when all the clients reconnect at the same time. An event's own
`retry` field overrides it from then on, see [Events](events.md#retry).

## Heartbeats

Proxies, load balancers and some browsers close connections that look idle, often after 30 to 60 seconds. If your
stream can go quiet for that long, enable heartbeats:

```php
EventStream::create()
    ->every(60)
    ->heartbeat(15);
```

When nothing was written for 15 seconds, the stream sends a comment (`:` and a line break), which the browser
ignores. It's sent on time even between two ticks: with `every(60)`, the stream wakes up to send it.

Heartbeats also help detect disconnections, since PHP only notices that the client left when it writes something.
See [How it works](how-it-works.md#how-disconnections-are-detected).

Heartbeats only exist in tick streams. A generator stream never waits between events, so `heartbeat()` throws a
`LogicException` there.

## Maximum duration

A stream that runs for hours ties up a PHP worker, can hit `max_execution_time`, and can slowly leak memory.
`maxDuration()` ends it after a given number of seconds:

```php
EventStream::create()->maxDuration(300); // 5 minutes
```

The browser then reconnects, and with [`Last-Event-ID`](#resuming-after-a-reconnection) it doesn't miss anything.
The stream checks the duration before every tick, and doesn't sleep past it.

Ending streams regularly is also how deployments stay smooth: old streams end on their own, and reconnections reach
the new code.

## Headers

Every stream is sent with these headers:

| Header | Value | Why |
|---|---|---|
| `Content-Type` | `text/event-stream` | Required by `EventSource` |
| `Cache-Control` | `no-cache` | A stream must never be cached |
| `X-Accel-Buffering` | `no` | Turns off response buffering in nginx |

Add or replace headers with `header()` and `headers()`. Names are case-insensitive, so `header('cache-control', …)`
replaces the default:

```php
EventStream::create()
    ->header('Access-Control-Allow-Origin', 'https://app.example.com')
    ->headers([
        'Access-Control-Allow-Credentials' => 'true',
        'X-Robots-Tag' => 'noindex',
    ]);
```

Header names and values can't contain line breaks, which would allow injecting other headers. Invalid ones throw an
`InvalidArgumentException`.

`getHeaders()` returns all the headers, defaults included. Bridges and custom adapters use it, see
[Extending](extending.md).

## Settings summary

| Method | Default | Tick streams | Generator streams |
|---|---|---|---|
| `every(float $seconds)` | 1 | ✅ | ❌ throws |
| `heartbeat(?float $seconds)` | off | ✅ | ❌ throws |
| `maxDuration(?float $seconds)` | no limit | ✅ | ✅ |
| `retry(?float $seconds)` | not sent | ✅ | ✅ |
| `header()`, `headers()` | see above | ✅ | ✅ |

Pass `null` to `heartbeat()`, `maxDuration()` or `retry()` to turn them off again.
