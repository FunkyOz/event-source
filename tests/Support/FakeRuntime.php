<?php

declare(strict_types=1);

namespace Test\Support;

use EventSource\RuntimeInterface;

/**
 * Records flushes and sleeps instead of touching the PHP process,
 * and can simulate a client disconnection after a given number of flushes
 */
class FakeRuntime implements RuntimeInterface
{
    public int $flushes = 0;

    /**
     * @var float[]
     */
    public array $sleeps = [];

    public function __construct(private readonly ?int $abortAfterFlushes = null) {}

    public function flush(): void
    {
        $this->flushes++;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    public function isConnectionAborted(): bool
    {
        return null !== $this->abortAfterFlushes && $this->flushes >= $this->abortAfterFlushes;
    }
}
