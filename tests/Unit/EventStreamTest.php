<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Unit;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Stream;
use FunkyOz\EventSource\Testing\FakeClock;
use FunkyOz\EventSource\Testing\MemoryOutput;
use FunkyOz\EventSource\Testing\StreamTester;
use PHPUnit\Framework\TestCase;

class EventStreamTest extends TestCase
{
    public function testListenersRunInOrder(): void
    {
        $calls = [];
        $stream = EventStream::create()
            ->onStart(function (Stream $stream) use (&$calls): void {
                $calls[] = 'start ' . $stream->iteration();
            })
            ->onTick(function (Stream $stream) use (&$calls): void {
                $calls[] = 'tick A ' . $stream->iteration();
            })
            ->onTick(function (Stream $stream) use (&$calls): void {
                $calls[] = 'tick B ' . $stream->iteration();
            })
            ->onStop(function (Stream $stream) use (&$calls): void {
                $calls[] = 'stop ' . $stream->stopReason()?->name;
            });

        $result = StreamTester::for($stream)->disconnectAfter(2)->run();

        self::assertSame(['start 0', 'tick A 1', 'tick B 1', 'tick A 2', 'tick B 2', 'stop ClientDisconnected'], $calls);
        self::assertSame(StopReason::ClientDisconnected, $result->stopReason());
        self::assertSame(2, $result->iterations());
    }

    public function testSendsEventsImmediately(): void
    {
        $output = new MemoryOutput();
        $stream = EventStream::create()->onStart(function (Stream $stream): void {
            $stream->send(new Event('one'));
            $stream->send(new Event('two'));
        });

        $stream->run($output, null, new FakeClock());

        self::assertSame("event: one\ndata: \n\nevent: two\ndata: \n\n", $output->contents());
        self::assertSame(2, $output->flushes());
        self::assertTrue($output->wasOpened());
        self::assertTrue($output->wasClosed());
    }

    public function testStopEndsAfterTheCurrentListener(): void
    {
        $calls = [];
        $stream = EventStream::create()
            ->onTick(function (Stream $stream) use (&$calls): void {
                $calls[] = 'A';
                if (2 === $stream->iteration()) {
                    $stream->stop();
                    self::assertTrue($stream->isStopped());
                }
            })
            ->onTick(function () use (&$calls): void {
                $calls[] = 'B';
            });

        $result = StreamTester::for($stream)->run();

        self::assertSame(['A', 'B', 'A'], $calls);
        self::assertSame(StopReason::Stopped, $result->stopReason());
        self::assertSame([1.0], $result->sleeps());
    }

    public function testStopInStartListenerSkipsTheTicks(): void
    {
        $ticks = 0;
        $stream = EventStream::create()
            ->onStart(fn(Stream $stream) => $stream->stop())
            ->onTick(function () use (&$ticks): void {
                $ticks++;
            });

        $result = StreamTester::for($stream)->run();

        self::assertSame(0, $ticks);
        self::assertSame(StopReason::Stopped, $result->stopReason());
    }

    public function testStopInStartListenerSkipsTheNextStartListeners(): void
    {
        $calls = [];
        $stream = EventStream::create()
            ->onStart(function (Stream $stream) use (&$calls): void {
                $calls[] = 'first';
                $stream->stop();
            })
            ->onStart(function (Stream $stream) use (&$calls): void {
                $calls[] = 'second';
                $stream->send(new Event('hello'));
            })
            ->onStop(function () use (&$calls): void {
                $calls[] = 'stop';
            });

        $result = StreamTester::for($stream)->run();

        self::assertSame(['first', 'stop'], $calls);
        self::assertSame([], $result->events());
        self::assertSame(StopReason::Stopped, $result->stopReason());
    }

    public function testWithoutTickListenersEndsAfterStart(): void
    {
        $stream = EventStream::create()->onStart(fn(Stream $stream) => $stream->send(new Event('hello')));

        $result = StreamTester::for($stream)->run();

        self::assertCount(1, $result->events('hello'));
        self::assertSame(StopReason::Stopped, $result->stopReason());
        self::assertSame([], $result->sleeps());
    }

    public function testTicksAreEvenlySpacedWhateverTheListenerTakes(): void
    {
        $clock = new FakeClock();
        $output = new MemoryOutput();
        $output->disconnectWhen(static fn(): bool => count($clock->sleeps()) >= 2);
        $stream = EventStream::create()
            ->every(5)
            ->onTick(fn() => $clock->advance(2));

        $stream->run($output, null, $clock);

        self::assertSame([3.0, 3.0], $clock->sleeps());
    }

    public function testNoSleepWhenATickTakesLongerThanTheInterval(): void
    {
        $clock = new FakeClock();
        $output = new MemoryOutput();
        $ticks = 0;
        $stream = EventStream::create()
            ->every(1)
            ->onTick(function (Stream $stream) use ($clock, &$ticks): void {
                $clock->advance(3);
                if (3 === ++$ticks) {
                    $stream->stop();
                }
            });

        $stream->run($output, null, $clock);

        self::assertSame([], $clock->sleeps());
    }

