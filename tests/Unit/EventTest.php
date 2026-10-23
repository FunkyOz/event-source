<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Unit;

use FunkyOz\EventSource\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testDefaults(): void
    {
        $event = new Event();

        self::assertNull($event->name);
        self::assertSame('', $event->data);
        self::assertNull($event->id);
        self::assertNull($event->retry);
    }

    public function testNameComesFirst(): void
    {
        $event = new Event('tick', 'data', '42', 1.5);

        self::assertSame('tick', $event->name);
        self::assertSame('data', $event->data);
        self::assertSame('42', $event->id);
        self::assertSame(1.5, $event->retry);
    }

    public function testJson(): void
    {
        $event = Event::json('order', ['id' => 42, 'url' => 'https://example.com/a', 'name' => 'Caffè'], '42', 2);

        self::assertSame('order', $event->name);
        self::assertSame('{"id":42,"url":"https://example.com/a","name":"Caffè"}', $event->data);
        self::assertSame('42', $event->id);
        self::assertSame(2.0, $event->retry);
    }

    public function testJsonThrowsOnInvalidData(): void
    {
        $this->expectException(\JsonException::class);
        Event::json('broken', "\xB1\x31");
    }

    public function testWithersReturnNewInstances(): void
    {
        $event = new Event('a', 'b', 'c', 1);

        $changed = $event->withName('x')->withData('y')->withId('z')->withRetry(2);

        self::assertEquals(new Event('x', 'y', 'z', 2), $changed);
        self::assertEquals(new Event('a', 'b', 'c', 1), $event);
        self::assertNull($event->withName(null)->name);
        self::assertNull($event->withId(null)->id);
        self::assertNull($event->withRetry(null)->retry);
    }

    /**
     * @param array{0?: string|null, 1?: string, 2?: string|null, 3?: float|null} $arguments
     */
    #[DataProvider('provideInvalidEvents')]
    public function testRejectsInvalidValues(array $arguments, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new Event(...$arguments);
    }

    /**
     * @return iterable<string, array{array{0?: string|null, 1?: string, 2?: string|null, 3?: float|null}, string}>
     */
    public static function provideInvalidEvents(): iterable
    {
        yield 'name with line feed' => [["a\nb"], 'event name'];
        yield 'name with carriage return' => [["a\rb"], 'event name'];
        yield 'id with line feed' => [[null, '', "1\n2"], 'event id'];
        yield 'id with carriage return' => [[null, '', "1\r2"], 'event id'];
        yield 'id with NUL' => [[null, '', "1\0"], 'event id'];
        yield 'negative retry' => [[null, '', null, -1], 'retry'];
    }

    public function testDataCanContainLineBreaks(): void
    {
        self::assertSame("a\nb", (new Event(null, "a\nb"))->data);
    }

    public function testRetryCanBeZero(): void
    {
        self::assertSame(0.0, (new Event(retry: 0))->retry);
    }
}
