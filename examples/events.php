<?php

declare(strict_types=1);

/*
 * Streams a "tick" event every second, then a "done" event after 10 ticks.
 * When the browser reconnects it sends the last received id in the Last-Event-ID header,
 * so the counter resumes from there instead of starting again.
 */

use EventSource\Event;
use EventSource\EventBufferInterface;
use EventSource\EventSender;

require __DIR__ . '/../vendor/autoload.php';

$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
$counter = is_numeric($lastEventId) ? (int) $lastEventId : 0;

$sender = new EventSender();
$sender->addStartListener(
    function (EventBufferInterface $buffer) use ($counter): void {
        // Ask the browser to wait 2 seconds before reconnecting
        $buffer->write(new Event('hello', "Streaming from tick {$counter}", null, 2));
    },
);
$sender->addWriteListener(
    function (EventBufferInterface $buffer) use (&$counter): bool {
        $counter++;
        $data = json_encode(['tick' => $counter, 'time' => date('H:i:s')], JSON_THROW_ON_ERROR);
        $buffer->write(new Event('tick', $data, (string) $counter));

        // Returning true stops the stream
        return $counter >= 10;
    },
);
$sender->addStopListener(
    function (EventBufferInterface $buffer): void {
        $buffer->write(new Event('done', "Multi-line data works too:\nthe stream is over"));
    },
);

$sender->send();
