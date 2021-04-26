<?php

declare(strict_types=1);

namespace EventSource;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class EventSender
 * @package EventSource
 *
 * Loop response inside StreamedResponse callback:
 * - Call each listeners [start, write, stop] and shift it, at the end of process all listeners are empty
 * - If write listener are empty break the loop.
 * - If even just one write listener return true then break the loop.
 * - Then sleep until $sleep passed.
 *
 */
class EventSender
{
    public const ON_START = 'start';
    public const ON_WRITE = 'write';
    public const ON_STOP = 'stop';

    /**
     * Listeners for events
     * [start, write, stop]
     *
     * @var callable[]
     */
    private $listeners = [
        self::ON_START => [],
        self::ON_WRITE => [],
        self::ON_STOP => []
    ];

    /**
     * Symfony StreamedResponse
     *
     * @var StreamedResponse
     */
    protected $response;

    /**
     * Time to sleep in seconds
     *
     * @var int
     */
    protected $sleep = 1;

    /**
     * @var EventBufferInterface
     */
    protected $buffer;

    /**
     * EventSender constructor.
     */
    public function __construct()
    {
        $this->response = new StreamedResponse;
        $this->response->headers->set('Content-Type', 'text/event-stream');
        $this->response->headers->set('Transfer-Encoding', 'identity');
        $this->response->headers->set('Cache-Control', 'no-cache');
        $this->response->headers->set('X-Accel-Buffering', 'no');

        // Using default EventBuffer
        $this->setBuffer(new EventBuffer);
    }

    /**
     * Send response through Symfony StreamedResponse
     *
     * @return StreamedResponse
     */
    public function send(): StreamedResponse
    {
        $this->response->setCallback(
            function () {
                while (count($this->listeners[self::ON_START]) > 0) {
                    $onStartListener = array_shift($this->listeners[self::ON_START]);
                    $onStartListener($this->buffer);
                }

                while (true) {
                    if (empty($this->listeners[self::ON_WRITE])) {
                        break;
                    }

                    $shouldStop = false;
                    while (count($this->listeners[self::ON_WRITE]) > 0) {
                        $onWriteListener = array_shift($this->listeners[self::ON_WRITE]);
                        $shouldStop = $shouldStop || $onWriteListener($this->buffer);
                    }

                    if (true === $shouldStop) {
                        break;
                    }

                    usleep($this->sleep * 1000000);
                    if (1 === connection_aborted()) {
                        break;
                    }
                }

                while (count($this->listeners[self::ON_STOP]) > 0) {
                    $onStopListener = array_shift($this->listeners[self::ON_STOP]);
                    $onStopListener($this->buffer);
                }
            }
        );

        return $this->response->send();
    }

    /**
     * Add start listeners
     *
     * @param callable|null $onStartListener
     */
    public function addStartListener(callable $onStartListener): void
    {
        $this->listeners[self::ON_START][] = $onStartListener;
    }

    /**
     * Add write listener
     *
     * @param callable|null $onWriteListener
     */
    public function addWriteListener(callable $onWriteListener): void
    {
        $this->listeners[self::ON_WRITE][] = $onWriteListener;
    }

    /**
     * Add stop listener
     *
     * @param callable $onStopListener
     */
    public function addStopListener(callable $onStopListener): void
    {
        $this->listeners[self::ON_STOP][] = $onStopListener;
    }

    /**
     * @param int $seconds
     */
    public function setSleep(int $seconds): void
    {
        $this->sleep = $seconds;
    }

    /**
     * @param EventBufferInterface $buffer
     */
    public function setBuffer(EventBufferInterface $buffer): void
    {
        $this->buffer = $buffer;
    }
}
