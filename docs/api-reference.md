# API reference

All classes are in the `FunkyOz\EventSource\` namespace, and are `final` unless noted.

- [`EventStream`](#eventstream)
- [`Stream`](#stream)
- [`Event`](#event)
- [`StopReason`](#stopreason)
- [`EventFormatter`](#eventformatter)
- [`Output`, `PhpOutput`, `StreamOutput`](#output)
- [`Clock`, `SystemClock`](#clock)
- [Bridges](#bridges)
- [Testing](#testing)

## EventStream

The definition of a stream. Its methods change it and return it, so calls can be chained. It holds no state from a
run, and can be sent any number of times.

### Creating

| Method | Description |
|---|---|
| `static create(): EventStream` | A tick stream: calls the tick listeners at a regular interval |
| `static fromGenerator(callable $generator): EventStream` | A generator stream. `$generator` receives the `Stream`, and returns an iterable of `Event`, usually by yielding |

### Settings

| Method | Default | Throws |
|---|---|---|
| `every(float $seconds): EventStream`: seconds between the start of two ticks | `1.0` | `InvalidArgumentException` if negative. `LogicException` on a generator stream |
| `heartbeat(?float $seconds): EventStream`: send a comment when nothing was written for this long. `null` turns it off | off | `InvalidArgumentException` if zero or negative. `LogicException` on a generator stream |
| `maxDuration(?float $seconds): EventStream`: end the stream after this long. `null` for no limit | no limit | `InvalidArgumentException` if zero or negative |
| `retry(?float $seconds): EventStream`: reconnection delay sent when the stream starts. `null` to not send it | not sent | `InvalidArgumentException` if negative |
| `header(string $name, string $value): EventStream`: add or replace a header. Names are case-insensitive | see below | `InvalidArgumentException` if the name is empty or contains `:`, `\r` or `\n`, or the value contains `\r`, `\n` or NUL |
| `headers(array<string, string> $headers): EventStream`: `header()` for each entry | | Same as `header()` |
| `getHeaders(): array<string, string>`: the headers to send, defaults included | | |

Default headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`.

### Listeners

| Method | Called | Signature of the listener |
|---|---|---|
| `onStart(callable $listener): EventStream` | Once, before the first tick or generated event | `fn (Stream $stream)` |
| `onTick(callable $listener): EventStream` | On every tick. Throws `LogicException` on a generator stream | `fn (Stream $stream)` |
| `onStop(callable $listener): EventStream` | Once, at the end, whatever the reason | `fn (Stream $stream)` |
| `onError(callable $listener): EventStream` | When a start or tick listener, or the generator, throws | `fn (\Throwable $e, Stream $stream)` |

Listeners of each kind run in the order they were added. Their return value is ignored. Without `onError`
listeners, an exception is rethrown after the stop listeners have run. See [Error handling](error-handling.md).

### Sending

| Method | Description | Throws |
|---|---|---|
| `send(): void` | Plain PHP: sends the headers, reads `Last-Event-ID` from `$_SERVER`, and runs the stream with `PhpOutput` | `LogicException` if output was already sent |
| `static sendNoContent(): void` | Plain PHP: answers `204 No Content`, so the browser stops reconnecting | `LogicException` if output was already sent |
| `run(Output $output, ?string $lastEventId = null, ?Clock $clock = null): StopReason` | Runs the stream against any output, without sending headers. For adapters and tests | Rethrows a listener's exception when there are no `onError` listeners |

## Stream

A running stream, given to listeners and to the generator. Created by the library for each run.

| Method | Description |
|---|---|
| `send(Event $event): void` | Writes the event and flushes it to the client immediately. If the event has an id, `lastEventId()` returns it from then on |
| `comment(string $text = ''): void` | Writes a comment, which the browser ignores |
| `stop(): void` | Ends the stream after the current listener. In a generator stream, nothing yielded after it is sent |
| `isStopped(): bool` | Whether `stop()` was called |
| `lastEventId(): ?string` | The `Last-Event-ID` sent by the browser, then the id of the last event sent |
| `iteration(): int` | 0 in start listeners, then 1, 2, 3… for each tick or generated event |
| `elapsed(): float` | Seconds since the stream started |
| `stopReason(): ?StopReason` | Why the stream ended. Set when the stop listeners run, `null` before |

`send()` and `comment()` throw a `LogicException` once the stream has ended.

## Event

```php
final readonly class Event
{
    public function __construct(
        public ?string $name = null,
        public string $data = '',
        public ?string $id = null,
        public ?float $retry = null,
    );
}
```

| Member | Description |
|---|---|
| `$name` | Event type. `null` for an unnamed event, received by `onmessage` |
| `$data` | Payload. Multi-line strings are sent as several `data:` fields |
| `$id` | Sent back by the browser in `Last-Event-ID` after a reconnection |
| `$retry` | Seconds the browser waits before reconnecting. Sent in milliseconds, rounded |
| `static json(?string $name, mixed $data, ?string $id = null, ?float $retry = null): Event` | An event whose data is JSON-encoded, without escaping slashes or Unicode. Throws `JsonException` |
| `withName(?string $name): Event` | A copy with another name |
| `withData(string $data): Event` | A copy with other data |
| `withId(?string $id): Event` | A copy with another id |
| `withRetry(?float $retry): Event` | A copy with another retry delay |

