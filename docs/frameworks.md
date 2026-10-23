# Frameworks

In plain PHP, `$stream->send()` sends everything. In a framework, return a response instead: the framework sends
it, and its middleware, event listeners and profiler keep working.

The core of the library has no dependencies. The bridges use the framework's own classes, so they only work when
the framework is installed.

## Symfony

Requires `symfony/http-foundation` 6.4 to 8.x, which every Symfony application has.

```php
use FunkyOz\EventSource\Bridge\Symfony\StreamedResponseFactory;
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController
{
    #[Route('/notifications/stream')]
    public function stream(Request $request): StreamedResponse
    {
        $stream = EventStream::create()
            ->every(2)
            ->heartbeat(15)
            ->maxDuration(300)
            ->onTick(function (Stream $stream) {
                // …
            });

        return StreamedResponseFactory::create($stream, $request);
    }
}
```

- `create()` returns a standard `StreamedResponse`, with the stream's headers.
- Pass the `Request`, so the stream receives the `Last-Event-ID` header. Without it, `lastEventId()` starts as
  `null`.
- The stream only runs when Symfony sends the response.
- `StreamedResponseFactory::noContent()` returns a `204 No Content` response, which tells the browser to stop
  reconnecting.

### Sessions

Symfony uses PHP's native sessions, which are **locked** for the whole request. While the stream is open, the same
user's other requests would wait. Save the session before returning the response:

```php
$userId = $request->getSession()->get('user_id');
$request->getSession()->save(); // releases the lock

return StreamedResponseFactory::create($stream, $request);
```

### Defining streams as services

A stream definition holds no state from a run, so it can be built by a service and reused:

```php
final class NotificationStream
{
    public function __construct(private readonly NotificationRepository $notifications) {}

    public function forUser(int $userId): EventStream
    {
        return EventStream::create()
            ->onTick(function (Stream $stream) use ($userId) {
                foreach ($this->notifications->newerThan($userId, $stream->lastEventId()) as $notification) {
                    $stream->send(Event::json('notification', $notification, id: (string) $notification->getId()));
                }
            });
    }
}
```

## Laravel

Requires Laravel 12 or 13. Package discovery registers the service provider automatically, which adds a `sse`
response macro:

```php
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use Illuminate\Support\Facades\Route;

Route::get('/notifications/stream', function () {
    $stream = EventStream::create()
        ->every(2)
        ->heartbeat(15)
        ->maxDuration(300)
        ->onTick(function (Stream $stream) {
            // …
        });

    return response()->sse($stream);
})->middleware('auth');
```

- `response()->sse()` reads `Last-Event-ID` from the current request.
- It returns a Symfony `StreamedResponse`, which Laravel sends like any response.
- To stop the browser from reconnecting, return `response()->noContent()`.

Laravel's own `response()->eventStream()` is a different, simpler feature. The two can be used side by side.

### Registering the provider yourself

If you turned off package discovery for this package, register the provider in `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    FunkyOz\EventSource\Bridge\Laravel\EventSourceServiceProvider::class,
];
```

### Name conflicts

If Laravel or another package already defines `response()->sse()`, the provider throws a `LogicException` when the
application boots, instead of silently using the other one. Use the Symfony bridge, which works in Laravel too:

```php
use FunkyOz\EventSource\Bridge\Symfony\StreamedResponseFactory;

Route::get('/notifications/stream', fn (Request $request) => StreamedResponseFactory::create($stream, $request));
```

### Laravel Octane

Octane isn't supported: the stream waits between ticks, which would block an Octane worker for every connected
client. Serve the stream route with PHP-FPM.

## Other frameworks

### Frameworks that accept Symfony responses

Drupal, and anything built on Symfony's HttpFoundation, can use `StreamedResponseFactory` as shown above.

### Any framework

`$stream->send()` works anywhere, as long as nothing was output yet. It bypasses the framework's response pipeline,
so stop the request right after it:

```php
public function stream(): never
{
    EventStream::create()->/* … */->send();
    exit;
}
```

For a proper integration, wrap the stream in the framework's own streamed response. See
[Writing an adapter](extending.md#writing-an-adapter-for-a-framework).
