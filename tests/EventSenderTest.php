<?php

declare(strict_types=1);

namespace Test;

use EventSource\EventSender;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

class EventSenderTest extends TestCase
{
    private $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = new EventSender;
    }

    public function test_add_start_listener()
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addStartListener(
                function () {
                }
            );
        }

        $listeners = $this->getListenersProperty();
        self::assertCount($totals, $listeners[EventSender::ON_START]);
    }

    public function test_add_write_listener()
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addWriteListener(
                function () {
                }
            );
        }

        $listeners = $this->getListenersProperty();
        self::assertCount($totals, $listeners[EventSender::ON_WRITE]);
    }

    public function test_add_stop_listener()
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addStopListener(
                function () {
                }
            );
        }

        $listeners = $this->getListenersProperty();
        self::assertCount($totals, $listeners[EventSender::ON_STOP]);
    }

    public function test_send()
    {
        $startListener = $writeListener = $stopListener = false;
        $this->sender->addStartListener(
            function () use (&$startListener) {
                $startListener = true;
            }
        );
        $this->sender->addWriteListener(
            function () use (&$writeListener) {
                $writeListener = true;

                // Return true for stop loop.
                return true;
            }
        );
        $this->sender->addStopListener(
            function () use (&$stopListener) {
                $stopListener = true;
            }
        );

        $this->sender->send();

        self::assertTrue($startListener);
        self::assertTrue($writeListener);
        self::assertTrue($stopListener);

        $listeners = $this->getListenersProperty();
        self::assertEmpty($listeners[EventSender::ON_START]);
        self::assertEmpty($listeners[EventSender::ON_WRITE]);
        self::assertEmpty($listeners[EventSender::ON_STOP]);
    }

    /**
     * @return array
     * @throws ReflectionException
     */
    private function getListenersProperty(): array
    {
        $reflection = new ReflectionClass(get_class($this->sender));
        $property = $reflection->getProperty('listeners');
        $property->setAccessible(true);

        return $property->getValue($this->sender);
    }
}
