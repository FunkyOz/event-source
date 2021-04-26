<?php

declare(strict_types=1);

namespace Test;

use EventSource\Event;
use EventSource\EventBuffer;
use PHPUnit\Framework\TestCase;

class EventBufferTest extends TestCase
{
    /**
     * @param Event $event
     * @param string|null $payload
     * @dataProvider provide_write_data
     */
    public function test_write(Event $event, ?string $payload)
    {
        $this->expectOutputString($payload);
        $buffer = new EventBuffer;
        $buffer->write($event);
    }

    public function provide_write_data(): array
    {
        return [
            [
                new Event('message', 'this is a test'),
                'event: message
data: this is a test

retry: 3000
'
            ],
            [
                new Event('json', json_encode(['key' => 'test'])),
                'event: json
data: {"key":"test"}

retry: 3000
'
            ],
            [
                new Event('message', 'test whit id', 'id-test'),
                'event: message
data: test whit id

id: id-test
retry: 3000
'
            ],
            [
                new Event('message', 'test whit retry', null, 1),
                'event: message
data: test whit retry

retry: 1000
'
            ],
            [
                new Event('message', 'complete test', 'id', 2),
                'event: message
data: complete test

id: id
retry: 2000
'
            ],
        ];
    }
}
