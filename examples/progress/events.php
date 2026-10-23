<?php

declare(strict_types=1);

/*
 * A generator stream: the task runs inside the request, and reports its progress as it goes. If the user leaves,
 * the generator isn't resumed, so the task stops.
 */

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/../bootstrap.php';

const STEPS = 20;

EventStream::fromGenerator(function (Stream $stream) {
    $started = microtime(true);

    for ($step = 1; $step <= STEPS; $step++) {
        // The work of one step: here, just waiting
        usleep(isFastMode() ? 0 : 150_000);

        yield Event::json('progress', ['step' => $step, 'total' => STEPS, 'percent' => intdiv($step * 100, STEPS)]);
    }

    yield Event::json('done', ['seconds' => round(microtime(true) - $started, 1)]);
})
    ->maxDuration(30)
    ->send();
