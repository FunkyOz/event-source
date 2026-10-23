<?php

declare(strict_types=1);

namespace Test;

use EventSource\Event;
use EventSource\EventBufferInterface;
use EventSource\EventSender;
use PHPUnit\Framework\TestCase;
use Test\Support\FakeRuntime;
use Test\Support\RecordingBuffer;

class EventSenderTest extends TestCase
{
    private EventSender $sender;
    private FakeRuntime $runtime;

    public function testAddStartListener(): void
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addStartListener(
                function (): void {},
            );
        }

        self::assertCount($totals, $this->sender->getListeners()[EventSender::ON_START]);
    }

    public function testAddWriteListener(): void
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addWriteListener(
                function (): void {},
            );
        }

        self::assertCount($totals, $this->sender->getListeners()[EventSender::ON_WRITE]);
    }

    public function testAddStopListener(): void
    {
        $totals = mt_rand(1, 10);
        for ($i = 0; $i < $totals; $i++) {
            $this->sender->addStopListener(
                function (): void {},
            );
        }

        self::assertCount($totals, $this->sender->getListeners()[EventSender::ON_STOP]);
    }

    public function testSend(): void
    {
        $startListener = $writeListener = $stopListener = false;
        $this->sender->addStartListener(
            function () use (&$startListener): void {
                $startListener = true;
            },
        );
        $this->sender->addWriteListener(
            function () use (&$writeListener): true {
                $writeListener = true;

                // Return true for stop loop.
                return true;
            },
        );
        $this->sender->addStopListener(
            function () use (&$stopListener): void {
                $stopListener = true;
            },
        );

        $this->sender->send();

        self::assertTrue($startListener);
        self::assertTrue($writeListener);
        self::assertTrue($stopListener);

        self::assertEmpty($this->sender->getListeners()[EventSender::ON_START]);
        self::assertEmpty($this->sender->getListeners()[EventSender::ON_WRITE]);
        self::assertEmpty($this->sender->getListeners()[EventSender::ON_STOP]);
    }

    public function testSendCallsWriteListenersUntilOneReturnsTrue(): void
    {
        $firstCalls = $secondCalls = 0;
        $this->sender->setSleep(0.5);
        $this->sender->addWriteListener(
            function () use (&$firstCalls): bool {
                $firstCalls++;

                return $firstCalls === 3;
            },
        );
        $this->sender->addWriteListener(
            function () use (&$secondCalls): int {
                $secondCalls++;

                // Any value other than true keeps the loop running
                return 1;
            },
        );

        $this->sender->send();

        self::assertSame(3, $firstCalls);
        self::assertSame(3, $secondCalls);
        self::assertSame([0.5, 0.5], $this->runtime->sleeps);
        self::assertEmpty($this->sender->getListeners()[EventSender::ON_WRITE]);
    }

    public function testSendStopsWhenClientDisconnects(): void
    {
        $runtime = new FakeRuntime(2);
        $sender = new EventSender(null, $runtime);
        $writeCalls = 0;
        $stopListener = false;
        $sender->addWriteListener(
            function () use (&$writeCalls): void {
                $writeCalls++;
            },
        );
        $sender->addStopListener(
            function () use (&$stopListener): void {
                $stopListener = true;
            },
        );

        $sender->send();

        self::assertSame(2, $writeCalls);
        self::assertCount(1, $runtime->sleeps);
        self::assertTrue($stopListener);
    }

    public function testSendWithoutWriteListenersRunsStartAndStopListeners(): void
    {
        $calls = [];
        $this->sender->addStartListener(
            function () use (&$calls): void {
                $calls[] = 'start';
            },
        );
        $this->sender->addStopListener(
            function () use (&$calls): void {
                $calls[] = 'stop';
            },
        );

        $this->sender->send();

        self::assertSame(['start', 'stop'], $calls);
        self::assertSame([], $this->runtime->sleeps);
    }

    public function testListenersReceiveTheBufferAndOutputIsFlushed(): void
    {
        $this->expectOutputString("event: start\ndata: \n\nevent: write\ndata: \n\nevent: stop\ndata: \n\n");
        $this->sender->addStartListener(
            function (EventBufferInterface $buffer): void {
                $buffer->write(new Event('start'));
            },
        );
        $this->sender->addWriteListener(
            function (EventBufferInterface $buffer): true {
                $buffer->write(new Event('write'));

                return true;
            },
        );
        $this->sender->addStopListener(
            function (EventBufferInterface $buffer): void {
                $buffer->write(new Event('stop'));
            },
        );

        $this->sender->send();

        self::assertSame(1, $this->runtime->flushes);
    }

    public function testSendRestoresIgnoreUserAbort(): void
    {
        $previous = ignore_user_abort(false);
        try {
            $this->sender->send();
            self::assertSame(0, ignore_user_abort());
        } finally {
            ignore_user_abort((bool) $previous);
        }
    }

    public function testDefaultHeaders(): void
    {
        $headers = $this->sender->send()->headers;

        self::assertSame('text/event-stream', $headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $headers->get('Cache-Control'));
        self::assertSame('no', $headers->get('X-Accel-Buffering'));
    }

    public function testCustomBuffer(): void
    {
        $constructorBuffer = new RecordingBuffer();
        $sender = new EventSender($constructorBuffer, $this->runtime);
        $sender->addWriteListener(
            function (EventBufferInterface $buffer): bool {
                $buffer->write(new Event('first'));

                return true;
            },
        );
        $sender->send();

        self::assertSame(['first'], $constructorBuffer->eventNames);

        // A StreamedResponse is streamed only once, so a new sender is needed
        $setterBuffer = new RecordingBuffer();
        $sender = new EventSender(null, $this->runtime);
        $sender->setBuffer($setterBuffer);
        $sender->addStartListener(
            function (EventBufferInterface $buffer): void {
                $buffer->write(new Event('second'));
            },
        );
        $sender->send();

        self::assertSame(['second'], $setterBuffer->eventNames);
    }

    public function testSetSleepAcceptsZero(): void
    {
        $this->sender->setSleep(0);
        $calls = 0;
        $this->sender->addWriteListener(
            function () use (&$calls): bool {
                return ++$calls === 2;
            },
        );

        $this->sender->send();

        self::assertSame([0.0], $this->runtime->sleeps);
    }

    public function testHeaders(): void
    {
        $this->sender->addHeaders(['X-Custom' => 'custom', 'X-Accel-Buffering' => 'yes']);
        $this->sender->addHeader(null, 'ignored');

        $headers = $this->sender->send()->headers;

        self::assertSame('text/event-stream', $headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $headers->get('Cache-Control'));
        self::assertSame('custom', $headers->get('X-Custom'));
        self::assertSame('yes', $headers->get('X-Accel-Buffering'));
        self::assertFalse($headers->has('Transfer-Encoding'));
    }

    public function testSetSleepRejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->setSleep(-1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = new FakeRuntime();
        $this->sender = new EventSender(null, $this->runtime);
    }
}
