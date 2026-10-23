<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

/**
 * Serializes events and comments following the text/event-stream format
 */
final class EventFormatter
{
    public static function format(Event $event): string
    {
        $lines = [];
        if (null !== $event->name) {
            $lines[] = 'event: ' . $event->name;
        }
        if (null !== $event->id) {
            $lines[] = 'id: ' . $event->id;
        }
        if (null !== $event->retry) {
            $lines[] = 'retry: ' . self::milliseconds($event->retry);
        }
        // Each line of a multi-line payload needs its own "data:" field. There is always at least one,
        // otherwise the browser does not dispatch the event
        foreach (self::splitLines($event->data) as $line) {
            $lines[] = 'data: ' . $line;
        }

        // A blank line dispatches the event, so it must come after all its fields
        return implode("\n", $lines) . "\n\n";
    }

    /**
     * Comments are ignored by the browser, and keep the connection active
     */
    public static function comment(string $text = ''): string
    {
        if ('' === $text) {
            return ":\n";
        }

        $output = '';
        foreach (self::splitLines($text) as $line) {
            $output .= ': ' . $line . "\n";
        }

        return $output;
    }

    /**
     * Sets the reconnection time without dispatching an event
     */
    public static function retry(float $seconds): string
    {
        return 'retry: ' . self::milliseconds($seconds) . "\n\n";
    }

    private static function milliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        return preg_split('/\r\n|\r|\n/', $text) ?: [$text];
    }
}
