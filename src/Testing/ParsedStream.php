<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Testing;

use FunkyOz\EventSource\Event;

/**
 * What a browser would read from a stream
 */
final readonly class ParsedStream
{
    /**
     * @param list<Event> $events
     * @param list<string> $comments
     * @param float|null $retry The last reconnection time received, in seconds
     */
    public function __construct(
        public array $events,
        public array $comments,
        public ?float $retry,
    ) {}
}
