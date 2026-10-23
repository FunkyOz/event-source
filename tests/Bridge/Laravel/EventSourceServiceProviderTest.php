<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Bridge\Laravel;

use FunkyOz\EventSource\Bridge\Laravel\EventSourceServiceProvider;
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use FunkyOz\EventSource\Testing\EventParser;
use FunkyOz\EventSource\Tests\Support\CapturesOutput;
use Illuminate\Foundation\Application;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventSourceServiceProviderTest extends TestCase
{
    use CapturesOutput;

    protected function getPackageProviders($app): array
    {
        return [EventSourceServiceProvider::class];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ResponseFactory::flushMacros();
        $this->resetRegisteredFlag();
    }

    public function testResponseMacroStreamsTheEvents(): void
    {
        // This is response()->sse(...). Static analysis doesn't know macros without Larastan, so it's called through
        // Macroable::__call(), which is what Laravel does
        Route::get('/events', fn() => $this->responseFactory()->__call(EventSourceServiceProvider::MACRO, [
            EventStream::create()->onStart(fn(Stream $stream) => $stream->send(new Event('resume', (string) $stream->lastEventId()))),
        ]));

        $response = $this->get('/events', ['Last-Event-ID' => '41']);

        $response->assertOk();
        // Laravel prepares the response, which adds the charset
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('X-Accel-Buffering', 'no');
        $base = $response->baseResponse;
        self::assertInstanceOf(StreamedResponse::class, $base);
        $output = $this->captureOutput($base->sendContent(...));
        self::assertEquals([new Event('resume', '41')], EventParser::parse($output)->events);
    }

    public function testBootingTwiceKeepsTheMacro(): void
    {
        $this->provider()->boot();

        self::assertTrue(ResponseFactory::hasMacro(EventSourceServiceProvider::MACRO));
    }

    public function testRegistersAgainAfterTheMacrosWereFlushed(): void
    {
        ResponseFactory::flushMacros();

        $this->provider()->boot();

        self::assertTrue(ResponseFactory::hasMacro(EventSourceServiceProvider::MACRO));
    }

    public function testFailsWhenAnotherPackageUsesTheName(): void
    {
        ResponseFactory::flushMacros();
        $this->resetRegisteredFlag();
        ResponseFactory::macro(EventSourceServiceProvider::MACRO, fn(): null => null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot register response()->sse()');
        $this->provider()->boot();
    }

    private function responseFactory(): ResponseFactory
    {
        self::assertInstanceOf(Application::class, $this->app);

        return $this->app->make(ResponseFactory::class);
    }

    private function provider(): EventSourceServiceProvider
    {
        self::assertInstanceOf(Application::class, $this->app);

        return new EventSourceServiceProvider($this->app);
    }

    private function resetRegisteredFlag(): void
    {
        (new \ReflectionProperty(EventSourceServiceProvider::class, 'registered'))->setValue(null, false);
    }
}
