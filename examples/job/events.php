<?php

declare(strict_types=1);

/*
 * Follows a job that runs somewhere else. When the job is over, or unknown, the response is 204 No Content: the
 * browser then stops reconnecting.
 */

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Examples\Job\JobStore;
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/../bootstrap.php';

$jobs = new JobStore(exampleDatabase());
$jobId = is_string($_GET['job'] ?? null) ? $_GET['job'] : '';

$progress = $jobs->progress($jobId);
if (null === $progress || 100 === $progress) {
    EventStream::sendNoContent();
    exit;
}

$lastSent = null;

EventStream::create()
    ->every(0.25)
    ->heartbeat(15)
    ->maxDuration(60)
    ->retry(2)
    ->onTick(function (Stream $stream) use ($jobs, $jobId, &$lastSent): void {
        $progress = (int) $jobs->progress($jobId);

        // Only when it changed, so a slow job doesn't flood the browser
        if ($progress !== $lastSent) {
            $lastSent = $progress;
            $stream->send(Event::json('progress', ['percent' => $progress]));
        }

        if (100 === $progress) {
            $stream->stop();
        }
    })
    ->onStop(function (Stream $stream): void {
        if (StopReason::Stopped === $stream->stopReason()) {
            $stream->send(new Event('done'));
        }
    })
    ->send();
