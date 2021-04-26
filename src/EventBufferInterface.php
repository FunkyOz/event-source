<?php
declare(strict_types=1);

namespace EventSource;

/**
 * Interface EventSourceBufferInterface
 * @package EventSource
 *
 * Interface to implement write. Using EventSourceBuffer for default
 */
interface EventBufferInterface
{
    /**
     * @param Event $event
     */
    public function write(Event $event): void;
}
