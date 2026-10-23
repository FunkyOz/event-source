# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Planned as 2.0.0: it contains breaking changes.

### Changed

- **Breaking:** write listeners are now called on every iteration until one of them returns `true` or the
  client disconnects. Before, they were called only once and the connection was closed after one second.
- **Breaking:** only a listener returning exactly `true` stops the stream.
- **Breaking:** `Event` no longer sets `retry` to 3 seconds by default; `retry:` is sent only when set.
- **Breaking:** the minimum PHP version is now 8.2.
- **Breaking:** `id:` and `retry:` are now written before `data:`, so the `id` belongs to its own event,
  following the specification.
- Supports `symfony/http-foundation` 6.4, 7.x and 8.x.
- `EventSender::setSleep()` accepts fractions of a second and rejects negative values.
- `Event` accepts a `null` event name in the constructor, as the setter already did.
- `EventBuffer` writes to `php://output` by default, or to any stream passed to the constructor.
- The output buffer is closed only when `EventSender` opened it.

### Added

- `RuntimeInterface` and `PhpRuntime`, to control flushing, sleeping and disconnection detection.
- `EventBuffer::format()` to serialize an event without writing it.
- A runnable example in `examples/`.
- The `LICENSE` file.

### Fixed

- Multi-line data is split into multiple `data:` fields instead of breaking the stream.
- Stop listeners are called even when the client disconnects.
- Output written by the last write listener and by stop listeners is flushed.
- The `Transfer-Encoding` header is no longer sent.

### Removed

- The `composer.lock` file: the library is tested against the lowest and the highest allowed dependencies.

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

[Unreleased]: https://github.com/FunkyOz/event-source/compare/1.0.7...HEAD
[1.0.7]: https://github.com/FunkyOz/event-source/compare/1.0.6...1.0.7
[1.0.6]: https://github.com/FunkyOz/event-source/compare/1.0.5...1.0.6
[1.0.5]: https://github.com/FunkyOz/event-source/compare/1.0.4...1.0.5
[1.0.4]: https://github.com/FunkyOz/event-source/compare/1.0.3...1.0.4
[1.0.3]: https://github.com/FunkyOz/event-source/compare/1.0.2...1.0.3
[1.0.2]: https://github.com/FunkyOz/event-source/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/FunkyOz/event-source/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/FunkyOz/event-source/releases/tag/1.0.0
