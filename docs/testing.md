# Testing

A stream normally needs a server and a browser, and it waits between ticks. The `Testing` namespace removes all
three: it runs a stream in memory, without waiting, and returns what a browser would have received. It works with
any test framework.

## StreamTester

```php
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Testing\StreamTester;
use PHPUnit\Framework\TestCase;

final class NotificationStreamTest extends TestCase
{
    public function testSendsTheNewNotifications(): void
    {
        $stream = (new NotificationStream($this->repositoryWith([42, 43])))->forUser(1);

        $result = StreamTester::for($stream)
            ->withLastEventId('41')
            ->disconnectAfter(3)
            ->run();

        self::assertCount(2, $result->events('notification'));
        self::assertSame('42', $result->events()[0]->id);
        self::assertSame(StopReason::ClientDisconnected, $result->stopReason());
        self::assertSame(3, $result->iterations());
        self::assertSame([2.0, 2.0], $result->sleeps());
    }
}
```

| Method | Description |
|---|---|
| `StreamTester::for(EventStream $stream)` | Prepare a run. The stream itself isn't changed: the tester works on a copy |
| `withLastEventId(?string $id)` | Simulate a reconnection, with this `Last-Event-ID` |
| `disconnectAfter(int $iterations)` | Simulate the user leaving after this many ticks, or generated events |
| `iterationLimit(int $iterations)` | Fail if the stream runs longer than this. 10,000 by default |
| `run(): StreamResult` | Run the stream, without waiting |

Time is simulated: sleeping moves a fake clock forward instantly, so a stream with `every(60)` and
`maxDuration(3600)` runs in milliseconds.

A stream that never ends, because nothing stops it and `disconnectAfter()` wasn't called, makes `run()` throw a
`LogicException` after the iteration limit. A test can't hang.

Exceptions thrown by the stream, when it has no `onError` listener, are rethrown by `run()`, so you can assert them
with `expectException()`.

## StreamResult

| Method | Returns |
|---|---|
| `events(?string $name = null)` | The events received, as `Event` objects. Only those with this name, when given |
| `comments()` | The comments received. Heartbeats are empty comments |
| `retry()` | The last reconnection delay received, in seconds |
| `stopReason()` | Why the stream ended, a `StopReason` |
| `iterations()` | How many ticks, or generated events, ran |
| `sleeps()` | Every wait between ticks, in seconds |
| `output()` | The raw `text/event-stream` text |

The events are parsed back the way a browser does, so they're what your JavaScript receives: unnamed events have
a `null` name, and multi-line data is joined with `\n`.

## What to test

**What the stream sends**, for a given state of your data:

```php
$result = StreamTester::for($stream)->disconnectAfter(1)->run();

self::assertEquals(
    [new Event('stats', '{"users":3}')],
    $result->events(),
);
```

**Resuming**, with `withLastEventId()`: check that only the newer items are sent.

**When it ends**, with `stopReason()`: for example, that a finished job stops the stream with
`StopReason::Stopped` and sends a `done` event.

**Timing**, with `sleeps()`: that the interval is what you expect, and that heartbeats are sent:

```php
$result = StreamTester::for(EventStream::create()->every(30)->heartbeat(10)->onTick(fn () => null))
    ->disconnectAfter(2)
    ->run();

self::assertCount(2, $result->comments());   // heartbeats at 10 s and 20 s
self::assertSame([10.0, 10.0, 10.0], $result->sleeps());
```

**Errors**, with an `onError` listener and `stopReason()`, or with `expectException()` when there's none.

## Finer control: MemoryOutput and FakeClock

`StreamTester` is built on two classes you can use directly, when you need to change things during the run:

```php
use FunkyOz\EventSource\Testing\FakeClock;
use FunkyOz\EventSource\Testing\MemoryOutput;

$clock = new FakeClock();
$output = new MemoryOutput();

// Simulate a slow tick
$stream = EventStream::create()
    ->every(5)
    ->maxDuration(20)
    ->onTick(fn () => $clock->advance(2));

$reason = $stream->run($output, lastEventId: null, clock: $clock);

self::assertSame(StopReason::MaxDurationReached, $reason);
self::assertSame([3.0, 3.0, 3.0, 3.0], $clock->sleeps());
```

**`MemoryOutput`** keeps everything written:

| Method | Description |
|---|---|
| `contents()` | The raw text written |
| `parse()` | The text parsed back: a `ParsedStream` with `events`, `comments` and `retry` |
| `flushes()` | How many times the output was flushed |
| `disconnect()` | Simulate a disconnection from now on |
| `disconnectWhen(callable $condition)` | Simulate a disconnection as soon as `$condition()` returns `true` |
| `wasOpened()`, `wasClosed()` | Whether the stream opened and closed the output |

**`FakeClock`** never waits:

| Method | Description |
|---|---|
| `new FakeClock(float $now = 0.0)` | A clock starting at this time |
| `now()` | The current fake time |
| `sleep(float $seconds)` | Records the sleep, and moves time forward |
| `advance(float $seconds)` | Moves time forward, e.g. to simulate a slow listener |
| `sleeps()` | Every duration passed to `sleep()` |

**`EventParser::parse(string $text)`** parses any `text/event-stream` text, for example a response captured in an
end-to-end test.

## Testing framework integration

The bridges are thin: test your stream definitions with `StreamTester`, and test the route only for what's
specific to it, like authentication and the response type.

**Symfony**, with `WebTestCase`. Symfony's test client **runs** a `StreamedResponse` to capture its content, so a
request to a stream route runs the stream until it ends, with real waiting. A stream that only ends when the client
leaves would hang the test. Either:

- test only the requests that don't stream, like an anonymous user being refused;
- or build the stream in a service, and replace it in the test environment with a short one, for example a
  generator stream with fixed events:

```php
// In the test
static::getContainer()->set(NotificationStream::class, new class extends NotificationStream {
    public function __construct() {} // no repository needed

    public function forUser(int $userId): EventStream
    {
        return EventStream::fromGenerator(fn () => [new Event('notification', 'test')]);
    }
});

$client->request('GET', '/notifications/stream');

self::assertResponseIsSuccessful();
self::assertResponseHeaderSame('Content-Type', 'text/event-stream');
self::assertSame("event: notification\ndata: test\n\n", $client->getInternalResponse()->getContent());
```

The anonymous class extends your service, so it can't be `final` for this approach. An interface works too.

**Laravel**:

```php
$this->actingAs($user)
    ->get('/notifications/stream')
    ->assertOk()
    ->assertHeader('X-Accel-Buffering', 'no');
```

Laravel's test client doesn't send the streamed content either, unless you call `streamedContent()`. Don't: it would
run the stream until it ends. Test the stream itself with `StreamTester` instead.
