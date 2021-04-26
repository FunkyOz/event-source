<?php
declare(strict_types=1);

namespace EventSource;

class EventSource
{
    /**
     * @var string|null
     */
    private $event;

    /**
     * @var string|null
     */
    private $data;

    /**
     * @var string|null
     */
    private $id;

    /**
     * Seconds to retry connection
     *
     * @var int|null
     */
    private $retry;

    /**
     * EventSource constructor.
     * @param string $event
     * @param string|null $data
     * @param string|null $id
     * @param int|null $retry
     */
    public function __construct(
        string $event = 'message',
        ?string $data = null,
        ?string $id = null,
        ?int $retry = 3
    ) {
        $this->event = $event;
        $this->data = $data;
        $this->id = $id;
        $this->retry = $retry;
    }

    /**
     * @return string|null
     */
    public function getEvent(): ?string
    {
        return $this->event;
    }

    /**
     * @param string|null $event
     */
    public function setEvent(?string $event): void
    {
        $this->event = $event;
    }

    /**
     * @return string|null
     */
    public function getData(): ?string
    {
        return $this->data;
    }

    /**
     * @param string|null $payload
     */
    public function setData(?string $payload): void
    {
        $this->data = $payload;
    }

    /**
     * @return string|null
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @param string|null $id
     */
    public function setId(?string $id): void
    {
        $this->id = $id;
    }

    /**
     * @return int|null
     */
    public function getRetry(): ?int
    {
        return $this->retry;
    }

    /**
     * @param int|null $retry
     */
    public function setRetry(?int $retry): void
    {
        $this->retry = $retry;
    }
}
