<?php

declare(strict_types=1);

namespace EventSource;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class EventSender
 * @package EventSource
 *
 * Loop response inside StreamedResponse callback:
 * - Call start listeners once.
 * - Call all write listeners on every iteration, flush the output, then sleep for $sleep seconds.
 * - If even just one write listener returns true, or the client disconnects, break the loop.
 * - Call stop listeners once, even when the client has disconnected.
 * - At the end of the process all listeners are empty.
 *
 */
class EventSender
{
    public const ON_START = 'start';
    public const ON_WRITE = 'write';
    public const ON_STOP = 'stop';
    /**
     * Symfony StreamedResponse
     */
    protected StreamedResponse $response;
    /**
     * Time to sleep in seconds
     */
    protected float $sleep = 1;
    protected EventBufferInterface $buffer;
    protected RuntimeInterface $runtime;
    /**
     * Listeners for events
     * [start, write, stop]
     *
     * @var array{
     *     'start': callable[],
     *     'write': callable[],
     *     'stop': callable[],
     * }
     */
    private array $listeners = [
        self::ON_START => [],
        self::ON_WRITE => [],
        self::ON_STOP => [],
    ];

    public function __construct(?EventBufferInterface $buffer = null, ?RuntimeInterface $runtime = null)
    {
        $this->response = new StreamedResponse();
        $this->response->headers->set('Content-Type', 'text/event-stream');
        $this->response->headers->set('Cache-Control', 'no-cache');
        $this->response->headers->set('X-Accel-Buffering', 'no');

        // Using default PhpRuntime and EventBuffer
        $this->runtime = $runtime ?? new PhpRuntime();
        $this->setBuffer($buffer ?? new EventBuffer(null, $this->runtime));
    }

    public function setBuffer(EventBufferInterface $buffer): void
    {
        $this->buffer = $buffer;
    }

    /**
     * Send response through Symfony StreamedResponse
     */
    public function send(): StreamedResponse
    {
        $this->response->setCallback(
            function (): void {
                $bufferStarted = false;
                if (ob_get_level() === 0) {
                    ob_start();
                    $bufferStarted = true;
                }

                $startListeners = $this->listeners[self::ON_START];
                $this->listeners[self::ON_START] = [];
                foreach ($startListeners as $onStartListener) {
                    $onStartListener($this->buffer);
                }

                // Keep running after a client disconnect so stop listeners are always called
                $previousIgnoreUserAbort = ignore_user_abort(true);

                // Write listeners are called on every iteration until one of them returns true
                // or the client disconnects
                $writeListeners = $this->listeners[self::ON_WRITE];
                $this->listeners[self::ON_WRITE] = [];
                while (!empty($writeListeners)) {
                    $shouldStop = false;
                    foreach ($writeListeners as $onWriteListener) {
                        $shouldStop = (true === $onWriteListener($this->buffer)) || $shouldStop;
                    }

                    if ($shouldStop) {
                        break;
                    }

                    $this->runtime->flush();

                    // The disconnection is only detected after trying to send output
                    if ($this->runtime->isConnectionAborted()) {
                        break;
                    }

                    $this->runtime->sleep($this->sleep);
                }

                $stopListeners = $this->listeners[self::ON_STOP];
                $this->listeners[self::ON_STOP] = [];
                foreach ($stopListeners as $onStopListener) {
                    $onStopListener($this->buffer);
                }
                // Send what the last write and the stop listeners have written
                $this->runtime->flush();

                // Only close the buffer opened here
                if ($bufferStarted) {
                    ob_end_flush();
                }

                ignore_user_abort((bool) $previousIgnoreUserAbort);
            },
        );

        return $this->response->send();
    }

    /**
     * Add start listeners
     */
    public function addStartListener(callable $onStartListener): void
    {
        $this->listeners[self::ON_START][] = $onStartListener;
    }

    /**
     * Add write listener
     */
    public function addWriteListener(callable $onWriteListener): void
    {
        $this->listeners[self::ON_WRITE][] = $onWriteListener;
    }

    /**
     * Add stop listener
     */
    public function addStopListener(callable $onStopListener): void
    {
        $this->listeners[self::ON_STOP][] = $onStopListener;
    }

    /**
     * Time to sleep between iterations, fractions of a second are allowed (e.g. 0.5)
     */
    public function setSleep(float $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('The sleep time cannot be negative.');
        }
        $this->sleep = $seconds;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function addHeaders(array $headers): void
    {
        foreach ($headers as $key => $values) {
            $this->addHeader($key, $values);
        }
    }

    public function addHeader(?string $key, ?string $values): void
    {
        if ($key === null) {
            return;
        }
        $this->response->headers->set($key, $values);
    }

    /**
     * @return array{
     *      'start': callable[],
     *      'write': callable[],
     *      'stop': callable[],
     *  }
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }
}
