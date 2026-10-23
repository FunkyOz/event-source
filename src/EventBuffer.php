<?php

declare(strict_types=1);

namespace EventSource;

class EventBuffer implements EventBufferInterface
{
    /**
     * Stream the events are written to, php://output when not set
     *
     * @var resource|null
     */
    private $stream;

    private readonly RuntimeInterface $runtime;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null, ?RuntimeInterface $runtime = null)
    {
        if (null !== $stream && !is_resource($stream)) {
            throw new \InvalidArgumentException('The stream must be a resource.');
        }
        $this->stream = $stream;
        $this->runtime = $runtime ?? new PhpRuntime();
    }

    public function write(Event $event, bool $flush = false): void
    {
        if (null === $this->stream) {
            // php://output goes through output buffering, like echo
            $stream = fopen('php://output', 'w');
            if (false === $stream) {
                throw new \RuntimeException('Unable to open php://output.');
            }
            $this->stream = $stream;
        }

        fwrite($this->stream, self::format($event));

        if ($flush) {
            fflush($this->stream);
            $this->runtime->flush();
        }
    }

    /**
     * Serialize an event following the text/event-stream format
     */
    public static function format(Event $event): string
    {
        $lines = [];
        $eventName = $event->getEventName();
        $id = $event->getId();
        $retry = $event->getRetry();
        if (null !== $eventName) {
            $lines[] = 'event: ' . $eventName;
        }
        if (null !== $id) {
            $lines[] = 'id: ' . $id;
        }
        if (null !== $retry) {
            $lines[] = 'retry: ' . ($retry * 1000);
        }
        // Each line of a multi-line payload needs its own "data:" field
        foreach (preg_split('/\r\n|\r|\n/', (string) $event->getData()) ?: [] as $line) {
            $lines[] = 'data: ' . $line;
        }

        // A blank line dispatches the event, so it must come after all its fields
        return implode("\n", $lines) . "\n\n";
    }
}
