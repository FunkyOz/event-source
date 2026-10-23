<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Unit;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EventFormatterTest extends TestCase
{
    #[DataProvider('provideEvents')]
    public function testFormat(Event $event, string $expected): void
    {
        self::assertSame($expected, EventFormatter::format($event));
    }

    /**
     * @return iterable<string, array{Event, string}>
     */
    public static function provideEvents(): iterable
    {
        yield 'named' => [new Event('tick', 'hello'), "event: tick\ndata: hello\n\n"];
        yield 'unnamed' => [new Event(null, 'hello'), "data: hello\n\n"];
        yield 'empty data still has a data field' => [new Event('done'), "event: done\ndata: \n\n"];
        yield 'all fields, data last' => [new Event('tick', 'hello', '42', 3), "event: tick\nid: 42\nretry: 3000\ndata: hello\n\n"];
        yield 'retry in milliseconds, rounded up' => [new Event(null, 'x', null, 1.2345), "retry: 1235\ndata: x\n\n"];
        yield 'retry in milliseconds, rounded down' => [new Event(null, 'x', null, 1.2341), "retry: 1234\ndata: x\n\n"];
        yield 'retry zero' => [new Event(null, 'x', null, 0), "retry: 0\ndata: x\n\n"];
        yield 'empty id resets the last event id' => [new Event(null, 'x', ''), "id: \ndata: x\n\n"];
        yield 'multi-line data, every line break style' => [
            new Event(null, "one\ntwo\r\nthree\rfour"),
            "data: one\ndata: two\ndata: three\ndata: four\n\n",
        ];
        yield 'trailing line break' => [new Event(null, "one\n"), "data: one\ndata: \n\n"];
    }

    #[DataProvider('provideComments')]
    public function testComment(string $text, string $expected): void
    {
        self::assertSame($expected, EventFormatter::comment($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideComments(): iterable
    {
        yield 'empty' => ['', ":\n"];
        yield 'text' => ['keep-alive', ": keep-alive\n"];
        yield 'multi-line' => ["one\r\ntwo", ": one\n: two\n"];
    }

    public function testRetry(): void
    {
        self::assertSame("retry: 2500\n\n", EventFormatter::retry(2.5));
    }
}
