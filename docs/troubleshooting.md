# Troubleshooting

## Events arrive all at once, or only when the stream ends

Something between PHP and the browser buffers the response. In order of likelihood:

1. **Compression**: `gzip` in nginx, `mod_deflate` in Apache, or `zlib.output_compression` in PHP. Turn it off for
   the stream's route.
2. **Proxy buffering**: nginx as a reverse proxy needs `proxy_buffering off`. Check load balancers and CDNs too.
3. **Nested output buffers**: an `ob_start()` in your code or framework, around the stream. The library flushes the
   current output buffer, not the ones below it.

See [Running in production](production.md).

To check PHP itself, bypass everything else:

```bash
php -S localhost:8000 your-stream.php
curl -N http://localhost:8000
```

If `curl -N` shows events one by one, PHP is fine and the buffering is in your web server or proxy.

## The page never loads while the stream is open

With PHP's built-in server, you have one worker, and the stream keeps it busy. Start it with several:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000
```

In production, see [PHP-FPM](production.md#php-fpm): your pool may be full of open streams.

## Other pages of the same user hang while a stream is open

The session is locked by the stream's request. Release it before the stream starts, see
[Sessions](production.md#sessions).

## The browser reconnects every few seconds

The stream ends right after it starts, and the browser reconnects. Log why it ends:

```php
->onStop(fn (Stream $stream) => error_log('Stream ended: ' . $stream->stopReason()?->name))
```

| Reason | Usual cause |
|---|---|
| `Stopped` | No tick listener was added, or a listener calls `stop()` too early |
| `Error` | A listener throws: see your `onError` listener, or the error log |
| `MaxDurationReached` | `maxDuration()` is too short |
| `ClientDisconnected` | A proxy closes the connection: see [heartbeats](controlling-streams.md#heartbeats) |

If the stream is meant to end, tell the browser to stop reconnecting, see
[Stopping for good](controlling-streams.md#stopping-for-good).

## The browser doesn't reconnect at all

`EventSource` gives up for good when the response has an error status (like `500` or `401`), or a `Content-Type`
other than `text/event-stream`. That's usually an exception before the stream starts, or a login redirect. Open the
stream's URL directly in the browser to see the response.

## "Cannot start the stream: output was already sent"

Something was printed before `send()`: whitespace or a byte-order mark before `<?php`, an `echo`, or a PHP notice
with `display_errors` on. The message tells the file and line where the output started.

## The connection closes after about a minute

A proxy or load balancer closes it as idle. Enable [heartbeats](controlling-streams.md#heartbeats) with an interval
below its timeout. See [Proxies and load balancers](production.md#proxies-and-load-balancers).

## Streams keep running after users leave

PHP only notices a disconnection when it writes something. A stream with nothing to send never notices. Enable
[heartbeats](controlling-streams.md#heartbeats), and set a [maximum duration](controlling-streams.md#maximum-duration).

## Only 6 tabs work

Over HTTP/1.1, browsers allow 6 connections per domain, shared by all tabs. Serve the site over
[HTTP/2](production.md#http2).

## The JavaScript listener is never called

- **The names don't match**: `addEventListener('Notification', …)` doesn't receive `notification` events. Names are
  case-sensitive.
- **The event has no name**: unnamed events go to `source.onmessage`, not to `addEventListener('your-name', …)`.
- **The event was sent before the listener was added**: add listeners right after `new EventSource()`.

## Cross-origin streams

`EventSource` follows CORS. For a stream on another domain, allow the page's origin:

```php
EventStream::create()->header('Access-Control-Allow-Origin', 'https://app.example.com');
```

To send cookies, the page must ask for them, and the stream must allow credentials with an explicit origin, not `*`:

```js
const source = new EventSource('https://api.example.com/stream', { withCredentials: true });
```

```php
EventStream::create()->headers([
    'Access-Control-Allow-Origin' => 'https://app.example.com',
    'Access-Control-Allow-Credentials' => 'true',
]);
```

## Exceptions from the library

| Message | Cause |
|---|---|
| `… cannot be used on a stream created with fromGenerator()` | `onTick()`, `every()` or `heartbeat()` on a [generator stream](generator-streams.md) |
| `The stream has ended, nothing can be sent anymore.` | A `Stream` was kept and used after its run |
| `The event name cannot contain line breaks.` / `The event id cannot contain…` | See [Validation](events.md#validation) |
| `The stream generator must return an iterable…` / `…must yield … Event instances…` | The generator of a [generator stream](generator-streams.md) returns or yields something else |
| `Cannot register response()->sse()…` | Another package defines the same Laravel macro, see [Name conflicts](frameworks.md#name-conflicts) |
| `The stream did not end after … iterations.` | In a test: use `disconnectAfter()`, or stop the stream, see [Testing](testing.md) |
