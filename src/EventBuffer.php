<?php

declare(strict_types=1);

namespace EventSource;

class EventBuffer implements EventBufferInterface
{
    /**
     * @param Event $event
     */
    public function write(Event $event): void
    {
        $eventName = $event->getEventName();
        $data = $event->getData();
        $id = $event->getId();
        $retry = $event->getRetry();
        if (null !== $eventName) {
            echo 'event: ' . $eventName . PHP_EOL;
        }
        echo 'data: ' . $data . PHP_EOL . PHP_EOL;
        if (null !== $id) {
            echo 'id: ' . $id . PHP_EOL;
        }
        if (null !== $retry) {
            echo 'retry: ' . ($retry * 1000) . PHP_EOL;
        }
    }
}
