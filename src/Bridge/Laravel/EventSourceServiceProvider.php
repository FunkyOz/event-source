<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Bridge\Laravel;

use FunkyOz\EventSource\Bridge\Symfony\StreamedResponseFactory;
use FunkyOz\EventSource\EventStream;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adds response()->sse($stream). Registered automatically by Laravel's package discovery
 */
final class EventSourceServiceProvider extends ServiceProvider
{
    public const MACRO = 'sse';

    private static bool $registered = false;

    public function boot(): void
    {
        if (ResponseFactory::hasMacro(self::MACRO) && self::$registered) {
            return;
        }

        // A real method silently takes priority over a macro with the same name, and a second macro would replace
        // the first one: fail loudly instead
        if (method_exists(ResponseFactory::class, self::MACRO) || ResponseFactory::hasMacro(self::MACRO)) {
            throw new \LogicException(sprintf(
                'Cannot register response()->%s(): the name is already used by Laravel or another package. Use %s::create() instead.',
                self::MACRO,
                StreamedResponseFactory::class,
            ));
        }

        // The macro outlives this provider: it stays registered when another application is created in the same
        // process, as in tests. So the request is resolved from the current application when the macro is called.
        // Not a static closure, because Laravel binds macros to the ResponseFactory instance
        ResponseFactory::macro(self::MACRO, fn(EventStream $stream): StreamedResponse => StreamedResponseFactory::create($stream, Container::getInstance()->make(Request::class)));
        self::$registered = true;
    }
}
