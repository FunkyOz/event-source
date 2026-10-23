# Extending

The library runs a stream against two small interfaces: an `Output`, where events are written, and a `Clock`, which
tells the time and waits. The plain PHP mode, the bridges and the testing helpers are all built on them. You can be
too.

## Running a stream yourself

`EventStream::run()` runs a stream against any output. It writes the events only: sending the status and headers is
up to you.

```php
public function run(Output $output, ?string $lastEventId = null, ?Clock $clock = null): StopReason
```

| Argument | Description |
|---|---|
| `$output` | Where the events are written |
| `$lastEventId` | The `Last-Event-ID` header of the request, if any |
| `$clock` | `SystemClock` by default |

It returns why the stream ended. It's what `send()` does, after sending the headers:

```php
foreach ($stream->getHeaders() as $name => $value) {
    header("{$name}: {$value}");
}

$reason = $stream->run(new PhpOutput(), $_SERVER['HTTP_LAST_EVENT_ID'] ?? null);
```

## Outputs

```php
interface Output
{
    public function open(): void;
    public function write(string $chunk): void;
    public function flush(): void;
    public function isConnectionAborted(): bool;
    public function close(): void;
}
```

The stream calls them in this order:

1. `open()` once, before anything is written;
2. `write()` and `flush()` for each event, comment and heartbeat. Every write is followed by a flush;
3. `isConnectionAborted()` after flushes, to decide whether to go on;
4. `close()` once at the end, **always**, also after an error.

Two implementations come with the library:

| Class | Writes to |
|---|---|
| `PhpOutput` | The PHP output, with `echo` and `flush()`. It closes the output buffers when opened, except on the command line. It ignores user aborts while open, so stop listeners always run, and restores the setting on `close()`. Used by `send()` and the bridges |
| `StreamOutput` | Any PHP stream resource, like a file or `php://stdout`. It never reports a disconnection, and doesn't close the resource |

### Example: recording a stream

A decorator that sends to the browser and also keeps a copy in a file, for debugging:

```php
use FunkyOz\EventSource\Output;

final class RecordingOutput implements Output
{
    public function __construct(
        private readonly Output $inner,
        private readonly Output $recording,
    ) {}

    public function open(): void
    {
        $this->inner->open();
        $this->recording->open();
    }

    public function write(string $chunk): void
    {
        $this->inner->write($chunk);
        $this->recording->write($chunk);
    }

    public function flush(): void
    {
        $this->inner->flush();
        $this->recording->flush();
    }

    public function isConnectionAborted(): bool
    {
        return $this->inner->isConnectionAborted();
    }

    public function close(): void
    {
        $this->inner->close();
        $this->recording->close();
    }
}

$stream->run(new RecordingOutput(new PhpOutput(), new StreamOutput(fopen('/tmp/stream.log', 'a'))));
```

## Clocks

```php
interface Clock
{
    public function now(): float;            // seconds, from a monotonic clock
    public function sleep(float $seconds): void;
}
```

`SystemClock` uses `hrtime()` and `usleep()`. `Testing\FakeClock` moves time forward instead of waiting, see
[Testing](testing.md). `now()` must be monotonic: a clock that jumps backwards, like `microtime()` after a system
time change, would break the tick schedule.

## Formatting

`EventFormatter` turns events into `text/event-stream` text, for outputs or logs:

```php
EventFormatter::format(new Event('tick', 'hello', '1')); // "event: tick\nid: 1\ndata: hello\n\n"
EventFormatter::comment('keep-alive');                    // ": keep-alive\n"
EventFormatter::retry(5);                                 // "retry: 5000\n\n"
```

## Writing an adapter for a framework

An adapter gives a framework a response it can send. Every framework with streamed responses works the same way:

1. create the framework's streamed response, with status `200` and `$stream->getHeaders()`;
2. in its callback, call `$stream->run(new PhpOutput(), $lastEventId)`, with the `Last-Event-ID` header of the
   framework's request.

This is the whole Symfony bridge:

```php
public static function create(EventStream $stream, ?Request $request = null): StreamedResponse
{
    $lastEventId = $request?->headers->get('Last-Event-ID');

    return new StreamedResponse(
        static function () use ($stream, $lastEventId): void {
            $stream->run(new PhpOutput(), $lastEventId);
        },
        Response::HTTP_OK,
        $stream->getHeaders(),
    );
}
```

Things to check in your framework:

- **It must not buffer the response body.** Some frameworks collect the output of streamed callbacks before
  sending it.
- **It must not compress the response**, or must skip `text/event-stream` responses.
- **It must run one request per process**: async servers aren't supported, see
  [Running in production](production.md#async-servers).

If you write an adapter for a popular framework, a pull request is welcome.

## Compatibility promise

The library follows [Semantic Versioning](https://semver.org). The public API, which only changes in major
versions, is every class, interface and method documented in the [API reference](api-reference.md).

Not covered:

- the `FunkyOz\EventSource\Internal\` namespace;
- anything marked `@internal`, like the `Stream` constructor;
- adding methods to the `Output` and `Clock` interfaces, which could happen in a minor version if a new feature
  needs it. It would be listed in the changelog.
