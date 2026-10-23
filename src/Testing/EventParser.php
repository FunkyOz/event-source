<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Testing;

use FunkyOz\EventSource\Event;

/**
 * Parses text/event-stream output the way a browser does
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
 */
final class EventParser
{
    public static function parse(string $stream): ParsedStream
    {
        $events = [];
        $comments = [];
        $lastRetry = null;
        $name = $id = $retry = null;
        $data = '';
        $hasData = false;

        $lines = preg_split('/\r\n|\r|\n/', $stream) ?: [];
        // The last element is an incomplete line, or empty when the stream ends with a line break
        array_pop($lines);

        foreach ($lines as $line) {
            if ('' === $line) {
                if ($hasData) {
                    $events[] = new Event($name, substr($data, 0, -1), $id, $retry);
                }
                $name = $id = $retry = null;
                $data = '';
                $hasData = false;

                continue;
            }

            if (str_starts_with($line, ':')) {
                $comments[] = self::removeLeadingSpace(substr($line, 1));

                continue;
            }

            [$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];
            $value = self::removeLeadingSpace($value);
            switch ($field) {
                case 'event':
                    $name = $value;
                    break;
                case 'data':
                    $data .= $value . "\n";
                    $hasData = true;
                    break;
                case 'id':
                    if (!str_contains($value, "\0")) {
                        $id = $value;
                    }
                    break;
                case 'retry':
                    if (1 === preg_match('/^\d+$/', $value)) {
                        $retry = $lastRetry = (int) $value / 1000;
                    }
                    break;
            }
        }

        return new ParsedStream($events, $comments, $lastRetry);
    }

    private static function removeLeadingSpace(string $value): string
    {
        return str_starts_with($value, ' ') ? substr($value, 1) : $value;
    }
}
