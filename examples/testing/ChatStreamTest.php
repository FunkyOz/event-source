<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Examples\Testing;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\Examples\Chat\ChatStream;
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Stream;
use FunkyOz\EventSource\Testing\StreamTester;
use PHPUnit\Framework\TestCase;

/**
 * How to test a stream: StreamTester runs it in memory, without waiting, and returns what the browser received.
 * This test runs with the library's own tests
 */
final class ChatStreamTest extends TestCase
{
    private ChatStream $chat;

    protected function setUp(): void
    {
        $this->chat = new ChatStream(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
    }

    public function testTheFirstConnectionGetsTheLatestMessages(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->chat->post('Ada', "Message {$i}");
        }

        $result = StreamTester::for($this->chat->create())->disconnectAfter(1)->run();

        self::assertCount(ChatStream::HISTORY, $result->events('chat'));
        self::assertSame('Message 6', $this->text($result->events()[0]));
        self::assertSame('Message 25', $this->text($result->events()[19]));
    }

    public function testAReconnectionGetsOnlyWhatItMissed(): void
    {
        $this->chat->post('Ada', 'First');
        $this->chat->post('Bob', 'Second');
        $this->chat->post('Ada', 'Third');

        // The browser received message 1 before the connection dropped
        $result = StreamTester::for($this->chat->create())->withLastEventId('1')->disconnectAfter(1)->run();

        self::assertSame(['2', '3'], array_map(static fn(Event $event): ?string => $event->id, $result->events()));
    }

    public function testNewMessagesArriveWhileConnected(): void
    {
        // Listeners can be added to a stream: this one posts a message during the first tick
        $stream = $this->chat->create()->onTick(function (Stream $stream): void {
            if (1 === $stream->iteration()) {
                $this->chat->post('Bob', 'Hello!');
            }
        });

        $result = StreamTester::for($stream)->disconnectAfter(2)->run();

        self::assertSame(['Hello!'], array_map($this->text(...), $result->events('chat')));
        // Two ticks, half a second apart
        self::assertSame([0.5], $result->sleeps());
    }

    public function testTheStreamRenewsItselfAfterTwoMinutes(): void
    {
        $result = StreamTester::for($this->chat->create())->run();

        self::assertSame(StopReason::MaxDurationReached, $result->stopReason());
        self::assertEqualsWithDelta(120, array_sum($result->sleeps()), 0.001);
        // Nothing to say for two minutes: heartbeats every 15 seconds keep the connection open
        self::assertCount(7, $result->comments());
    }

    public function testRejectsEmptyMessages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chat->post('Ada', '   ');
    }

    private function text(Event $event): string
    {
        $message = json_decode($event->data, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($message);
        self::assertIsString($message['text']);

        return $message['text'];
    }
}
