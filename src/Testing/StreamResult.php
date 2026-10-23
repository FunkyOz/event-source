<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Testing;

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\StopReason;

/**
 * What a stream run by StreamTester produced
 */
final readonly class StreamResult
{
    /**
     * @param list<float> $sleeps
     */
    public function __construct(
        private ParsedStream $parsed,
        private StopReason $stopReason,
        private array $sleeps,
        private int $iterations,
        private string $output,
    ) {}

    /**
     * @param string|null $name Only the events with this name, or all of them when null
     * @return list<Event>
     */
    public function events(?string $name = null): array
    {
        if (null === $name) {
            return $this->parsed->events;
        }

        return array_values(array_filter($this->parsed->events, static fn(Event $event): bool => $event->name === $name));
    }

    /**
     * @return list<string> the comments sent, including heartbeats (empty comments)
     */
    public function comments(): array
    {
        return $this->parsed->comments;
    }

    /**
     * The last reconnection time sent, in seconds
     */
    public function retry(): ?float
    {
        return $this->parsed->retry;
    }

    public function stopReason(): StopReason
    {
        return $this->stopReason;
    }

    /**
     * @return list<float> the durations the stream slept for
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    /**
     * The raw text/event-stream output
     */
    public function output(): string
    {
        return $this->output;
    }
}