The constructor and the `with*()` methods throw an `InvalidArgumentException` when the name contains `\r` or `\n`,
the id contains `\r`, `\n` or NUL, or the retry delay is negative.

## StopReason

```php
enum StopReason
{
    case Stopped;            // stop() was called, the generator finished, or there were no tick listeners
    case ClientDisconnected; // the browser closed the connection
    case MaxDurationReached; // maxDuration() was reached
    case Error;              // a listener or the generator threw
}
```

## EventFormatter

| Method | Returns |
|---|---|
| `static format(Event $event): string` | The event as `text/event-stream` text, ending with a blank line |
| `static comment(string $text = ''): string` | A comment: `:` for an empty one, `: text` per line otherwise |
| `static retry(float $seconds): string` | A `retry:` field in milliseconds, followed by a blank line |

## Output

```php
interface Output
{
    public function open(): void;               // once, before anything is written
    public function write(string $chunk): void;
    public function flush(): void;
    public function isConnectionAborted(): bool; // only reliable after a flush
    public function close(): void;              // once at the end, also after an error
}
```

| Class | Description |
|---|---|
| `PhpOutput` | Writes to the PHP output with `echo`, and flushes the current output buffer and the system buffers. Ignores user aborts while open, and restores the previous setting when closed. Detects disconnections with `connection_aborted()` |
| `StreamOutput` | `new StreamOutput(resource $stream)`: writes to a stream resource. Never reports a disconnection, and doesn't close the resource. Throws `InvalidArgumentException` if the resource isn't open |

See [Extending](extending.md#outputs).

## Clock

```php
interface Clock
{
    public function now(): float;               // seconds, from a monotonic clock
    public function sleep(float $seconds): void;
}
```

| Class | Description |
|---|---|
| `SystemClock` | `hrtime()` and `usleep()`. Zero or negative sleeps return immediately |

## Bridges

### `Bridge\Symfony\StreamedResponseFactory`

| Method | Returns |
|---|---|
| `static create(EventStream $stream, ?Request $request = null): StreamedResponse` | A Symfony `StreamedResponse` with status 200 and the stream's headers. The `Last-Event-ID` header is read from `$request` |
| `static noContent(): Response` | A `204 No Content` response |

Requires `symfony/http-foundation` 6.4 to 8.x.

### `Bridge\Laravel\EventSourceServiceProvider`

Registered by package discovery. Adds the response macro:

```php
response()->sse(EventStream $stream): StreamedResponse
```

It reads `Last-Event-ID` from the current request. The constant `EventSourceServiceProvider::MACRO` holds its name,
`'sse'`. Booting throws a `LogicException` if the name is already used by Laravel or another package.

Requires Laravel 12 or 13.

## Testing

In the `FunkyOz\EventSource\Testing\` namespace. See [Testing](testing.md).

### `StreamTester`

| Method | Description |
|---|---|
| `static for(EventStream $stream): StreamTester` | Prepares a run of a copy of the stream |
| `withLastEventId(?string $lastEventId): StreamTester` | The `Last-Event-ID` to simulate |
| `disconnectAfter(int $iterations): StreamTester` | Simulates a disconnection after this many ticks or generated events. Throws `InvalidArgumentException` below 1 |
| `iterationLimit(int $iterations): StreamTester` | Maximum number of iterations, 10,000 by default. Throws `InvalidArgumentException` below 1 |
| `run(): StreamResult` | Runs the stream with a `MemoryOutput` and a `FakeClock`. Throws `LogicException` when the iteration limit is reached |

### `StreamResult`

| Method | Returns |
|---|---|
| `events(?string $name = null): list<Event>` | The events received, all or only those with this name |
| `comments(): list<string>` | The comments received; heartbeats are empty strings |
| `retry(): ?float` | The last reconnection delay received, in seconds |
| `stopReason(): StopReason` | Why the stream ended |
| `iterations(): int` | How many ticks or generated events ran |
| `sleeps(): list<float>` | Every wait, in seconds |
| `output(): string` | The raw text |

### `MemoryOutput`

Implements `Output`, and keeps everything in memory.

| Method | Description |
|---|---|
| `contents(): string` | The raw text written |
| `parse(): ParsedStream` | The text parsed like a browser does |
| `flushes(): int` | How many times `flush()` was called |
| `disconnect(): void` | Reports a disconnection from now on |
| `disconnectWhen(callable $condition): void` | Reports a disconnection as soon as `$condition()` returns `true` |
| `wasOpened(): bool`, `wasClosed(): bool` | Whether `open()` and `close()` were called |

### `FakeClock`

Implements `Clock`, without waiting.

| Method | Description |
|---|---|
| `__construct(float $now = 0.0)` | Starts at this time |
| `now(): float` | The current fake time |
| `sleep(float $seconds): void` | Records the duration, and moves time forward |
| `advance(float $seconds): void` | Moves time forward |
| `sleeps(): list<float>` | Every duration passed to `sleep()` |

### `EventParser` and `ParsedStream`

| Member | Description |
|---|---|
| `EventParser::parse(string $stream): ParsedStream` | Parses `text/event-stream` text following the specification. An incomplete last event is ignored |
| `ParsedStream::$events` | `list<Event>`: the complete events. Unnamed events have a `null` name |
| `ParsedStream::$comments` | `list<string>`: the comments, without the leading `:` and space |
| `ParsedStream::$retry` | `?float`: the last reconnection delay, in seconds |
