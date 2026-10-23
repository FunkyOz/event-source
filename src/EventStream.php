<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

use FunkyOz\EventSource\Internal\StreamRunner;

/**
 * The definition of a stream: what to send, and when. It can be run any number of times
 */
final class EventStream
{
    private const DEFAULT_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        // Disable response buffering in nginx
        'X-Accel-Buffering' => 'no',
    ];

    private float $every = 1.0;
    private ?float $heartbeat = null;
    private ?float $maxDuration = null;
    private ?float $retry = null;

    /**
     * Keyed by lowercase name, as header names are case-insensitive
     *
     * @var array<string, array{string, string}>
     */
    private array $headers = [];

    /**
     * @var list<\Closure(Stream): mixed>
     */
    private array $onStart = [];

    /**
     * @var list<\Closure(Stream): mixed>
     */
    private array $onTick = [];

    /**
     * @var list<\Closure(Stream): mixed>
     */
    private array $onStop = [];

    /**
     * @var list<\Closure(\Throwable, Stream): mixed>
     */
    private array $onError = [];

    /**
     * @param (\Closure(Stream): mixed)|null $generator
     */
    private function __construct(private readonly ?\Closure $generator)
    {
        foreach (self::DEFAULT_HEADERS as $name => $value) {
            $this->header($name, $value);
        }
    }

    /**
     * A stream that calls the tick listeners at a regular interval
     */
    public static function create(): self
    {
        return new self(null);
    }

    /**
     * A stream that sends every event the generator yields
     *
     * @param callable(Stream): iterable<Event> $generator
     */
    public static function fromGenerator(callable $generator): self
    {
        return new self($generator(...));
    }

    /**
     * Seconds between the start of two ticks, 1 by default
     */
    public function every(float $seconds): self
    {
        $this->assertTickMode(__FUNCTION__);
        if ($seconds < 0) {
            throw new \InvalidArgumentException('The tick interval cannot be negative.');
        }
        $this->every = $seconds;

        return $this;
    }

    /**
     * Send a comment when nothing was written for the given seconds, so proxies don't close the connection
     */
    public function heartbeat(?float $seconds): self
    {
        // Heartbeats are sent while waiting between ticks, and a generator stream never waits
        $this->assertTickMode(__FUNCTION__);
        if (null !== $seconds && $seconds <= 0) {
            throw new \InvalidArgumentException('The heartbeat interval must be greater than zero.');
        }
        $this->heartbeat = $seconds;

        return $this;
    }

    /**
     * End the stream after the given seconds. The browser then reconnects
     */
    public function maxDuration(?float $seconds): self
    {
        if (null !== $seconds && $seconds <= 0) {
            throw new \InvalidArgumentException('The maximum duration must be greater than zero.');
        }
        $this->maxDuration = $seconds;

        return $this;
    }

    /**
     * Seconds the browser waits before reconnecting, sent once when the stream starts
     */
    public function retry(?float $seconds): self
    {
        if (null !== $seconds && $seconds < 0) {
            throw new \InvalidArgumentException('The retry time cannot be negative.');
        }
        $this->retry = $seconds;

        return $this;
    }

    /**
     * Add or replace a response header
     */
    public function header(string $name, string $value): self
    {
        if ('' === $name || 1 === preg_match('/[\r\n:]/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid header name "%s".', $name));
        }
        if (1 === preg_match('/[\r\n\0]/', $value)) {
            throw new \InvalidArgumentException(sprintf('The value of the "%s" header cannot contain line breaks.', $name));
        }
        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    /**
     * Add or replace several response headers
     *
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }

        return $this;
    }

    /**
     * Called once, before the first tick or generated event
     *
     * @param callable(Stream): mixed $listener
     */
    public function onStart(callable $listener): self
    {
        $this->onStart[] = $listener(...);

        return $this;
    }

    /**
     * Called on every tick
     *
     * @param callable(Stream): mixed $listener
     */
    public function onTick(callable $listener): self
    {
        $this->assertTickMode(__FUNCTION__);
        $this->onTick[] = $listener(...);

        return $this;
    }

    /**
     * Called once at the end, also after a disconnection or an error. Stream::stopReason() tells why
     *
     * @param callable(Stream): mixed $listener
     */
    public function onStop(callable $listener): self
    {
        $this->onStop[] = $listener(...);

        return $this;
    }

    /**
     * Called when a listener or the generator throws. Without error listeners, the exception is rethrown
     * after the stop listeners have run
     *
     * @param callable(\Throwable, Stream): mixed $listener
     */
    public function onError(callable $listener): self
    {
        $this->onError[] = $listener(...);

        return $this;
    }

    /**
     * The response headers, including the defaults
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $headers = [];
        foreach ($this->headers as [$name, $value]) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Send the stream with plain PHP: the headers, then the events
     */
    public function send(): void
    {
        self::assertHeadersNotSent();
        foreach ($this->getHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }

        $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
        $this->run(new PhpOutput(), is_string($lastEventId) ? $lastEventId : null);
    }

    /**
     * Answer with 204 No Content, which tells the browser to stop reconnecting
     */
    public static function sendNoContent(): void
    {
        self::assertHeadersNotSent();
        http_response_code(204);
    }

    /**
     * Run the stream against any output. The headers are not sent: this is what adapters build on
     */
    public function run(Output $output, ?string $lastEventId = null, ?Clock $clock = null): StopReason
    {
        $runner = new StreamRunner(
            output: $output,
            clock: $clock ?? new SystemClock(),
            lastEventId: $lastEventId,
            onStart: $this->onStart,
            onTick: $this->onTick,
            onStop: $this->onStop,
            onError: $this->onError,
            generator: $this->generator,
            every: $this->every,
            heartbeat: $this->heartbeat,
            maxDuration: $this->maxDuration,
            retry: $this->retry,
        );

        return $runner->run();
    }

    private function assertTickMode(string $method): void
    {
        if ($this->generator instanceof \Closure) {
            throw new \LogicException(sprintf('%s() cannot be used on a stream created with fromGenerator().', $method));
        }
    }

    private static function assertHeadersNotSent(): void
    {
        if (headers_sent($file, $line)) {
            throw new \LogicException(sprintf('Cannot start the stream: output was already sent in %s on line %d.', $file, $line));
        }
    }
}
