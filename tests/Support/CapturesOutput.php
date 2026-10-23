<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Support;

trait CapturesOutput
{
    /**
     * Captures the output, also what is flushed with ob_flush(), which would otherwise escape PHPUnit
     */
    private function captureOutput(callable $callback): string
    {
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;

            return '';
        });
        try {
            $callback();
        } finally {
            ob_end_flush();
        }

        return $captured;
    }
}
