<?php

declare(strict_types=1);

namespace EventSource;

/**
 * Interface RuntimeInterface
 * @package EventSource
 *
 * Interaction with the PHP process while streaming. Using PhpRuntime for default
 */
interface RuntimeInterface
{
    /**
     * Send buffered output to the client
     */
    public function flush(): void;

    public function sleep(float $seconds): void;

    /**
     * Whether the client has disconnected. Only reliable after a flush
     */
    public function isConnectionAborted(): bool;
}
