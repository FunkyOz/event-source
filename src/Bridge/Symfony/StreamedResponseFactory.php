<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Bridge\Symfony;

use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\PhpOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a stream into a Symfony response, which the framework sends. Also works with Laravel
 */
final class StreamedResponseFactory
{
    /**
     * @param Request|null $request To resume from the Last-Event-ID header the browser sends after a reconnection
     */
    public static function create(EventStream $stream, ?Request $request = null): StreamedResponse
    {
        $lastEventId = $request?->headers->get('Last-Event-ID');

        return new StreamedResponse(
            static function () use ($stream, $lastEventId): void {
                $stream->run(new PhpOutput(), $lastEventId);
            },
            Response::HTTP_OK,
            $stream->getHeaders(),
        );
    }

    /**
     * A 204 No Content response, which tells the browser to stop reconnecting
     */
    public static function noContent(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
