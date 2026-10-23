<?php

declare(strict_types=1);

/*
 * Counts to 10, one tick per second. Every 4 seconds the stream ends (maxDuration), and the browser reconnects on
 * its own, sending the id of the last tick it received in the Last-Event-ID header: the count resumes from there.
 */

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/../bootstrap.php';

EventStream::create()
    ->maxDuration(4)
    // Ask the browser to wait 2 seconds before reconnecting
    ->retry(2)
    ->heartbeat(15)
    ->onStart(function (Stream $stream): void {
        $stream->send(new Event('hello', 'Streaming from tick ' . ($stream->lastEventId() ?? '0')));
    })
    ->onTick(function (Stream $stream): void {
        // lastEventId() is the Last-Event-ID header at first, then the id of the last event sent
        $tick = (int) $stream->lastEventId() + 1;
        $stream->send(Event::json('tick', ['tick' => $tick, 'time' => date('H:i:s')], (string) $tick));

        if ($tick >= 10) {
            $stream->stop();
        }
    })
    ->onStop(function (Stream $stream): void {
        // Only when the count is over: after maxDuration, the browser must reconnect
        if (StopReason::Stopped === $stream->stopReason()) {
            $stream->send(new Event('done', "Multi-line data works too:\nthe count is over"));
        }
    })
    ->send();
