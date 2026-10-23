# Running in production

Each open stream is an HTTP request that stays alive, served by one PHP process. That's unusual for PHP, so a few
settings matter.

## Checklist

- [ ] Release the session lock before the stream starts.
- [ ] Set a [maximum duration](controlling-streams.md#maximum-duration), e.g. 5 minutes.
- [ ] Enable [heartbeats](controlling-streams.md#heartbeats), below your proxies' idle timeout.
- [ ] Turn off compression and response buffering for the stream's route.
- [ ] Size your PHP-FPM pool for the number of open streams.
- [ ] Serve the site over HTTP/2.
- [ ] Choose a tick interval your database can handle, for every connected client.

## Sessions

PHP's native sessions are locked for the whole request. While a stream is open, every other request from the same
user waits for it: pages stop loading.

Read what you need from the session, then release it before sending the stream:

```php
session_start();
$userId = $_SESSION['user_id'] ?? null;
session_write_close();

EventStream::create()->/* … */->send();
```

In Symfony, call `$request->getSession()->save()`. Laravel's sessions don't lock by default.

## PHP settings

| Setting | Recommendation |
|---|---|
| `max_execution_time` | On Linux, it counts CPU time, not waiting, so streams rarely hit it. Still, use `maxDuration()`, or call `set_time_limit(0)` for the stream's request |
| `output_buffering` | Fine at the usual 4096: the library flushes it. Avoid extra nested `ob_start()` calls around the stream |
| `zlib.output_compression` | Must be `Off` for the stream's route: compression holds events back |
| `display_errors` | `Off`, as always in production. A notice printed before the stream would make it fail with "output was already sent" |
| `memory_limit` | A long stream accumulates whatever your listeners keep. `maxDuration()` resets it regularly |

## PHP-FPM

Every connected client keeps an FPM worker busy for the whole stream. With `pm.max_children = 50` and 50 open
streams, no worker is left for normal pages.

- Size `pm.max_children` for your expected number of simultaneous streams, plus your normal traffic. Check that the
  server has the memory for it.
- Or give streams their own pool, so they can't starve the rest of the site:

```ini
; /etc/php/8.4/fpm/pool.d/streams.conf
[streams]
listen = /run/php/streams.sock
pm = static
pm.max_children = 100
```

```nginx
location /notifications/stream {
    fastcgi_pass unix:/run/php/streams.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
}
```

## nginx

The `X-Accel-Buffering: no` header, sent by default, turns off nginx's response buffering. Also check:

```nginx
location /notifications/stream {
    # Compression holds events back
    gzip off;

    # Longer than the time between two writes: use heartbeats to keep it short
    fastcgi_read_timeout 300s;

    # …your usual fastcgi_pass configuration
}
```

When nginx is a reverse proxy in front of another server, the equivalents are `proxy_buffering off`,
`proxy_read_timeout` and, for HTTP/1.1 to the backend, `proxy_http_version 1.1` with an empty `Connection` header.

## Apache

- Turn off compression for the route, as `mod_deflate` buffers the response:

```apache
SetEnvIf Request_URI "^/notifications/stream" no-gzip
```

- With PHP-FPM through `mod_proxy_fcgi`, if events arrive in bursts, look at the proxy's buffering and flushing
  options for your Apache version. With `mod_php`, the library's flushes are enough.

## Proxies and load balancers

Proxies, load balancers and CDNs close connections that look idle. Typical defaults:

| Component | Idle timeout |
|---|---|
| nginx (`proxy_read_timeout`, `fastcgi_read_timeout`) | 60 s |
| AWS Application Load Balancer | 60 s |
| Cloudflare | 100 s |

Use `heartbeat()` with an interval well below the lowest one, like 15 or 20 seconds. Also make sure none of them
buffers or compresses `text/event-stream` responses.

When a connection is closed anyway, the browser reconnects with `Last-Event-ID`, so nothing is lost. It just costs
a new request.

## HTTP/2

Over HTTP/1.1, browsers allow only **6 connections per domain**, shared by all tabs. With a stream in every tab, the
seventh tab can't load anything. HTTP/2 multiplexes requests over one connection, which removes the limit. Enable it
in your web server, or your CDN.

## Async servers

Laravel Octane, Swoole, RoadRunner and ReactPHP aren't supported. A stream waits between ticks with `usleep()`,
which would block the server's worker, or its whole event loop, for every connected client. Serve the stream's route
with PHP-FPM, Apache with mod_php, or another server that runs one request per process.

## Databases and other connections

A stream holds its database connection for its whole life. Keep it in mind:

- with 100 open streams, that's 100 database connections. Check your database's connection limit;
- servers close idle connections after a while (MySQL's `wait_timeout`, often 8 hours, sometimes much less). A
  `maxDuration()` shorter than that avoids the problem;
- caching what every client asks for, for a second or two, reduces the load a lot when many clients poll the same
  data.

## Deploying

Open streams keep running the old code until they end. With `maxDuration()`, they end on their own within that
time, and reconnect to the new code. During a restart, every client reconnects at once: a longer
[reconnection delay](controlling-streams.md#reconnection-delay) spreads the load.

## When to use something else

One PHP process per client fits up to a few hundred simultaneous streams on an ordinary server. For thousands of
clients, or to push the same message to many clients at once, use a dedicated real-time server, like
[Mercure](https://mercure.rocks), and keep PHP for publishing the updates.
