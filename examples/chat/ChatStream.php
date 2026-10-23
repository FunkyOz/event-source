<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Examples\Chat;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

/**
 * A chat room stored in a database. Building the stream in a class makes it easy to test: see
 * examples/testing/ChatStreamTest.php
 */
final readonly class ChatStream
{
    public const HISTORY = 20;

    public function __construct(private \PDO $database)
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                author TEXT NOT NULL,
                text TEXT NOT NULL,
                sent_at TEXT NOT NULL
            )',
        );
    }

    /**
     * @throws \InvalidArgumentException when the message is empty or too long
     */
    public function post(string $author, string $text): void
    {
        $author = trim($author);
        $text = trim($text);
        if ('' === $author || '' === $text || mb_strlen($author) > 30 || mb_strlen($text) > 500) {
            throw new \InvalidArgumentException('A message needs an author of up to 30 characters, and a text of up to 500.');
        }

        $this->database
            ->prepare('INSERT INTO messages (author, text, sent_at) VALUES (:author, :text, :sent_at)')
            ->execute(['author' => $author, 'text' => $text, 'sent_at' => date('H:i:s')]);
    }

    public function create(): EventStream
    {
        $newer = $this->database->prepare('SELECT id, author, text, sent_at FROM messages WHERE id > :after ORDER BY id LIMIT 100');
        $newer->setFetchMode(\PDO::FETCH_ASSOC);

        return EventStream::create()
            ->every(0.5)
            ->heartbeat(15)
            ->maxDuration(120)
            ->retry(1)
            ->onStart(function (Stream $stream): void {
                // First connection: send the latest messages. After a reconnection, the browser sends Last-Event-ID,
                // and the tick listener sends only what it missed
                if (null === $stream->lastEventId()) {
                    $history = $this->database->query(
                        'SELECT * FROM (SELECT id, author, text, sent_at FROM messages ORDER BY id DESC LIMIT ' . self::HISTORY . ') ORDER BY id',
                        \PDO::FETCH_ASSOC,
                    );
                    foreach ($history ?: [] as $message) {
                        $stream->send($this->event($message));
                    }
                }
            })
            ->onTick(function (Stream $stream) use ($newer): void {
                // lastEventId() follows the ids sent, so this returns only the new messages
                $newer->execute(['after' => (int) $stream->lastEventId()]);
                foreach ($newer as $message) {
                    $stream->send($this->event($message));
                }
            });
    }

    /**
     * @param mixed $message A row of the messages table
     */
    private function event(mixed $message): Event
    {
        if (!is_array($message) || !is_scalar($message['id'] ?? null)) {
            throw new \UnexpectedValueException('Invalid message row.');
        }

        return Event::json('chat', $message, id: (string) $message['id']);
    }
}
