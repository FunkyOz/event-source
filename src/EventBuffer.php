<?php

declare(strict_types=1);

namespace EventSource;

class EventSourceBuffer implements EventSourceBufferInterface
{
    /**
     * @param EventSource $event
     */
    public function write(EventSource $event): void
    {
        $evt = $event->getEvent();
        $data = $event->getData();
        $id = $event->getId();
        $retry = $event->getRetry();
        if (null !== $evt) {
            echo 'event: ' . $evt . PHP_EOL;
        }
        echo 'data: ' . json_encode($data) . PHP_EOL . PHP_EOL;
        if (null !== $id) {
            echo 'id: ' . $id . PHP_EOL;
        }
        if (null !== $retry) {
            echo 'retry: ' . ($retry * 1000) . PHP_EOL;
        }
    }
}
