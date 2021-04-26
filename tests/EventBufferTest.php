<?php

declare(strict_types=1);

namespace Test;

use EventSource\EventSource;
use EventSource\EventSourceBuffer;
use PHPUnit\Framework\TestCase;

class EventSourceBufferTest extends TestCase
{
    /**
     * @param EventSource $event
     * @param string|null $payload
     * @dataProvider provide_write_data
     */
    public function test_write(EventSource $event, ?string $payload)
    {
        $this->expectOutputString($payload);
        $buffer = new EventSourceBuffer;
        $buffer->write($event);
    }

    public function provide_write_data(): array
    {
        return [
            [
                new EventSource('message', 'this is a test'),
                'event: message
data: "this is a test"

retry: 3000
'
            ],
            [
                new EventSource('json', json_encode(['key' => 'test'])),
                'event: json
data: "{\"key\":\"test\"}"

retry: 3000
'
            ],
            [
                new EventSource('message', 'test whit id', 'id-test'),
                'event: message
data: "test whit id"

id: id-test
retry: 3000
'
            ],
            [
                new EventSource('message', 'test whit retry', null, 1),
                'event: message
data: "test whit retry"

retry: 1000
'
            ],
            [
                new EventSource('message', 'complete test', 'id', 2),
                'event: message
data: "complete test"

id: id
retry: 2000
'
            ],
        ];
    }
}
