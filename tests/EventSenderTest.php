<?php

declare(strict_types=1);

namespace Test;

use EventSource\EventSender;
use PHPUnit\Framework\TestCase;

class EventSenderTest extends TestCase
{
    private EventSender $sender;

    public function testAddStartListener(): void
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addStartListener(
                function () {
                }
            );
        }

        self::assertCount($totals, $this->sender->getListeners()[EventSender::ON_START]);
    }

    public function testAddWriteListener(): void
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addWriteListener(
                function () {
                }
            );
        }

        self::assertCount($totals, $this->sender->getListeners()[EventSender::ON_WRITE]);
    }

    public function testAddStopListener(): void
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addStopListener(
                function () {
                }
            );
        }

        self::assertCount($totals, $this->sender->getListeners()[EventSender::ON_STOP]);
    }

    public function testSend(): void
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

        self::assertEmpty($this->sender->getListeners()[EventSender::ON_START]);
        self::assertEmpty($this->sender->getListeners()[EventSender::ON_WRITE]);
        self::assertEmpty($this->sender->getListeners()[EventSender::ON_STOP]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = new EventSender;
    }
}
