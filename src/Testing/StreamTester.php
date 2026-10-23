<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Testing;

use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;

/**
 * Runs a stream instantly, without a server, and returns what it sent
 */
final class StreamTester
{
    private ?string $lastEventId = null;
    private ?int $disconnectAfter = null;
    private int $iterationLimit = 10_000;

    private function __construct(private readonly EventStream $stream) {}

    public static function for(EventStream $stream): self
    {
        return new self($stream);
    }

    /**
     * Simulate a reconnection: the Last-Event-ID header the browser sends
     */
    public function withLastEventId(?string $lastEventId): self
    {
        $this->lastEventId = $lastEventId;

        return $this;
    }

    /**
     * Simulate a client disconnection after the given number of ticks or generated events
     */
    public function disconnectAfter(int $iterations): self
    {
        if ($iterations < 1) {
            throw new \InvalidArgumentException('The number of iterations must be at least 1.');
        }
        $this->disconnectAfter = $iterations;

        return $this;
    }

    /**
     * Fail when the stream runs for more iterations than this, 10000 by default
     */
    public function iterationLimit(int $iterations): self
    {
        if ($iterations < 1) {
            throw new \InvalidArgumentException('The iteration limit must be at least 1.');
        }
        $this->iterationLimit = $iterations;

        return $this;
    }

    public function run(): StreamResult
    {
        $clock = new FakeClock();
        $output = new MemoryOutput();
        $current = null;
        $limitReached = false;

        // A copy, so the stream given by the user is not changed
        $stream = clone $this->stream;
        $stream->onStart(static function (Stream $stream) use (&$current): void {
            $current = $stream;
        });
        $output->disconnectWhen(function () use (&$current, &$limitReached): bool {
            if (!$current instanceof Stream) {
                return false;
            }
            if (null !== $this->disconnectAfter && $current->iteration() >= $this->disconnectAfter) {
                return true;
            }
            if ($current->iteration() >= $this->iterationLimit) {
                return $limitReached = true;
            }

            return false;
        });

        $stopReason = $stream->run($output, $this->lastEventId, $clock);

        if ($limitReached) {
            throw new \LogicException(sprintf('The stream did not end after %d iterations. Use disconnectAfter(), or stop the stream.', $this->iterationLimit));
        }

        return new StreamResult(
            $output->parse(),
            $stopReason,
            $clock->sleeps(),
            $current instanceof Stream ? $current->iteration() : 0,
            $output->contents(),
        );
    }
}
