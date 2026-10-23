<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Internal;

use FunkyOz\EventSource\StopReason;

/**
 * State of one run, shared by the runner and the Stream given to listeners
 *
 * @internal
 */
final class StreamState
{
    public int $iteration = 0;
    public bool $stopRequested = false;
    public bool $closed = false;
    public ?StopReason $stopReason = null;
    public float $lastWriteAt;

    public function __construct(
        public ?string $lastEventId,
        public readonly float $startedAt,
    ) {
        $this->lastWriteAt = $startedAt;
    }
}
