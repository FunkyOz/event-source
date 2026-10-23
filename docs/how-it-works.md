# How it works

## Three types

You work with three types:

| Type | What it is |
|---|---|
| `EventStream` | The **definition** of a stream: its listeners, timing and headers |
| `Stream` | A **running** stream, given to every listener. It sends events and tells you about the run |
| `Event` | One message sent to the browser |

Everything else is for [frameworks](frameworks.md), [tests](testing.md) and [extensions](extending.md).

## Definition and run

An `EventStream` holds no state from a run. Each time it's sent, it gets a new `Stream`. So you can build a
definition once, for example in a service, and send it to every client:

```php
final class ClockStream
{
    public function create(): EventStream
    {
        return EventStream::create()
            ->onTick(fn (Stream $stream) => $stream->send(new Event('time', date('H:i:s'))));
    }
}
```

The definition is mutable: methods like `onTick()` and `every()` change it and return it, so calls can be chained.

## The lifecycle

There are two kinds of streams:

- **tick streams**, created with `EventStream::create()`, call your tick listeners at a regular interval. They fit
  "check for something new every N seconds";
- **generator streams**, created with `EventStream::fromGenerator()`, send what a generator yields. They fit
  streams with a natural end, like the progress of a job. See [Generator streams](generator-streams.md).

A tick stream runs like this:

```
retry sent, if set
     │
onStart listeners          once
     │
     ▼
┌▶ stop requested, or maximum duration reached?  ──yes──┐
│    │ no                                               │
│    ▼                                                  │
│  onTick listeners         every listener, in order    │
│    │                                                  │
│    ├─ stop() called?  ─────────────────────────yes────┤
│    ├─ client disconnected?  ───────────────────yes────┤
│    ▼                                                  │
│  wait until the next tick, sending heartbeats         │
│    │                                                  │
└────┴─ maximum duration reached while waiting?  ─yes───┤
                                                        ▼
onStop listeners           once, with the reason the stream ended
```

Some details:

- **Ticks start at a fixed interval**: with `every(5)`, ticks start at 0 s, 5 s, 10 s… If a tick takes 2 seconds,
  the stream waits 3. If it takes longer than the interval, the next tick starts right away.
- **`stop()` ends the stream after the current listener.** The tick listeners after it don't run.
- **A tick stream without tick listeners** ends right after the start listeners.
- **Stop listeners always run**: after `stop()`, a disconnection, the maximum duration, or an error.
- **Iterations**: `$stream->iteration()` is 0 in start listeners, then 1, 2, 3… for each tick.

## Listeners

Each kind of listener can be added many times. They run in the order they were added.

| Method | Called | Receives |
|---|---|---|
| `onStart()` | Once, before the first tick | `Stream $stream` |
| `onTick()` | On every tick | `Stream $stream` |
| `onStop()` | Once, at the end | `Stream $stream` |
| `onError()` | When a start or tick listener, or the generator, throws | `\Throwable $e, Stream $stream` |

Listeners can be any callable: closures, invokable objects, `[$object, 'method']`. Their return value is ignored.

## The `Stream` object

| Method | Description |
|---|---|
| `send(Event $event)` | Send an event to the browser immediately |
| `comment(string $text = '')` | Send a comment, which the browser ignores |
| `stop()` | End the stream after the current listener |
| `isStopped()` | Whether `stop()` was called |
| `lastEventId()` | The `Last-Event-ID` the browser sent, then the id of the last event sent |
| `iteration()` | 0 in start listeners, then 1, 2, 3… |
| `elapsed()` | Seconds since the stream started |
| `stopReason()` | Why the stream ended, in stop listeners |

Don't keep a `Stream` after the run: once the stream has ended, `send()` and `comment()` throw a
`LogicException`.

## Why the stream ended

`$stream->stopReason()` returns a `StopReason` in stop listeners:

| Case | When |
|---|---|
| `StopReason::Stopped` | A listener called `stop()`, the generator finished, or there were no tick listeners |
| `StopReason::ClientDisconnected` | The browser closed the connection |
| `StopReason::MaxDurationReached` | The stream reached its `maxDuration()` |
| `StopReason::Error` | A listener or the generator threw, see [Error handling](error-handling.md) |

`send()` doesn't return it, but `run()` does. See [Extending](extending.md#running-a-stream-yourself).

## How disconnections are detected

PHP only notices that the browser left when it tries to write to it. The library checks after every tick, after
every heartbeat, and after every event of a generator stream.

So a stream that has nothing to send doesn't notice that the user left: it keeps a PHP worker busy until it sends
something, or reaches its maximum duration. [Heartbeats](controlling-streams.md#heartbeats) fix that, since they
write something regularly.
