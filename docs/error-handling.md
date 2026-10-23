# Error handling

## What happens when something throws

When a start listener, a tick listener or the generator throws an exception:

1. the stream stops: no more ticks or generated events;
2. the `onError` listeners are called with the exception and the `Stream`. They can still send an event;
3. the `onStop` listeners run, and `$stream->stopReason()` returns `StopReason::Error`;
4. the output is closed;
5. **if there are no `onError` listeners**, the exception is rethrown.

So by default, errors aren't silent: the exception reaches your framework's error handler, or PHP's. And cleanup
always happens first.

## Handling errors

Add an `onError` listener to log the error and tell the browser:

```php
EventStream::create()
    ->onTick(function (Stream $stream) {
        $stream->send(Event::json('stats', Stats::current()));
    })
    ->onError(function (\Throwable $e, Stream $stream) use ($logger) {
        $logger->error('The stats stream failed', ['exception' => $e]);
        $stream->send(new Event('error', 'Temporary problem, reconnecting…'));
    });
```

```js
source.addEventListener('error', (event) => {
    // Our own "error" event has data. EventSource's built-in connection error doesn't
    if (event.data) {
        showWarning(event.data);
    }
});
```

With an `onError` listener, the exception is **not** rethrown: you've handled it. The stream ends normally, and
the browser reconnects after the retry delay, which gives transient problems, like a database restart, a chance to
pass.

> [!TIP]
> `EventSource` also fires an `error` event of its own when the connection fails. If that's confusing in your
> page, give your event another name, like `server-error`.

## Errors in cleanup code

An exception thrown inside `onStop` or `onError` isn't caught: it's a bug in the cleanup code, and it should be
visible. The output is still closed.

## Errors before the stream starts

Some problems are detected before anything is sent, and throw right away:

| Problem | Exception |
|---|---|
| Output was already sent, so headers can't be (`send()`, `sendNoContent()`) | `LogicException` |
| An invalid event, header or setting | `InvalidArgumentException` |
| `onTick()`, `every()` or `heartbeat()` on a generator stream | `LogicException` |
| `Event::json()` with data that can't be encoded | `JsonException` |

The last two can also happen inside a listener: they're then handled like any other error.

## What the browser sees

When a stream ends, for any reason, the browser's `EventSource` reconnects after the retry delay, unless:

- the page called `source.close()`;
- the server answered with `204 No Content`;
- the server answered with an error status (like `500`) or another `Content-Type`. The browser then gives up
  **without** reconnecting.

That last point matters: if an exception escapes before the stream starts, the browser gets an error page and stops
trying. Use `source.onerror` to detect that, and `source.readyState === EventSource.CLOSED` to tell it apart from
a reconnection:

```js
source.onerror = () => {
    if (source.readyState === EventSource.CLOSED) {
        showWarning('Live updates are unavailable. Reload the page to try again.');
    }
};
```
