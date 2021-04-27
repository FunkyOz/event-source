# PHP Event Source (Server-Sent Events)

PHP Event Source is a simple library for handle Event Source API through HTTP protocol, followed specifics
at https://www.w3.org/TR/eventsource.

![GitHub all releases](https://img.shields.io/github/downloads/FunkyOz/event-source/total) ![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/FunkyOz/event-source?label=size)
## Getting started

### Requirements

php >= 7.1

### Installation

```bash
composer require dessimoney/event-source
```

## How to use
### Default usage
```php
use EventSource\EventSender;
use EventSource\EventBufferInterface;
use EventSource\Event;

$sender = new EventSender(); // Create new sender instance
// Configure sender adding listeners
$sender->addStartListener(
    function () {
        // What do you want when I'm starting?
    }
);
$sender->addWriteListener(
    function (EventBufferInterface $buffer) {
        $event = new Event('ping', 'ping at: ' . time());
        $buffer->write($event);
    }
);
$sender->addStopListener(
    function () {
        // What do you want when I'm stopping?
    }
);

$sender->send(); 
```

### Custom usage
```php
use EventSource\EventBufferInterface;
use EventSource\Event;
use EventSource\EventSender;

// If you want to use a custom buffer you can extend \EventSource\EventBufferInterface
class MyOwnBuffer implements EventBufferInterface {
    public function write(Event $event) : void 
    {
        echo 'MyOwnBuffer write this';
    }
}

// And the set to EventSender instance
$sender = new EventSender();
$sender->setBuffer(new MyOwnBuffer());

```

## License

Built under [MIT](https://choosealicense.com/licenses/mit/) license.

## Authors and Copyright

Lorenzo Dessimoni - lorenzo.dessimoni@gmail.com
