<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Testing;

use FunkyOz\EventSource\Clock;

/**
 * A clock that moves forward on sleep() instead of waiting
 */
final class FakeClock implements Clock
{
    /**
     * @var list<float>
     */
    private array $sleeps = [];

    public function __construct(private float $now = 0.0) {}

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->advance($seconds);
    }

    /**
     * Move time forward, e.g. to simulate a slow listener
     */
    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    /**
     * @return list<float> every duration passed to sleep()
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
