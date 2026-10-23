# Generator streams

Tick streams poll for something new at a regular interval. Some streams are different: they follow a task from
start to end, like an import, an export or a report. For those, a generator reads more naturally.

```php
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

$stream = EventStream::fromGenerator(function (Stream $stream) use ($rows) {
    $total = count($rows);

    foreach ($rows as $index => $row) {
        importRow($row);

        yield Event::json('progress', ['done' => $index + 1, 'total' => $total]);
    }

    yield new Event('done');
});

$stream->send();
```

Every event the generator yields is sent to the browser immediately. When the generator finishes, the stream ends
with `StopReason::Stopped`.

## What the generator receives

The generator is called with the running `Stream`, like listeners. Use it to read `lastEventId()`, `elapsed()` or
`iteration()`, or to `stop()` the stream:

```php
EventStream::fromGenerator(function (Stream $stream) {
    foreach (Report::sections() as $section) {
        if ($stream->elapsed() > 60) {
            yield new Event('partial', 'The report was cut short');
            $stream->stop(); // the sections yielded after this aren't sent
        }

        yield Event::json('section', $section->render());
    }
});
```

`iteration()` counts the events sent: 1 after the first one, 2 after the second…

The generator can also return any other iterable, like an array. Every item must be an `Event`: anything else
throws an `UnexpectedValueException`, handled like any [error](error-handling.md).

## When the stream ends

A generator stream ends when:

- the generator finishes: `StopReason::Stopped`;
- the generator calls `$stream->stop()`: `StopReason::Stopped`. Events yielded after `stop()` aren't sent;
- the client disconnects: `StopReason::ClientDisconnected`, checked after every event;
- the maximum duration is reached: `StopReason::MaxDurationReached`, checked after every event;
- the generator throws: `StopReason::Error`.

When the client disconnects, the generator isn't resumed, so the rest of its code doesn't run. Use a stop listener,
or a `finally` block in the generator, for cleanup.

## Listeners and settings

| Feature | Generator streams |
|---|---|
| `onStart()`, `onStop()`, `onError()` | ✅ Same as tick streams. Start listeners run before the generator is called |
| `maxDuration()` | ✅ Checked after every event |
| `retry()`, `header()`, `headers()` | ✅ Same as tick streams |
| `onTick()`, `every()` | ❌ Throw a `LogicException`: there are no ticks |
| `heartbeat()` | ❌ Throws a `LogicException`: the stream never waits between events, so there's nothing to fill |

## Long pauses inside the generator

The stream can only write while the generator yields. If your generator waits a long time between two events, for
example on a slow query, nothing is written in the meantime:

- proxies may close the connection, see [Running in production](production.md#proxies-and-load-balancers);
- a disconnection isn't noticed until the next event.

If the waiting is regular ("check every few seconds"), a [tick stream](controlling-streams.md) fits better: it
sends heartbeats while it waits. If it's a single slow step, you can yield a comment-like progress event before it,
so the browser knows the stream is alive.

## Tick stream or generator stream?

| You want to… | Use |
|---|---|
| Check for new data every N seconds, for as long as the user stays | A tick stream: `EventStream::create()->onTick()` |
| Report the progress of one task, from start to end | A generator stream: `EventStream::fromGenerator()` |
| Keep a connection alive while nothing happens | A tick stream with `heartbeat()` |
