<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

/**
 * Where a stream is written to. Implemented by adapters and tests
 */
interface Output
{
    /**
     * Called once, before anything is written
     */
    public function open(): void;

    public function write(string $chunk): void;

    /**
     * Send what was written to the client
     */
    public function flush(): void;

    /**
     * Whether the client has disconnected. Only reliable after a flush
     */
    public function isConnectionAborted(): bool;

    /**
     * Called once at the end, also after an error
     */
    public function close(): void;
}
