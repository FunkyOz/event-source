<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

interface Clock
{
    /**
     * Current time in seconds, from a monotonic clock
     */
    public function now(): float;

    public function sleep(float $seconds): void;
}
