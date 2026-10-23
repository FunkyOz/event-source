<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

/**
 * Writes to a PHP stream resource, e.g. a file or php://stdout. The resource is not closed
 */
final class StreamOutput implements Output
{
    /**
     * @var resource
     */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('The stream must be an open resource.');
        }
        $this->stream = $stream;
    }

    public function open(): void {}

    public function write(string $chunk): void
    {
        fwrite($this->stream, $chunk);
    }

    public function flush(): void
    {
        fflush($this->stream);
    }

    public function isConnectionAborted(): bool
    {
        return false;
    }

    public function close(): void
    {
        $this->flush();
    }
}
