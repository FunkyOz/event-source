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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeneratorStreamTest extends TestCase
{
    public function testSendsEveryYieldedEvent(): void
    {
        $iterations = [];
        $stream = EventStream::fromGenerator(function (Stream $stream) use (&$iterations): \Generator {
            foreach ([25, 50, 100] as $percent) {
                yield Event::json('progress', ['percent' => $percent]);
                $iterations[] = $stream->iteration();
            }
            yield new Event('done');
        });

        $result = StreamTester::for($stream)->run();

        self::assertSame(['{"percent":25}', '{"percent":50}', '{"percent":100}'], array_map(
            static fn(Event $event): string => $event->data,
            $result->events('progress'),
        ));
        self::assertCount(1, $result->events('done'));
        self::assertSame([1, 2, 3], $iterations);
        self::assertSame(StopReason::Stopped, $result->stopReason());
        self::assertSame([], $result->sleeps());
    }

    public function testAcceptsAnyIterable(): void
    {
        $stream = EventStream::fromGenerator(fn(): array => [new Event('a'), new Event('b')]);

        self::assertCount(2, StreamTester::for($stream)->run()->events());
    }

    public function testStartAndStopListeners(): void
    {
        $calls = [];
        $stream = EventStream::fromGenerator(function () use (&$calls): \Generator {
            $calls[] = 'generator';
            yield new Event('a');
        })
            ->onStart(function () use (&$calls): void {
                $calls[] = 'start';
            })
            ->onStop(function () use (&$calls): void {
                $calls[] = 'stop';
            });

        StreamTester::for($stream)->run();

        self::assertSame(['start', 'generator', 'stop'], $calls);
    }

    public function testStop(): void
    {
        $stream = EventStream::fromGenerator(function (Stream $stream): \Generator {
            yield new Event('a');
            $stream->stop();
            yield new Event('b');
            yield new Event('c');
        });

        $result = StreamTester::for($stream)->run();

        // Nothing yielded after stop() is sent
        self::assertCount(1, $result->events());
        self::assertSame(StopReason::Stopped, $result->stopReason());
    }

    public function testStopInStartListenerSkipsTheGenerator(): void
    {
        $called = false;
        $stream = EventStream::fromGenerator(function () use (&$called): \Generator {
            $called = true;
            yield new Event('a');
        })->onStart(fn(Stream $stream) => $stream->stop());

        StreamTester::for($stream)->run();

        self::assertFalse($called);
    }

    public function testClientDisconnection(): void
    {
        $stream = EventStream::fromGenerator(function (): \Generator {
            while (true) {
                yield new Event('tick');
            }
        });

        $result = StreamTester::for($stream)->disconnectAfter(3)->run();

        self::assertCount(3, $result->events());
        self::assertSame(StopReason::ClientDisconnected, $result->stopReason());
    }

    public function testMaxDuration(): void
    {
        $clock = new FakeClock();
        $stream = EventStream::fromGenerator(function () use ($clock): \Generator {
            while (true) {
                $clock->advance(1);
                yield new Event('tick');
            }
        })->maxDuration(3);
        $output = new MemoryOutput();

        self::assertSame(StopReason::MaxDurationReached, $stream->run($output, null, $clock));
        self::assertCount(3, $output->parse()->events);
    }

    public function testExceptionInTheGenerator(): void
    {
        $stream = EventStream::fromGenerator(function (): \Generator {
            yield new Event('a');
            throw new \RuntimeException('failed');
        })->onError(fn(\Throwable $e, Stream $stream) => $stream->send(new Event('error', $e->getMessage())));

        $result = StreamTester::for($stream)->run();

        self::assertSame(StopReason::Error, $result->stopReason());
        self::assertSame('failed', $result->events('error')[0]->data);
    }

    /**
     * The generators are typed as plain closures on purpose: they break the documented signature
     */
    #[DataProvider('provideInvalidGenerators')]
    public function testRejectsInvalidGenerators(\Closure $generator, string $message): void
    {
        $stream = EventStream::fromGenerator($generator);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);
        StreamTester::for($stream)->run();
    }

    /**
     * @return iterable<string, array{\Closure, string}>
     */
    public static function provideInvalidGenerators(): iterable
    {
        yield 'not iterable' => [static fn(): string => 'not iterable', 'must return an iterable, string returned'];
        yield 'not an event' => [static fn(): array => ['plain string'], 'must yield FunkyOz\EventSource\Event instances, string yielded'];
    }

    public function testTickListenersAreNotAllowed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('onTick() cannot be used on a stream created with fromGenerator()');
        EventStream::fromGenerator(fn(): array => [])->onTick(fn(): null => null);
    }

    public function testHeartbeatIsNotAllowed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('heartbeat() cannot be used');
        EventStream::fromGenerator(fn(): array => [])->heartbeat(15);
    }

    public function testIntervalIsNotAllowed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('every() cannot be used');
        EventStream::fromGenerator(fn(): array => [])->every(1);
    }
}
