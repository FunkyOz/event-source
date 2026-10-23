<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Unit\Testing;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\Testing\EventParser;
use PHPUnit\Framework\TestCase;

class EventParserTest extends TestCase
{
    public function testParsesEventsLikeABrowser(): void
    {
        $parsed = EventParser::parse(
            "retry: 3000\n\n"
            . ": a comment\n"
            . ":no space\n"
            . "event: tick\nid: 7\nretry: 1500\ndata: one\ndata:two\n\n"
            . "data: unnamed\r\n\r\n"
            . "event: no-data\n\n"
            . "unknown: field\nfield-without-colon\ndata: x\n\n"
            . "id: bad\0id\nretry: 1.5\ndata: y\n\n"
            . "data: incomplete event",
        );

        self::assertEquals([
            new Event('tick', "one\ntwo", '7', 1.5),
            new Event(null, 'unnamed'),
            new Event(null, 'x'),
            new Event(null, 'y'),
        ], $parsed->events);
        self::assertSame(['a comment', 'no space'], $parsed->comments);
        self::assertSame(1.5, $parsed->retry);
    }

    public function testEmptyStream(): void
    {
        $parsed = EventParser::parse('');

        self::assertSame([], $parsed->events);
        self::assertSame([], $parsed->comments);
        self::assertNull($parsed->retry);
    }
}
