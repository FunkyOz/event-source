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

The tests are split in suites, which you can run on their own with `vendor/bin/phpunit --testsuite <name>`:

| Suite | What it tests | Needs |
|---|---|---|
| `core` | The library, plus end-to-end tests over HTTP with PHP's built-in server | Nothing |
| `symfony` | The Symfony bridge | `symfony/http-foundation` |
| `laravel` | The Laravel bridge, in a real application | `orchestra/testbench` |

The core has no dependencies, and CI checks it with no framework installed. Keep `src/` free of framework code,
except in `src/Bridge/`.

To test against the lowest supported dependencies, like CI does:

```bash
composer update --prefer-lowest && composer tests:unit
composer update
```

## Trying changes in the browser

```bash
composer example
```

Then open http://localhost:8000. See [`examples/`](examples). Every example also runs in the `core` test suite
(`tests/Integration/ExamplesTest.php`): when you add one, add it there too.

## Pull requests

- Add or update tests for every change. Streams can be tested without waiting with `Testing\StreamTester`, or
  with `Testing\MemoryOutput` and `Testing\FakeClock` for finer control.
- Add a line to `CHANGELOG.md` under "Unreleased".
- Update the README if the public API changes.
- Keep pull requests focused on one change.

## Releases

The project follows [Semantic Versioning](https://semver.org), and the changelog follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Releases are made by pushing a tag: a workflow does the
rest.

### Releasing a version

1. **Prepare the changelog.** On a new branch, run:

   ```bash
   composer release:prepare 2.1.0
   ```

   It moves the "Unreleased" entries under `## [2.1.0] - <today>` and updates the comparison links. It refuses
   invalid or older versions, and an empty "Unreleased" section. Review the result.

2. **Merge it.** Open a pull request with the changelog, and merge it once CI passes.

3. **Tag the merged commit:**

   ```bash
   git switch main && git pull
   git tag 2.1.0
   git push origin 2.1.0
   ```

   Or create the tag from GitHub's "Draft a new release" page, on `main`.

The [release workflow](.github/workflows/release.yml) then:

- checks that the tag is a semantic version, and that the tagged commit is on `main`;
- waits for CI on that commit, and fails if it didn't pass;
- creates the GitHub release, with the version's changelog section as notes. Versions like `2.1.0-beta.1` are
  marked as pre-releases.

Packagist picks up the tag through its GitHub hook, usually within a minute. Check it on
[packagist.org/packages/funkyoz/event-source](https://packagist.org/packages/funkyoz/event-source).

To preview the notes of a version: `composer release:notes 2.1.0`.

### If the release workflow fails

Nothing is published on GitHub, but Packagist may already have picked up the tag. Fix the cause, then either run
the workflow again from the Actions tab, or, if the tag itself is wrong, delete it and tag again:

```bash
git push --delete origin 2.1.0
git tag --delete 2.1.0
```

Only delete a tag right after pushing it: once people installed a version, never change it. Release a new one.

### Protecting tags

Only maintainers should be able to create version tags. A repository ruleset enforces it. Create it once, with
admin rights on the repository:

```bash
gh api --method POST repos/FunkyOz/event-source/rulesets --input - <<'JSON'
{
  "name": "Version tags",
  "target": "tag",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["refs/tags/*.*.*"], "exclude": [] } },
  "rules": [{ "type": "creation" }, { "type": "update" }, { "type": "deletion" }],
  "bypass_actors": [{ "actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "always" }]
}
JSON
```

Repository admins (role 5) can still create, and if needed delete, tags. Everyone else can't.
