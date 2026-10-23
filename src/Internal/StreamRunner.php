<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Internal;

use FunkyOz\EventSource\Clock;
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventFormatter;
use FunkyOz\EventSource\Output;
use FunkyOz\EventSource\StopReason;
use FunkyOz\EventSource\Stream;

/**
 * Runs a stream definition against an output
 *
 * @internal
 */
final readonly class StreamRunner
{
    private StreamState $state;
    private Stream $stream;

    /**
     * @param list<\Closure(Stream): mixed> $onStart
     * @param list<\Closure(Stream): mixed> $onTick
     * @param list<\Closure(Stream): mixed> $onStop
     * @param list<\Closure(\Throwable, Stream): mixed> $onError
     * @param (\Closure(Stream): mixed)|null $generator
     */
    public function __construct(
        private Output $output,
        private Clock $clock,
        ?string $lastEventId,
        private array $onStart,
        private array $onTick,
        private array $onStop,
        private array $onError,
        private ?\Closure $generator,
        private float $every,
        private ?float $heartbeat,
        private ?float $maxDuration,
        private ?float $retry,
    ) {
        $this->state = new StreamState($lastEventId, $clock->now());
        $this->stream = new Stream($output, $clock, $this->state);
    }

    public function run(): StopReason
    {
        $error = null;
        $reason = StopReason::Stopped;
        $this->output->open();
        try {
            try {
                if (null !== $this->retry) {
                    $this->output->write(EventFormatter::retry($this->retry));
                    $this->output->flush();
                }
                foreach ($this->onStart as $listener) {
                    $listener($this->stream);
                }
                $reason = $this->generator instanceof \Closure ? $this->runGenerator($this->generator) : $this->runTicks();
            } catch (\Throwable $e) {
                $reason = StopReason::Error;
                if ([] === $this->onError) {
                    $error = $e;
                }
                foreach ($this->onError as $listener) {
                    $listener($e, $this->stream);
                }
            }

            $this->state->stopReason = $reason;
            foreach ($this->onStop as $listener) {
                $listener($this->stream);
            }
        } finally {
            $this->state->closed = true;
            $this->output->close();
        }

        if ($error instanceof \Throwable) {
            throw $error;
        }

        return $reason;
    }

    private function runTicks(): StopReason
    {
        if ([] === $this->onTick) {
            return StopReason::Stopped;
        }

        while (true) {
            if ($this->state->stopRequested) {
                return StopReason::Stopped;
            }
            if ($this->isMaxDurationReached()) {
                return StopReason::MaxDurationReached;
            }

            $tickStartedAt = $this->clock->now();
            $this->state->iteration++;
            foreach ($this->onTick as $listener) {
                $listener($this->stream);
                if ($this->state->stopRequested) {
                    return StopReason::Stopped;
                }
            }

            // Stream::send() has flushed: a disconnection is only detected after trying to send output
            if ($this->output->isConnectionAborted()) {
                return StopReason::ClientDisconnected;
            }

            $reason = $this->waitUntil($tickStartedAt + $this->every);
            if ($reason instanceof \FunkyOz\EventSource\StopReason) {
                return $reason;
            }
        }
    }

    /**
     * Sleep until the given time, sending heartbeats on time while waiting
     *
     * @return StopReason|null Why the stream must end, or null to continue
     */
    private function waitUntil(float $nextTickAt): ?StopReason
    {
        $endsAt = null === $this->maxDuration ? null : $this->state->startedAt + $this->maxDuration;
        while (true) {
            $now = $this->clock->now();
            if (null !== $endsAt && $now >= $endsAt) {
                return StopReason::MaxDurationReached;
            }
            if ($now >= $nextTickAt) {
                return null;
            }

            $wakeAt = min($nextTickAt, $endsAt ?? $nextTickAt);
            if (null !== $this->heartbeat) {
                $heartbeatAt = $this->state->lastWriteAt + $this->heartbeat;
                if ($heartbeatAt <= $now) {
                    $this->stream->comment();
                    if ($this->output->isConnectionAborted()) {
                        return StopReason::ClientDisconnected;
                    }
                    continue;
                }
                $wakeAt = min($wakeAt, $heartbeatAt);
            }

            $this->clock->sleep($wakeAt - $now);
        }
    }

    /**
     * @param \Closure(Stream): mixed $generator
     */
    private function runGenerator(\Closure $generator): StopReason
    {
        if ($this->state->stopRequested) {
            return StopReason::Stopped;
        }

        $events = $generator($this->stream);
        if (!is_iterable($events)) {
            throw new \UnexpectedValueException(sprintf('The stream generator must return an iterable, %s returned.', get_debug_type($events)));
        }

        foreach ($events as $event) {
            if (!$event instanceof Event) {
                throw new \UnexpectedValueException(sprintf('The stream generator must yield %s instances, %s yielded.', Event::class, get_debug_type($event)));
            }
            // stop() may have been called by the generator itself, before yielding this event
            if ($this->state->stopRequested) {
                return StopReason::Stopped;
            }
            $this->state->iteration++;
            $this->stream->send($event);

            if ($this->output->isConnectionAborted()) {
                return StopReason::ClientDisconnected;
            }
            if ($this->isMaxDurationReached()) {
                return StopReason::MaxDurationReached;
            }
        }

        return StopReason::Stopped;
    }

    private function isMaxDurationReached(): bool
    {
        return null !== $this->maxDuration && $this->clock->now() - $this->state->startedAt >= $this->maxDuration;
    }
}
