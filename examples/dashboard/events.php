<?php

declare(strict_types=1);

/*
 * Sends the server's metrics when they change. Load averages change every few seconds, so most ticks send nothing;
 * heartbeats keep the connection open in between. See them with: curl -N http://localhost:8000/dashboard/events.php
 */

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/../bootstrap.php';

/**
 * @return array{load: list<float>|null, disk_free_gb: float|null, php: string}
 */
function metrics(): array
{
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $free = disk_free_space(sys_get_temp_dir());

    return [
        'load' => false === $load ? null : array_map(static fn(float $value): float => round($value, 2), $load),
        'disk_free_gb' => false === $free ? null : round($free / 1e9, 1),
        'php' => PHP_VERSION,
    ];
}

$previous = null;

EventStream::create()
    ->every(1)
    ->heartbeat(5)
    ->maxDuration(300)
    ->onTick(function (Stream $stream) use (&$previous): void {
        $metrics = metrics();
        if ($metrics === $previous) {
            return;
        }
        $previous = $metrics;
        $stream->send(Event::json('metrics', $metrics + ['changed_at' => date('H:i:s')]));
    })
    ->send();
