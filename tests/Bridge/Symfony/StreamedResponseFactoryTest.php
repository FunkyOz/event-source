<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Bridge\Symfony;

use FunkyOz\EventSource\Bridge\Symfony\StreamedResponseFactory;
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use FunkyOz\EventSource\Testing\EventParser;
use FunkyOz\EventSource\Tests\Support\CapturesOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class StreamedResponseFactoryTest extends TestCase
{
    use CapturesOutput;

    public function testCreatesAResponseThatStreamsTheEvents(): void
    {
        $stream = EventStream::create()
            ->header('Access-Control-Allow-Origin', '*')
            ->onStart(fn(Stream $stream) => $stream->send(new Event('resume', (string) $stream->lastEventId())));
        $request = Request::create('/events', server: ['HTTP_LAST_EVENT_ID' => '41']);

        $response = StreamedResponseFactory::create($stream, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));

        // Nothing runs until the framework sends the response
        $output = $this->captureOutput($response->sendContent(...));
        self::assertEquals([new Event('resume', '41')], EventParser::parse($output)->events);
    }

    public function testWithoutARequest(): void
    {
        $stream = EventStream::create()->onStart(fn(Stream $stream) => $stream->send(new Event('resume', var_export($stream->lastEventId(), true))));

        $output = $this->captureOutput(StreamedResponseFactory::create($stream)->sendContent(...));

        self::assertSame('NULL', EventParser::parse($output)->events[0]->data);
    }

    public function testNoContent(): void
    {
        $response = StreamedResponseFactory::noContent();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }
}
