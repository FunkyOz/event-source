<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Integration;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\Testing\EventParser;
use FunkyOz\EventSource\Tests\Support\BuiltInServer;
use PHPUnit\Framework\TestCase;

/**
 * Runs every example in examples/ through PHP's built-in server, so they keep working
 */
class ExamplesTest extends TestCase
{
    use BuiltInServer;

    private static string $database;

    public static function setUpBeforeClass(): void
    {
        self::$database = (string) tempnam(sys_get_temp_dir(), 'event-source-examples-');
    }

    /**
     * A new server for every test: a stream left open by a test would keep the single server process busy until it
     * notices the disconnection, at its next write
     */
    protected function setUp(): void
    {
        self::startServer(['-t', __DIR__ . '/../../examples'], [
            'EXAMPLES_DATABASE' => self::$database,
            // No waiting in the examples that simulate work
            'EXAMPLES_FAST' => '1',
        ]);
    }

    protected function tearDown(): void
    {
        self::stopServer();
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$database, self::$database . '-wal', self::$database . '-shm'] as $file) {
            @unlink($file);
        }
    }

    public function testEveryPageLoads(): void
    {
        foreach (['/', '/counter/', '/chat/', '/progress/', '/job/', '/dashboard/', '/errors/', '/style.css'] as $path) {
            [$status] = $this->request('GET', $path);
            self::assertSame(200, $status, $path);
        }
    }

    public function testCounterResumesFromTheLastEventId(): void
    {
        [$status, $headers, $body] = $this->request('GET', '/counter/events.php', ['Last-Event-ID: 8']);

        self::assertSame(200, $status);
        self::assertStringStartsWith('text/event-stream', $headers['content-type'] ?? '');
        $parsed = EventParser::parse($body);
        self::assertSame(2.0, $parsed->retry);
        self::assertSame(['hello', 'tick', 'tick', 'done'], array_map(static fn(Event $event): ?string => $event->name, $parsed->events));
        self::assertSame('Streaming from tick 8', $parsed->events[0]->data);
        self::assertSame(['9', '10'], [$parsed->events[1]->id, $parsed->events[2]->id]);
    }

    public function testChatSendsPostedMessages(): void
    {
        [$status] = $this->request('POST', '/chat/send.php', ['Content-Type: application/x-www-form-urlencoded'], 'author=Ada&text=Hello+everyone');
        self::assertSame(204, $status);

        [$status, , $body] = $this->request('POST', '/chat/send.php', ['Content-Type: application/x-www-form-urlencoded'], 'author=Ada&text=');
        self::assertSame(422, $status);
        self::assertStringContainsString('A message needs', $body);

        $connection = $this->open('GET', '/chat/events.php');
        $received = $this->readUntil($connection, 'Hello everyone');
        fclose($connection);

        $events = EventParser::parse(self::parseResponse($received)[2])->events;
        self::assertSame('chat', $events[0]->name);
        $message = json_decode($events[0]->data, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($message);
        self::assertSame('Ada', $message['author'] ?? null);
    }

    public function testProgressReportsEveryStepThenFinishes(): void
    {
        [, , $body] = $this->request('GET', '/progress/events.php');

        $events = EventParser::parse($body)->events;
        self::assertCount(21, $events);
        self::assertSame('{"step":20,"total":20,"percent":100}', $events[19]->data);
        self::assertSame('done', $events[20]->name);
    }

    public function testJobIsFollowedUntilTheEndThenAnswersNoContent(): void
    {
        [, , $body] = $this->request('POST', '/job/start.php');
        $job = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($job);
        $id = $job['id'] ?? null;
        self::assertIsString($id);

        [$status, , $body] = $this->request('GET', '/job/events.php?job=' . $id);
        self::assertSame(200, $status);
        $events = EventParser::parse($body)->events;
        self::assertSame('done', end($events)->name ?? null);
        self::assertSame('{"percent":100}', $events[count($events) - 2]->data);

        // The job is over: a reconnection gets 204, so the browser stops
        [$status] = $this->request('GET', '/job/events.php?job=' . $id);
        self::assertSame(204, $status);

        [$status] = $this->request('GET', '/job/events.php?job=unknown');
        self::assertSame(204, $status);
    }

    public function testDashboardSendsMetrics(): void
    {
        $connection = $this->open('GET', '/dashboard/events.php');
        // The end of the first event: its JSON data, then the blank line
        $received = $this->readUntil($connection, "}\n\n");
        fclose($connection);

        $metrics = json_decode(EventParser::parse(self::parseResponse($received)[2])->events[0]->data, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($metrics);
        self::assertSame(PHP_VERSION, $metrics['php'] ?? null);
    }

    public function testErrorsAreReportedThenTheStreamStops(): void
    {
        [, , $body] = $this->request('GET', '/errors/events.php');

        $parsed = EventParser::parse($body);
        self::assertSame(3.0, $parsed->retry);
        self::assertEquals([
            new Event('tick', '1'),
            new Event('tick', '2'),
            new Event('tick', '3'),
            new Event('server-error', 'The database went away'),
            new Event('stopped', 'Error'),
        ], $parsed->events);
    }
}
