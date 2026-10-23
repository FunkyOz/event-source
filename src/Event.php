<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

/**
 * A server-sent event
 */
final readonly class Event
{
    /**
     * @param string|null $name Event type, null for an unnamed event (the browser's "message" event)
     * @param string|null $id Sent back by the browser in the Last-Event-ID header after a reconnection
     * @param float|null $retry Seconds the browser waits before reconnecting
     */
    public function __construct(
        public ?string $name = null,
        public string $data = '',
        public ?string $id = null,
        public ?float $retry = null,
    ) {
        // A line break would start a new field and corrupt the stream
        if (null !== $name && 1 === preg_match('/[\r\n]/', $name)) {
            throw new \InvalidArgumentException('The event name cannot contain line breaks.');
        }
        if (null !== $id && 1 === preg_match('/[\r\n\0]/', $id)) {
            throw new \InvalidArgumentException('The event id cannot contain line breaks or NUL characters.');
        }
        if (null !== $retry && $retry < 0) {
            throw new \InvalidArgumentException('The retry time cannot be negative.');
        }
    }

    /**
     * Create an event whose data is JSON-encoded
     *
     * @throws \JsonException
     */
    public static function json(?string $name, mixed $data, ?string $id = null, ?float $retry = null): self
    {
        return new self(
            $name,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $id,
            $retry,
        );
    }

    public function withName(?string $name): self
    {
        return new self($name, $this->data, $this->id, $this->retry);
    }

    public function withData(string $data): self
    {
        return new self($this->name, $data, $this->id, $this->retry);
    }

    public function withId(?string $id): self
    {
        return new self($this->name, $this->data, $id, $this->retry);
    }

    public function withRetry(?float $retry): self
    {
        return new self($this->name, $this->data, $this->id, $retry);
    }
}
