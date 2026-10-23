# Contributing

Thanks for helping improve this library! Bug reports, ideas and pull requests are welcome.

## Setup

You need PHP 8.2 or newer and [Composer](https://getcomposer.org).

```bash
git clone git@github.com:FunkyOz/event-source.git
cd event-source
composer install
```

There is no `composer.lock`: as a library, it is tested against both the lowest and the highest allowed
versions of its dependencies, so `composer install` always resolves the latest versions for your PHP.

## Checks

Run every check that CI runs before opening a pull request:

```bash
composer tests
```

| Command | What it does |
|---|---|
| `composer cs:check` / `composer cs:fix` | Check or fix the code style (PER Coding Style, with PHP-CS-Fixer) |
| `composer rector:check` / `composer rector:fix` | Show or apply automatic code modernisations (Rector) |
| `composer tests:types` | Static analysis (PHPStan, level 9) |
| `composer tests:unit` | Unit tests (PHPUnit) |
| `composer tests:coverage` | Unit tests with a coverage report, needs [pcov](https://github.com/krakjoe/pcov) or Xdebug |
| `composer tests:mutation` | Mutation tests (Infection), needs pcov or Xdebug |

To test against the lowest supported dependencies, like CI does:

```bash
composer update --prefer-lowest && composer tests:unit
composer update
```

## Trying changes in the browser

```bash
composer example
```

Then open http://localhost:8000. See [`examples/`](examples).

## Pull requests

- Add or update tests for every change. Code that writes to the output or depends on time can be tested
  with a stream (`new EventBuffer($stream)`) and a fake `RuntimeInterface` (see `tests/Support`).
- Add a line to `CHANGELOG.md` under "Unreleased".
- Update the README if the public API changes.
- Keep pull requests focused on one change.

## Releases

The project follows [Semantic Versioning](https://semver.org):

1. Move the "Unreleased" entries in `CHANGELOG.md` under the new version and date.
2. Tag the release (e.g. `git tag 2.0.0 && git push --tags`). Packagist picks up the tag automatically.
3. Create a GitHub release from the tag, with the changelog entries as description.
