<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Testing;

use FunkyOz\EventSource\Output;

/**
 * Keeps everything written in memory, and can simulate a client disconnection
 */
final class MemoryOutput implements Output
{
    private string $contents = '';
    private int $flushes = 0;
    private bool $opened = false;
    private bool $closed = false;
    private bool $aborted = false;

    /**
     * @var (\Closure(): bool)|null
     */
    private ?\Closure $disconnectWhen = null;

    public function open(): void
    {
        $this->opened = true;
    }

    public function write(string $chunk): void
    {
        $this->contents .= $chunk;
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    public function isConnectionAborted(): bool
    {
        if (!$this->aborted && $this->disconnectWhen instanceof \Closure) {
            $this->aborted = ($this->disconnectWhen)();
        }

        return $this->aborted;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * Simulate a client disconnection
     */
    public function disconnect(): void
    {
        $this->aborted = true;
    }

    /**
     * Simulate a client disconnection as soon as the condition is true
     *
     * @param callable(): bool $condition
     */
    public function disconnectWhen(callable $condition): void
    {
        $this->disconnectWhen = $condition(...);
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function parse(): ParsedStream
    {
        return EventParser::parse($this->contents);
    }

    public function flushes(): int
    {
        return $this->flushes;
    }

    public function wasOpened(): bool
    {
        return $this->opened;
    }

    public function wasClosed(): bool
    {
        return $this->closed;
    }
}
