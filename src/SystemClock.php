<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
