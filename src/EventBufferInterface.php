<?php
declare(strict_types=1);

namespace EventSource;

/**
 * Interface EventSourceBufferInterface
 * @package EventSource
 *
 * Interface to implement write. Using EventSourceBuffer for default
 */
interface EventSourceBufferInterface
{
    /**
     * @param EventSource $event
     */
    public function write(EventSource $event): void;
}
