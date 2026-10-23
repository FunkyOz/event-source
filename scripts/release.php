<?php

declare(strict_types=1);

/*
 * Release helper, used by the composer scripts and the release workflow:
 *
 *   php scripts/release.php prepare <version> [<date>]   Move the Unreleased entries under <version>
 *   php scripts/release.php notes <version>              Print the entries of <version>
 */

use FunkyOz\EventSource\Tooling\Changelog;

// No autoloader: the release workflow runs this without installing the dependencies
require __DIR__ . '/Changelog.php';

if (!isset($argv)) {
    exit('Run this script from the command line.');
}

$path = __DIR__ . '/../CHANGELOG.md';
[, $command, $version] = $argv + [null, null, null];

try {
    if (null === $version) {
        throw new InvalidArgumentException('Usage: php scripts/release.php prepare|notes <version> [<date>]');
    }
    $changelog = Changelog::fromFile($path);

    switch ($command) {
        case 'prepare':
            file_put_contents($path, $changelog->prepare($version, $argv[3] ?? date('Y-m-d')));
            fwrite(STDOUT, <<<TEXT
                CHANGELOG.md is ready for {$version}. Next:
                  1. Review it, then open a pull request with it, and merge it once CI passes.
                  2. Tag the merged commit: git tag {$version} && git push origin {$version}
                  The release workflow then creates the GitHub release, and Packagist picks up the tag.

                TEXT);
            break;
        case 'notes':
            fwrite(STDOUT, $changelog->notes($version));
            break;
        default:
            throw new InvalidArgumentException(sprintf('Unknown command "%s". Use "prepare" or "notes".', $command));
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
