# Documentation

`funkyoz/event-source` streams real-time updates from PHP to the browser with
[Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html), in plain PHP, Symfony or
Laravel.

## Guides

Start here, in this order:

1. [Getting started](getting-started.md): install the library and send a first stream to the browser.
2. [How it works](how-it-works.md): the lifecycle of a stream, and the three types you use.
3. [Events](events.md): names, data, JSON, ids, comments.
4. [Controlling streams](controlling-streams.md): frequency, stopping, resuming, heartbeats, maximum duration,
   headers.
5. [Generator streams](generator-streams.md): streams with a natural end, written as a generator.
6. [Error handling](error-handling.md): what happens when a listener throws.
7. [Frameworks](frameworks.md): Symfony, Laravel and other frameworks.
8. [Testing](testing.md): check what a stream sends, without a server or waiting.
9. [Running in production](production.md): PHP-FPM, nginx, Apache, sessions, timeouts, scaling.

## Reference

- [Examples](../examples): runnable demos, framework examples and a test example. Run them with
  `composer example`.
- [Recipes](recipes.md): complete examples for common needs.
- [Troubleshooting](troubleshooting.md): symptoms, causes and fixes.
- [Extending](extending.md): custom outputs and clocks, and adapters for other frameworks.
- [API reference](api-reference.md): every public class and method.
- [Upgrading from 1.x](upgrading-from-1.x.md): migrating from `dessimoney/event-source`.

## For contributors

- [Contributing](../CONTRIBUTING.md): setup, checks and releases.
- [Design of 2.0](design/2.0.md): the decisions behind the current API.
- [Changelog](../CHANGELOG.md)

## Requirements

| | Supported versions |
|---|---|
| PHP | 8.2 to 8.5 |
| Symfony bridge | `symfony/http-foundation` 6.4 to 8.x |
| Laravel bridge | Laravel 12 and 13 |
| Servers | PHP-FPM, Apache with mod_php, and PHP's built-in server. Async servers (Octane, Swoole, RoadRunner, ReactPHP) aren't supported, see [Running in production](production.md#async-servers) |
