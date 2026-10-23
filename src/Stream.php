<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

use FunkyOz\EventSource\Internal\StreamState;

/**
 * A running stream, given to every listener
 */
final readonly class Stream
{
    /**
     * @internal Created by the library for each run
     */
    public function __construct(
        private Output $output,
        private Clock $clock,
        private StreamState $state,
    ) {}

    /**
     * Send an event to the client immediately
     */
    public function send(Event $event): void
    {
        $this->write(EventFormatter::format($event));
        if (null !== $event->id) {
            $this->state->lastEventId = $event->id;
        }
    }

    /**
     * Send a comment, which the browser ignores
     */
    public function comment(string $text = ''): void
    {
        $this->write(EventFormatter::comment($text));
    }

    /**
     * End the stream after the current listener returns
     */
    public function stop(): void
    {
        $this->state->stopRequested = true;
    }

    public function isStopped(): bool
    {
        return $this->state->stopRequested;
    }

    /**
     * The Last-Event-ID sent by the browser at first, then the id of the last event sent
     */
    public function lastEventId(): ?string
    {
        return $this->state->lastEventId;
    }

    /**
     * 0 in start listeners, then 1, 2, 3… for each tick or generated event
     */
    public function iteration(): int
    {
        return $this->state->iteration;
    }

    /**
     * Seconds since the stream started
     */
    public function elapsed(): float
    {
        return $this->clock->now() - $this->state->startedAt;
    }

    /**
     * Why the stream ended. Set when the stop listeners run
     */
    public function stopReason(): ?StopReason
    {
        return $this->state->stopReason;
    }

    private function write(string $chunk): void
    {
        if ($this->state->closed) {
            throw new \LogicException('The stream has ended, nothing can be sent anymore.');
        }
        $this->output->write($chunk);
        $this->output->flush();
        $this->state->lastWriteAt = $this->clock->now();
    }
}
