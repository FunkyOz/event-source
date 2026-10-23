<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Integration;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\Testing\EventParser;
use FunkyOz\EventSource\Tests\Support\BuiltInServer;
use PHPUnit\Framework\TestCase;

/**
 * Sends streams with EventStream::send() through PHP's built-in server, over real HTTP connections
 */
class NativeServerTest extends TestCase
{
    use BuiltInServer;

    public static function setUpBeforeClass(): void
    {
        self::startServer([__DIR__ . '/fixtures/server.php']);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    public function testSendsHeadersAndEvents(): void
    {
        [$status, $headers, $body] = $this->request('GET', '/?case=events', ['Last-Event-ID: 41']);

        self::assertSame(200, $status);
        self::assertStringStartsWith('text/event-stream', $headers['content-type'] ?? '');
        self::assertSame('no-cache', $headers['cache-control'] ?? null);
        self::assertSame('no', $headers['x-accel-buffering'] ?? null);
        self::assertSame('*', $headers['access-control-allow-origin'] ?? null);
        self::assertEquals([
            new Event('resume', '41'),
            new Event('tick', '1', '1'),
            new Event('tick', '2', '2'),
            new Event('tick', '3', '3'),
        ], EventParser::parse($body)->events);
    }

    public function testSendNoContent(): void
    {
        [$status, , $body] = $this->request('GET', '/?case=no-content');

        self::assertSame(204, $status);
        self::assertSame('', $body);
    }

    public function testFailsWhenOutputWasAlreadySent(): void
    {
        [, , $body] = $this->request('GET', '/?case=output-already-sent');

        self::assertStringContainsString('Cannot start the stream: output was already sent', $body);
    }

    public function testEventsArriveWhileTheStreamRunsDespiteNestedOutputBuffers(): void
    {
        // The stream never ends, so the event can only arrive if it isn't held in an output buffer
        $connection = $this->open('GET', '/?case=nested-buffers');
        try {
            $this->readUntil($connection, "event: tick\ndata: 1\n\n");
        } finally {
            fclose($connection);
        }
    }

    public function testABufferThatCannotBeFlushedIsLeftAlone(): void
    {
        [, , $body] = $this->request('GET', '/?case=protected-buffer');

        // The buffer holds the events until the request ends, without errors
        self::assertStringNotContainsString('error:', $body);
        self::assertEquals([new Event('tick', '1'), new Event('tick', '2')], EventParser::parse($body)->events);
    }

    public function testStopListenersRunWhenTheClientDisconnects(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'event-source-');
        self::assertIsString($marker);
        try {
            $connection = $this->open('GET', '/?case=endless&marker=' . urlencode($marker));
            // Wait for the first event, then leave
            $this->readUntil($connection, "event: tick\n");
            fclose($connection);

            $deadline = microtime(true) + 5;
            while ('' === (string) file_get_contents($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertSame('ClientDisconnected', file_get_contents($marker));
        } finally {
            @unlink($marker);
        }
    }

}