    public function testEveryCanBeZero(): void
    {
        $result = StreamTester::for(EventStream::create()->every(0)->onTick(fn(): null => null))->disconnectAfter(3)->run();

        self::assertSame([], $result->sleeps());
        self::assertSame(3, $result->iterations());
    }

    public function testMaxDuration(): void
    {
        $elapsed = [];
        $stream = EventStream::create()
            ->every(2)
            ->maxDuration(5)
            ->onTick(function (Stream $stream) use (&$elapsed): void {
                $elapsed[] = $stream->elapsed();
            });

        $result = StreamTester::for($stream)->run();

        self::assertSame([0.0, 2.0, 4.0], $elapsed);
        // It doesn't sleep past the maximum duration
        self::assertSame([2.0, 2.0, 1.0], $result->sleeps());
        self::assertSame(StopReason::MaxDurationReached, $result->stopReason());
    }

    public function testTimeIsMeasuredFromTheStartOfTheStream(): void
    {
        // A clock that doesn't start at zero, like a real one
        $clock = new FakeClock(1000);
        $elapsed = [];
        $stream = EventStream::create()
            ->every(2)
            ->maxDuration(3)
            ->onTick(function (Stream $stream) use (&$elapsed): void {
                $elapsed[] = $stream->elapsed();
            });

        self::assertSame(StopReason::MaxDurationReached, $stream->run(new MemoryOutput(), null, $clock));
        self::assertSame([0.0, 2.0], $elapsed);
        self::assertSame([2.0, 1.0], $clock->sleeps());
    }

    public function testMaxDurationReachedDuringATick(): void
    {
        $clock = new FakeClock();
        $stream = EventStream::create()
            ->maxDuration(1)
            ->onTick(fn() => $clock->advance(5));

        self::assertSame(StopReason::MaxDurationReached, $stream->run(new MemoryOutput(), null, $clock));
        self::assertSame([], $clock->sleeps());
    }

    public function testMaxDurationReachedInStartListener(): void
    {
        $clock = new FakeClock();
        $ticks = 0;
        $stream = EventStream::create()
            ->maxDuration(1)
            ->onStart(fn() => $clock->advance(1))
            ->onTick(function () use (&$ticks): void {
                $ticks++;
            });

        self::assertSame(StopReason::MaxDurationReached, $stream->run(new MemoryOutput(), null, $clock));
        self::assertSame(0, $ticks);
    }

    public function testHeartbeatsAreSentOnTimeWhileWaiting(): void
    {
        $stream = EventStream::create()
            ->every(10)
            ->heartbeat(3)
            ->onTick(fn(): null => null);

        $result = StreamTester::for($stream)->disconnectAfter(2)->run();

        self::assertSame(['', '', ''], $result->comments());
        self::assertSame([3.0, 3.0, 3.0, 1.0], $result->sleeps());
        self::assertSame(":\n:\n:\n", $result->output());
    }

    public function testNoHeartbeatWhenEventsWereSentRecently(): void
    {
        $stream = EventStream::create()
            ->every(2)
            ->heartbeat(3)
            ->onTick(fn(Stream $stream) => $stream->send(new Event('tick')));

        $result = StreamTester::for($stream)->disconnectAfter(3)->run();

        self::assertSame([], $result->comments());
        self::assertCount(3, $result->events('tick'));
    }

    public function testHeartbeatDetectsADisconnection(): void
    {
        $clock = new FakeClock();
        $output = new MemoryOutput();
        $output->disconnectWhen(static fn(): bool => 2 === substr_count($output->contents(), ":\n"));
        $stream = EventStream::create()
            ->every(100)
            ->heartbeat(1)
            ->onTick(fn(): null => null);

        self::assertSame(StopReason::ClientDisconnected, $stream->run($output, null, $clock));
        self::assertSame([1.0, 1.0], $clock->sleeps());
    }

    public function testRetryIsSentAndFlushedFirst(): void
    {
        $output = new MemoryOutput();
        $flushesAtStart = null;
        $stream = EventStream::create()
            ->retry(1)
            ->onStart(function () use ($output, &$flushesAtStart): void {
                $flushesAtStart = $output->flushes();
            });

        $stream->run($output, null, new FakeClock());

        self::assertSame(1, $flushesAtStart);
    }

    public function testRetryIsSentFirst(): void
    {
        $stream = EventStream::create()
            ->retry(2.5)
            ->onStart(fn(Stream $stream) => $stream->send(new Event('hello')));

        $result = StreamTester::for($stream)->run();

        self::assertStringStartsWith("retry: 2500\n\n", $result->output());
        self::assertSame(2.5, $result->retry());
        self::assertNull($result->events()[0]->retry);
    }

