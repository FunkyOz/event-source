<?php

declare(strict_types=1);

/*
 * Fails on the third tick, like a database going away. The error listener tells the browser, the stop listener
 * runs anyway, and the browser reconnects after the retry delay.
 */

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/../bootstrap.php';

EventStream::create()
    ->retry(3)
    ->onTick(function (Stream $stream): void {
        $stream->send(new Event('tick', (string) $stream->iteration()));

        if (3 === $stream->iteration()) {
            throw new RuntimeException('The database went away');
        }
    })
    // Without an error listener, the exception would be rethrown, after the stop listeners
    ->onError(function (Throwable $e, Stream $stream): void {
        error_log('Stream failed: ' . $e->getMessage());
        // Not named "error": EventSource fires an "error" event of its own when the connection fails
        $stream->send(new Event('server-error', $e->getMessage()));
    })
    ->onStop(function (Stream $stream): void {
        $stream->send(new Event('stopped', (string) $stream->stopReason()?->name));
    })
    ->send();
