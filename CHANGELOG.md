# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Events were held back until the stream ended when output buffers were nested, for example `output_buffering`
  plus an `ob_start()` in a framework or plugin. The stream now flushes and closes the output buffers when it starts,
  as Symfony's `EventStreamResponse` does. On the command line they're kept, since tests use them to capture
  streams.

## [2.0.0] - 2026-10-23

The package is renamed from `dessimoney/event-source` to `funkyoz/event-source`, with a new API. Both can be
installed at the same time, since their namespaces differ. See [Upgrading from 1.x](docs/upgrading-from-1.x.md).

### Changed

- **Breaking:** the package is now `funkyoz/event-source`, with the `FunkyOz\EventSource\` namespace.
- **Breaking:** new API. `EventStream` defines a stream, with `onStart`, `onTick`, `onStop` and `onError`
  listeners. Listeners receive a `Stream`, which sends events and ends the stream with `stop()`.
- **Breaking:** `Event` is immutable, with public read-only fields and `with*()` methods. Its default name is `null`
  instead of `'message'`, and `retry` accepts fractions of a second.
- **Breaking:** no dependencies. `symfony/http-foundation` is only needed for the Symfony bridge.
- **Breaking:** the minimum PHP version is 8.2.
- Events are sent to the browser as soon as they are written, instead of at the end of an iteration.
- Ticks start at a fixed interval, whatever the listeners take.

### Added

- Plain PHP support with `EventStream::send()` and `EventStream::sendNoContent()`.
- Symfony bridge: `Bridge\Symfony\StreamedResponseFactory`, for `symfony/http-foundation` 6.4 to 8.x.
- Laravel bridge: the `response()->sse($stream)` macro, registered automatically, for Laravel 12 and 13.
- Generator streams with `EventStream::fromGenerator()`.
- `Last-Event-ID` support with `Stream::lastEventId()`, which follows the events sent.
- Heartbeats, a maximum duration and a reconnection delay for the whole stream: `heartbeat()`, `maxDuration()`
  and `retry()`.
- `StopReason`, which tells the stop listeners why the stream ended.
- `Event::json()`, and comments with `Stream::comment()`.
- Testing helpers: `Testing\StreamTester`, `Testing\MemoryOutput`, `Testing\FakeClock` and `Testing\EventParser`.
- `Output` and `Clock` interfaces, with `PhpOutput`, `StreamOutput` and `SystemClock`.
- Runnable examples in `examples/` (a counter, a chat, a progress bar, a background job, a dashboard, errors), with
  Symfony, Laravel and testing examples. Complete documentation in `docs/`, and the `LICENSE` file.

### Fixed

- The stream kept running only for one iteration: it now runs until it's stopped, the client disconnects or the
  maximum duration is reached.
- `id:` and `retry:` were written after the blank line that ends an event, so they applied to the next event.
- Multi-line data broke the stream.
- Every event asked the browser to wait 3 seconds before reconnecting.
- Stop listeners didn't run when the client disconnected, or when a listener threw an exception.
- Event names, ids and header values can no longer contain line breaks, which could inject fields into the stream.

### Removed

- `EventSender`, `EventBuffer` and `EventBufferInterface`. See [Upgrading from 1.x](docs/upgrading-from-1.x.md).

## [1.0.10] - 2025-11-13

### Removed

- The `Transfer-Encoding` header.

## [1.0.9] - 2025-11-13

### Fixed

- The output buffer is closed only when `EventSender` opened it.

## [1.0.8] - 2025-04-08

### Changed

- The minimum PHP version is now 8.1, and `symfony/http-foundation` 7 is required.

## [1.0.7] - 2022-03-08

### Changed

- Updated dependencies.

## [1.0.6] - 2021-09-18

### Changed

- Updated `symfony/http-foundation`.

## [1.0.5] - 2021-05-11

### Changed

- Widened the `symfony/http-foundation` version requirement.

## [1.0.4] - 2021-04-30

### Added

- `$flush` parameter on `EventBufferInterface::write()`, `false` by default.

## [1.0.3] - 2021-04-30

### Fixed

- Output is flushed after writing.

## [1.0.2] - 2021-04-27

### Changed

- Documented the minimum PHP version.

## [1.0.1] - 2021-04-26

### Added

- Custom response headers.

### Changed

- The constructor accepts the buffer.

## [1.0.0] - 2021-04-26

### Added

- First release.

[Unreleased]: https://github.com/FunkyOz/event-source/compare/2.0.0...HEAD
[2.0.0]: https://github.com/FunkyOz/event-source/compare/1.0.10...2.0.0
[1.0.10]: https://github.com/FunkyOz/event-source/compare/1.0.9...1.0.10
[1.0.9]: https://github.com/FunkyOz/event-source/compare/1.0.8...1.0.9
[1.0.8]: https://github.com/FunkyOz/event-source/compare/1.0.7...1.0.8
[1.0.7]: https://github.com/FunkyOz/event-source/compare/1.0.6...1.0.7
[1.0.6]: https://github.com/FunkyOz/event-source/compare/1.0.5...1.0.6
[1.0.5]: https://github.com/FunkyOz/event-source/compare/1.0.4...1.0.5
[1.0.4]: https://github.com/FunkyOz/event-source/compare/1.0.3...1.0.4
[1.0.3]: https://github.com/FunkyOz/event-source/compare/1.0.2...1.0.3
[1.0.2]: https://github.com/FunkyOz/event-source/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/FunkyOz/event-source/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/FunkyOz/event-source/releases/tag/1.0.0