    public function testLastEventIdFollowsTheEventsSent(): void
    {
        $ids = [];
        $stream = EventStream::create()
            ->onStart(function (Stream $stream) use (&$ids): void {
                $ids[] = $stream->lastEventId();
                $stream->send(new Event('no-id'));
                $ids[] = $stream->lastEventId();
                $stream->send(new Event('with-id', '', '42'));
                $ids[] = $stream->lastEventId();
            });

        StreamTester::for($stream)->withLastEventId('41')->run();
        StreamTester::for($stream)->run();

        self::assertSame(['41', '41', '42', null, null, '42'], $ids);
    }

    public function testComment(): void
    {
        $stream = EventStream::create()->onStart(fn(Stream $stream) => $stream->comment('hello'));

        self::assertSame(['hello'], StreamTester::for($stream)->run()->comments());
    }

    public function testExceptionIsRethrownAfterCleanupWithoutErrorListeners(): void
    {
        $output = new MemoryOutput();
        $reason = null;
        $stream = EventStream::create()
            ->onTick(fn() => throw new \RuntimeException('database down'))
            ->onStop(function (Stream $stream) use (&$reason): void {
                $reason = $stream->stopReason();
                $stream->send(new Event('bye'));
            });

        try {
            $stream->run($output, null, new FakeClock());
            self::fail('The exception was not rethrown');
        } catch (\RuntimeException $e) {
            self::assertSame('database down', $e->getMessage());
        }

        self::assertSame(StopReason::Error, $reason);
        self::assertSame("event: bye\ndata: \n\n", $output->contents());
        self::assertTrue($output->wasClosed());
    }

    public function testErrorListenersHandleTheException(): void
    {
        $handled = null;
        $stream = EventStream::create()
            ->onStart(fn() => throw new \RuntimeException('database down'))
            ->onError(function (\Throwable $e, Stream $stream) use (&$handled): void {
                $handled = $e;
                $stream->send(new Event('error', 'Temporary problem'));
            });

        $result = StreamTester::for($stream)->run();

        self::assertInstanceOf(\RuntimeException::class, $handled);
        self::assertSame(StopReason::Error, $result->stopReason());
        self::assertSame('Temporary problem', $result->events('error')[0]->data);
    }

    public function testOutputIsClosedWhenAStopListenerThrows(): void
    {
        $output = new MemoryOutput();
        $stream = EventStream::create()->onStop(fn() => throw new \LogicException('bug in cleanup'));

        try {
            $stream->run($output, null, new FakeClock());
            self::fail('The exception was not thrown');
        } catch (\LogicException) {
        }

        self::assertTrue($output->wasClosed());
    }

    public function testNothingCanBeSentAfterTheEnd(): void
    {
        $saved = null;
        $stream = EventStream::create()->onStart(function (Stream $stream) use (&$saved): void {
            $saved = $stream;
        });
        StreamTester::for($stream)->run();
        self::assertInstanceOf(Stream::class, $saved);

        $this->expectException(\LogicException::class);
        $saved->send(new Event('late'));
    }

    public function testADefinitionCanRunManyTimes(): void
    {
        $stream = EventStream::create()->onTick(fn(Stream $stream) => $stream->send(new Event('tick', (string) $stream->iteration())));

        $first = StreamTester::for($stream)->disconnectAfter(2)->run();
        $second = StreamTester::for($stream)->disconnectAfter(3)->run();

        self::assertCount(2, $first->events());
        self::assertSame(['1', '2', '3'], array_map(static fn(Event $event): string => $event->data, $second->events()));
    }

    public function testDefaultHeaders(): void
    {
        self::assertSame([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], EventStream::create()->getHeaders());
    }

    public function testHeadersAreReplacedCaseInsensitively(): void
    {
        $stream = EventStream::create()
            ->header('x-accel-buffering', 'yes')
            ->headers(['Access-Control-Allow-Origin' => 'https://example.com']);

        self::assertSame([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'x-accel-buffering' => 'yes',
            'Access-Control-Allow-Origin' => 'https://example.com',
        ], $stream->getHeaders());
    }

    public function testRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->header('X-Test', "value\r\nSet-Cookie: a=b");
    }

    public function testRejectsInvalidHeaderNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->header('X-Test: a', 'b');
    }

    public function testRejectsEmptyHeaderNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->header('', 'b');
    }

    public function testRejectsNegativeInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->every(-1);
    }

    public function testRejectsZeroHeartbeat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->heartbeat(0);
    }

    public function testRejectsZeroMaxDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->maxDuration(0);
    }

    public function testRejectsNegativeRetry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventStream::create()->retry(-1);
    }

    public function testSettingsCanBeTurnedOff(): void
    {
        $stream = EventStream::create()
            ->heartbeat(1)->heartbeat(null)
            ->maxDuration(1)->maxDuration(null)
            ->retry(0)->retry(null)
            ->every(10)
            ->onTick(fn(): null => null);

        $result = StreamTester::for($stream)->disconnectAfter(2)->run();

        self::assertSame([10.0], $result->sleeps());
        self::assertSame('', $result->output());
    }
}
