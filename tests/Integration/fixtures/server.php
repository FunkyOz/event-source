<?php

declare(strict_types=1);

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

require __DIR__ . '/../../../vendor/autoload.php';

$marker = is_string($_GET['marker'] ?? null) ? $_GET['marker'] : null;

switch ($_GET['case'] ?? '') {
    case 'events':
        EventStream::create()
            ->header('Access-Control-Allow-Origin', '*')
            ->onStart(fn(Stream $stream) => $stream->send(new Event('resume', (string) $stream->lastEventId())))
            ->onTick(function (Stream $stream): void {
                $stream->send(new Event('tick', (string) $stream->iteration(), (string) $stream->iteration()));
                if (3 === $stream->iteration()) {
                    $stream->stop();
                }
            })
            ->every(0.05)
            ->send();
        break;

    case 'endless':
        EventStream::create()
            ->every(0.05)
            ->onTick(fn(Stream $stream) => $stream->send(new Event('tick')))
            ->onStop(function (Stream $stream) use ($marker): void {
                file_put_contents((string) $marker, (string) $stream->stopReason()?->name);
            })
            ->send();
        break;

    case 'nested-buffers':
        // Like output_buffering in php.ini plus a buffer opened by a framework or a plugin
        ob_start();
        ob_start();
        EventStream::create()
            ->every(0.05)
            ->onTick(fn(Stream $stream) => $stream->send(new Event('tick', (string) $stream->iteration())))
            ->send();
        break;

    case 'protected-buffer':
        // A buffer that can't be removed or flushed, below a normal one. Errors are written to the response, to be seen
        set_error_handler(function (int $level, string $message): bool {
            echo "error: {$message}\n";

            return true;
        });
        ob_start(null, 0, 0);
        ob_start();
        EventStream::create()
            ->every(0.05)
            ->onTick(function (Stream $stream): void {
                $stream->send(new Event('tick', (string) $stream->iteration()));
                if (2 === $stream->iteration()) {
                    $stream->stop();
                }
            })
            ->send();
        break;

    case 'no-content':
        EventStream::sendNoContent();
        break;

    case 'output-already-sent':
        echo 'too early';
        flush();
        try {
            EventStream::create()->send();
        } catch (\LogicException $e) {
            echo "\n", $e->getMessage();
        }
        break;
}
