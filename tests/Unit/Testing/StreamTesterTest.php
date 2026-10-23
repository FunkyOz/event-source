<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Unit\Testing;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use FunkyOz\EventSource\Testing\FakeClock;
use FunkyOz\EventSource\Testing\StreamTester;
use PHPUnit\Framework\TestCase;

class StreamTesterTest extends TestCase
{
    public function testFiltersEventsByName(): void
    {
        $stream = EventStream::create()->onStart(function (Stream $stream): void {
            $stream->send(new Event('a', '1'));
            $stream->send(new Event('b', '2'));
            $stream->send(new Event('a', '3'));
        });

        $result = StreamTester::for($stream)->run();

        self::assertCount(3, $result->events());
        self::assertSame(['1', '3'], array_map(static fn(Event $event): string => $event->data, $result->events('a')));
        self::assertSame([], $result->events('c'));
        self::assertSame(0, $result->iterations());
    }

    public function testDoesNotChangeTheGivenStream(): void
    {
        $stream = EventStream::create()->onTick(fn(): null => null);

        StreamTester::for($stream)->disconnectAfter(1)->run();

        // The tester adds a listener to a copy, not to the stream it was given
        $onStart = (new \ReflectionProperty(EventStream::class, 'onStart'))->getValue($stream);
        self::assertSame([], $onStart);
    }

    public function testIterationsAreZeroWhenTheStreamFailsToStart(): void
    {
        $stream = EventStream::create()
            ->onStart(fn() => throw new \RuntimeException('failed'))
            ->onError(fn(): null => null);

        self::assertSame(0, StreamTester::for($stream)->run()->iterations());
    }

    public function testMinimumValues(): void
    {
        $ticks = 0;
        $stream = EventStream::create()->onTick(function () use (&$ticks): void {
            $ticks++;
        });

        StreamTester::for($stream)->disconnectAfter(1)->run();
        try {
            StreamTester::for($stream)->iterationLimit(1)->run();
        } catch (\LogicException) {
        }

        self::assertSame(2, $ticks);
    }

    public function testFailsWhenTheStreamDoesNotEnd(): void
    {
        $ticks = 0;
        $stream = EventStream::create()->every(1)->onTick(function () use (&$ticks): void {
            $ticks++;
        });

        try {
            StreamTester::for($stream)->iterationLimit(5)->run();
            self::fail('The stream did not fail');
        } catch (\LogicException $e) {
            self::assertStringContainsString('did not end after 5 iterations', $e->getMessage());
        }
        self::assertSame(5, $ticks);
    }

    public function testRejectsInvalidDisconnectAfter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamTester::for(EventStream::create())->disconnectAfter(0);
    }

    public function testRejectsInvalidIterationLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamTester::for(EventStream::create())->iterationLimit(0);
    }

    public function testFakeClock(): void
    {
        self::assertSame(0.0, (new FakeClock())->now());

        $clock = new FakeClock(10);
        $clock->sleep(1.5);
        $clock->advance(2);

        self::assertSame(13.5, $clock->now());
        self::assertSame([1.5], $clock->sleeps());
    }
}
