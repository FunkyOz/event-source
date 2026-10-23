<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

/**
 * Writes to the PHP output, for PHP-FPM, Apache and the built-in server
 */
final class PhpOutput implements Output
{
    private ?int $previousIgnoreUserAbort = null;

    public function open(): void
    {
        // Keep running after a client disconnect, so the stop listeners are always called
        $this->previousIgnoreUserAbort = ignore_user_abort(true);
    }

    public function write(string $chunk): void
    {
        echo $chunk;
    }

    public function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    public function isConnectionAborted(): bool
    {
        return 1 === connection_aborted();
    }

    public function close(): void
    {
        $this->flush();
        if (null !== $this->previousIgnoreUserAbort) {
            ignore_user_abort((bool) $this->previousIgnoreUserAbort);
            $this->previousIgnoreUserAbort = null;
        }
    }
}
