# Events

An `Event` is one message sent to the browser. It's an immutable value object with four fields, all optional.

```php
use FunkyOz\EventSource\Event;

new Event(
    name: 'order-update',               // ?string, null by default
    data: '{"id":42,"status":"paid"}',  // string, '' by default
    id: '42',                           // ?string, null by default
    retry: 5,                           // ?float, in seconds, null by default
);
```

Send it from a listener with `$stream->send($event)`. It's written and flushed to the browser immediately.

## Name

The name is the type of the event. The browser receives each type with its own listener:

```js
source.addEventListener('order-update', (event) => { /* … */ });
```

Events without a name (`null`, the default) are received by `onmessage`, or by `addEventListener('message', …)`:

```php
$stream->send(new Event(data: 'hello'));
```

```js
source.onmessage = (event) => console.log(event.data);
```

An event named `'message'` is received the same way as an unnamed one.

## Data

The data is always a string. The browser gets it in `event.data`.

**Multi-line data** is supported. Each line is sent as a separate `data:` field, and the browser joins them back with
`\n`. Windows (`\r\n`) and old Mac (`\r`) line breaks are converted to `\n`.

```php
$stream->send(new Event('log', "first line\nsecond line"));
```

```
event: log
data: first line
data: second line

```

**Empty data** is allowed: `new Event('done')` sends an event with an empty `event.data`. Use it for signals that
carry no payload.

## JSON

`Event::json()` encodes the data as JSON. The arguments are in the same order as the constructor:

```php
$stream->send(Event::json('order-update', ['id' => 42, 'status' => 'paid'], id: '42'));
```

```js
source.addEventListener('order-update', (event) => {
    const order = JSON.parse(event.data);
});
```

- Anything `json_encode()` accepts works, including objects implementing `JsonSerializable`.
- Slashes and Unicode characters aren't escaped, so the output stays readable: `"https://example.com"`, `"Caffè"`.
- Encoding errors throw a `JsonException`, for example with invalid UTF-8, instead of silently sending `false`.

## Id

The id lets the browser resume after a reconnection. When the connection drops, the browser reconnects and sends
the id of the last event it received, in the `Last-Event-ID` header. See
[Resuming after a reconnection](controlling-streams.md#resuming-after-a-reconnection).

```php
$stream->send(new Event('notification', $json, id: (string) $notification->id));
```

- Use ids that let you find what comes after: an auto-increment id, a timestamp, a sequence number.
- An empty id (`''`) resets the last event id: the next reconnection won't send `Last-Event-ID`.
- `$stream->lastEventId()` follows the ids you send.

## Retry

`retry` tells the browser how many seconds to wait before reconnecting, if the connection drops. It's sent in
milliseconds, rounded: `1.5` becomes `retry: 1500`.

```php
$stream->send(new Event('slow-down', retry: 30));
```

The browser keeps the value for all the following reconnections. To set it once for the whole stream, use
[`EventStream::retry()`](controlling-streams.md#reconnection-delay) instead.

## Comments

Comments are lines the browser ignores. They're useful to keep a connection active, which is what
[heartbeats](controlling-streams.md#heartbeats) do, or to leave debugging hints in the raw stream:

```php
$stream->comment('cache miss, querying the database');
```

```
: cache miss, querying the database
```

## Changing an event

Events are immutable. The `with*()` methods return a modified copy:

```php
$template = new Event('notification', retry: 10);

$stream->send($template->withData('First')->withId('1'));
$stream->send($template->withData('Second')->withId('2'));
```

| Method | Changes |
|---|---|
| `withName(?string $name)` | The name |
| `withData(string $data)` | The data |
| `withId(?string $id)` | The id |
| `withRetry(?float $retry)` | The retry delay |

The fields are public and read-only: `$event->name`, `$event->data`, `$event->id`, `$event->retry`.

## Validation

A line break inside a name or an id would start a new field, and could inject anything into the stream. So `Event`
rejects these values with an `InvalidArgumentException`:

| Field | Rejected |
|---|---|
| `name` | `\r` or `\n` |
| `id` | `\r`, `\n` or a NUL character |
| `retry` | Negative numbers |

Data can contain anything, since its line breaks are handled.

If ids or names come from user input, validate them before creating the event, or catch the exception.

## The wire format

For reference, this is what `$stream->send(new Event('tick', "a\nb", '7', 2))` writes:

```
event: tick
id: 7
retry: 2000
data: a
data: b

```

The blank line at the end tells the browser the event is complete. `EventFormatter::format($event)` returns this
text without sending it, which is useful for logging. See the [API reference](api-reference.md#eventformatter).
