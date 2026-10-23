<?php

declare(strict_types=1);

namespace Test;

use EventSource\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testDefaults(): void
    {
        $event = new Event();

        self::assertSame('message', $event->getEventName());
        self::assertNull($event->getData());
        self::assertNull($event->getId());
        self::assertNull($event->getRetry());
    }

    public function testSetters(): void
    {
        $event = new Event();
        $event->setEventName('update');
        $event->setData('payload');
        $event->setId('42');
        $event->setRetry(5);

        self::assertSame('update', $event->getEventName());
        self::assertSame('payload', $event->getData());
        self::assertSame('42', $event->getId());
        self::assertSame(5, $event->getRetry());

        $event->setEventName(null);
        self::assertNull($event->getEventName());
    }
}
