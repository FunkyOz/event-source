<?php

declare(strict_types=1);

namespace EventSource;

class PhpRuntime implements RuntimeInterface
{
    public function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    public function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    public function isConnectionAborted(): bool
    {
        return 1 === connection_aborted();
    }
}
