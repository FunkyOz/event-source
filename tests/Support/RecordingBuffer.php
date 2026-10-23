<?php

declare(strict_types=1);

namespace Test\Support;

use EventSource\Event;
use EventSource\EventBufferInterface;

/**
 * Keeps the name of every written event instead of sending it
 */
class RecordingBuffer implements EventBufferInterface
{
    /**
     * @var array<string|null>
     */
    public array $eventNames = [];

    public function write(Event $event, bool $flush = false): void
    {
        $this->eventNames[] = $event->getEventName();
    }
}
