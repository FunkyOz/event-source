# Getting started

## What Server-Sent Events are

Server-Sent Events (SSE) let a server push messages to the browser over a normal HTTP response that stays open.
The browser side is built into every modern browser as [`EventSource`](https://developer.mozilla.org/docs/Web/API/EventSource):

- it opens the connection and receives events as they arrive;
- it reconnects automatically when the connection drops;
- when it reconnects, it tells the server the id of the last event it received, so nothing is missed.

SSE goes one way, from the server to the browser. It fits notifications, progress bars, dashboards and live feeds.
For messages from the browser to the server, use normal HTTP requests next to the stream.

## Installation

```bash
composer require funkyoz/event-source
```

The library needs PHP 8.2 or newer, and nothing else. To return Symfony or Laravel responses, see
[Frameworks](frameworks.md).

## A first stream

### The server

Create `events.php`. It sends the current time every second:

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

- `EventStream::create()` starts the definition of a stream.
- `onTick()` adds a listener, called every second by default.
- `$stream->send()` sends an event to the browser immediately.
- `send()` sends the response: the headers, then the events, until the browser disconnects.

### The page

Create `index.html` next to it:

```html
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Server time</title>
</head>
<body>
<p>Server time: <span id="time">…</span></p>
<script>
    const source = new EventSource('events.php');

    source.addEventListener('time', (event) => {
        document.getElementById('time').textContent = event.data;
    });
</script>
</body>
</html>
```

`addEventListener('time', …)` receives the events named `time`. The text you sent is in `event.data`.

### Run it

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000
```

Open http://localhost:8000: the time updates every second. Close the tab, and the stream ends on the server.

`PHP_CLI_SERVER_WORKERS=4` gives the built-in server several workers. Each open stream keeps a worker busy, so
with only one, the page itself couldn't load while the stream is open.

## More examples

This repository contains runnable examples: a counter that resumes after reconnections, a chat, a progress bar, a
background job, a dashboard and error handling. There are also examples to copy into Symfony and Laravel, and a test
written with `StreamTester`.

```bash
git clone https://github.com/FunkyOz/event-source.git
cd event-source
composer install
composer example
```

Then open http://localhost:8000. See [`examples/`](../examples) for the list, and the code.

## Next steps

- [How it works](how-it-works.md): the lifecycle of a stream.
- [Events](events.md): what you can send.
- [Frameworks](frameworks.md): using it in Symfony or Laravel.
