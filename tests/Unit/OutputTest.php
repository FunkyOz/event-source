<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Unit;

use FunkyOz\EventSource\PhpOutput;
use FunkyOz\EventSource\StreamOutput;
use FunkyOz\EventSource\SystemClock;
use FunkyOz\EventSource\Tests\Support\CapturesOutput;
use PHPUnit\Framework\TestCase;

class OutputTest extends TestCase
{
    use CapturesOutput;

    public function testPhpOutputWritesAndFlushesToTheOutput(): void
    {
        $output = new PhpOutput();

        $captured = $this->captureOutput(static function () use ($output): void {
            $output->open();
            $output->write("data: one\n\n");
            $output->flush();
            $output->write("data: two\n\n");
            $output->close();
        });

        self::assertSame("data: one\n\ndata: two\n\n", $captured);
        self::assertFalse($output->isConnectionAborted());
    }

    public function testPhpOutputIgnoresUserAbortWhileOpenAndRestoresIt(): void
    {
        $previous = ignore_user_abort(false);
        try {
            $output = new PhpOutput();
            $this->captureOutput(static function () use ($output): void {
                $output->open();
                self::assertSame(1, ignore_user_abort());
                $output->close();
            });
            self::assertSame(0, ignore_user_abort());

            // Closing twice doesn't change it again
            ignore_user_abort(true);
            $this->captureOutput($output->close(...));
            self::assertSame(1, ignore_user_abort());
        } finally {
            ignore_user_abort((bool) $previous);
        }
    }

    public function testStreamOutput(): void
    {
        $resource = fopen('php://memory', 'w+');
        self::assertIsResource($resource);
        $output = new StreamOutput($resource);

        $output->open();
        $output->write('data: x');
        $output->flush();
        $output->close();

        rewind($resource);
        self::assertSame('data: x', stream_get_contents($resource));
        self::assertFalse($output->isConnectionAborted());
        // The resource belongs to the caller and stays open (a closed one has the type "Unknown")
        self::assertSame('stream', get_resource_type($resource));
    }

    public function testStreamOutputRejectsAClosedResource(): void
    {
        $resource = fopen('php://memory', 'w+');
        self::assertIsResource($resource);
        fclose($resource);

        $this->expectException(\InvalidArgumentException::class);
        new StreamOutput($resource);
    }

    public function testSystemClock(): void
    {
        $clock = new SystemClock();

        $start = $clock->now();
        $clock->sleep(0.02);
        $clock->sleep(0);
        $clock->sleep(-1);
        $elapsed = $clock->now() - $start;

        self::assertGreaterThanOrEqual(0.02, $elapsed);
        self::assertLessThan(1, $elapsed);
    }
}
