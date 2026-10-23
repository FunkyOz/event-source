<?php

declare(strict_types=1);

namespace FunkyOz\EventSource;

enum StopReason
{
    /** A listener called Stream::stop(), the generator finished, or there were no tick listeners */
    case Stopped;
    case ClientDisconnected;
    case MaxDurationReached;
    /** A listener or the generator threw an exception */
    case Error;
}
