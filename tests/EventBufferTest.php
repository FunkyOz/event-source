<?php

declare(strict_types=1);

namespace Test;

use EventSource\Event;
use EventSource\EventBuffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Test\Support\FakeRuntime;

class EventBufferTest extends TestCase
{
    #[DataProvider('provideWriteData')]
    public function testFormat(Event $event, string $payload): void
    {
        self::assertSame($payload, EventBuffer::format($event));
    }

    #[DataProvider('provideWriteData')]
    public function testWriteToStream(Event $event, string $payload): void
    {
        $stream = $this->openMemoryStream();
        $buffer = new EventBuffer($stream);
        $buffer->write($event);

        rewind($stream);
        self::assertSame($payload, stream_get_contents($stream));
    }

    public function testWriteToOutputByDefault(): void
    {
        $this->expectOutputString("event: message\ndata: this is a test\n\n");
        $buffer = new EventBuffer();
        $buffer->write(new Event('message', 'this is a test'));
    }

    public function testWriteFlushesOnlyWhenAsked(): void
    {
        $runtime = new FakeRuntime();
        $buffer = new EventBuffer($this->openMemoryStream(), $runtime);

        $buffer->write(new Event());
        self::assertSame(0, $runtime->flushes);

        $buffer->write(new Event(), true);
        self::assertSame(1, $runtime->flushes);
    }

    public function testConstructorRejectsClosedStream(): void
    {
        $stream = $this->openMemoryStream();
        fclose($stream);

        $this->expectException(\InvalidArgumentException::class);
        new EventBuffer($stream);
    }

    /**
     * @return array<array{0: Event, 1: string}>
     */
    public static function provideWriteData(): array
    {
        return [
            [
                new Event('message', 'this is a test'),
                "event: message\ndata: this is a test\n\n",
            ],
            [
                new Event('json', (string) json_encode(['key' => 'test'])),
                "event: json\ndata: {\"key\":\"test\"}\n\n",
            ],
            [
                new Event('message', 'test whit id', 'id-test'),
                "event: message\nid: id-test\ndata: test whit id\n\n",
            ],
            [
                new Event('message', 'test whit retry', null, 1),
                "event: message\nretry: 1000\ndata: test whit retry\n\n",
            ],
            [
                new Event('message', 'complete test', 'id', 2),
                "event: message\nid: id\nretry: 2000\ndata: complete test\n\n",
            ],
            [
                new Event('message', "first line\nsecond line\r\nthird line\rfourth line"),
                "event: message\ndata: first line\ndata: second line\ndata: third line\ndata: fourth line\n\n",
            ],
            [
                new Event('message'),
                "event: message\ndata: \n\n",
            ],
            [
                new Event(null, 'no event name'),
                "data: no event name\n\n",
            ],
        ];
    }

    /**
     * @return resource
     */
    private function openMemoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }
}
