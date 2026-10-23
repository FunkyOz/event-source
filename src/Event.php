<?php

declare(strict_types=1);

namespace EventSource;

class Event
{
    /**
     * @param int|null $retry Seconds the client waits before reconnecting
     */
    public function __construct(
        private ?string $eventName = 'message',
        private ?string $data = null,
        private ?string $id = null,
        private ?int $retry = null,
    ) {}

    public function getEventName(): ?string
    {
        return $this->eventName;
    }

    public function setEventName(?string $eventName): void
    {
        $this->eventName = $eventName;
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function setData(?string $payload): void
    {
        $this->data = $payload;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): void
    {
        $this->id = $id;
    }

    public function getRetry(): ?int
    {
        return $this->retry;
    }

    public function setRetry(?int $retry): void
    {
        $this->retry = $retry;
    }
}
