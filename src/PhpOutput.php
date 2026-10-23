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
        // flush() only empties the innermost output buffer, so events would wait in the ones below it: close the
        // buffers opened before the stream, like output_buffering or a framework's. Not on the command line, where
        // buffers capture streams in tests
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            $this->closeOutputBuffers();
        }
    }

    public function write(string $chunk): void
    {
        echo $chunk;
    }

    public function flush(): void
    {
        // A buffer that couldn't be closed may not be flushable either: ob_flush() would raise a notice on every event
        $buffer = ob_get_status();
        if ([] !== $buffer && 0 !== ($buffer['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE)) {
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

    /**
     * Flush and close the output buffers, from the innermost one, until one can't be removed
     */
    private function closeOutputBuffers(): void
    {
        $flags = PHP_OUTPUT_HANDLER_REMOVABLE | PHP_OUTPUT_HANDLER_FLUSHABLE;
        foreach (array_reverse(ob_get_status(true)) as $status) {
            if (($status['flags'] & $flags) !== $flags) {
                return;
            }
            ob_end_flush();
        }
    }
}
