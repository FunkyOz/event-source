# Examples

Runnable examples, each showing one feature of the library. From the repository:

```bash
composer install
composer example
```

Then open http://localhost:8000. `composer example` runs PHP's built-in server with 8 workers, since every open
stream keeps one busy.

| Example | Shows | Code |
|---|---|---|
| [Counter](counter/) | Event ids, automatic resuming with `Last-Event-ID` after `maxDuration()`, a final event | [`events.php`](counter/events.php) |
| [Chat](chat/) | A tick stream reading a database, history on the first connection, new messages in every tab | [`ChatStream.php`](chat/ChatStream.php), [`send.php`](chat/send.php) |
| [Progress bar](progress/) | A generator stream that runs a task and reports each step | [`events.php`](progress/events.php) |
| [Background job](job/) | Following a job that runs elsewhere, surviving a page reload, `204 No Content` when it's over | [`events.php`](job/events.php), [`JobStore.php`](job/JobStore.php) |
| [Dashboard](dashboard/) | Sending only changes, with heartbeats in between | [`events.php`](dashboard/events.php) |
| [Errors](errors/) | `onError`, `StopReason` in `onStop`, and the browser reconnecting with `retry()` | [`events.php`](errors/events.php) |

The chat and the background job store their data in a small SQLite database, in your temporary directory
(`event-source-examples.sqlite`). They need the `pdo_sqlite` extension, which most PHP installations have.

## Framework examples

To copy into an application: they don't run on their own.

| File | Shows |
|---|---|
| [`frameworks/symfony/NotificationController.php`](frameworks/symfony/NotificationController.php) | A Symfony controller, with `StreamedResponseFactory` and the session lock released |
| [`frameworks/laravel/routes.php`](frameworks/laravel/routes.php) | A Laravel route, with `response()->sse()` and the query builder |

## Testing example

[`testing/ChatStreamTest.php`](testing/ChatStreamTest.php) tests the chat stream with `StreamTester`: history,
resuming, new messages during the stream, and timing. It runs with the library's own tests.

## Kept working

Every example runs in the library's test suite, through PHP's built-in server
([`tests/Integration/ExamplesTest.php`](../tests/Integration/ExamplesTest.php)), so they can't silently break.
