<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Tooling;

use FunkyOz\EventSource\Tooling\Changelog;
use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
        # Changelog

        ## [Unreleased]

        ### Added

        - Something new.

        ## [1.1.0] - 2026-01-10

        ### Fixed

        - A bug.

        ## [1.0.0] - 2025-12-01

        - First release.

        [Unreleased]: https://github.com/FunkyOz/event-source/compare/1.1.0...HEAD
        [1.1.0]: https://github.com/FunkyOz/event-source/compare/1.0.0...1.1.0
        [1.0.0]: https://github.com/FunkyOz/event-source/releases/tag/1.0.0

        MD;

    public function testPrepareMovesTheUnreleasedEntriesAndUpdatesTheLinks(): void
    {
        $prepared = (new Changelog(self::CHANGELOG))->prepare('1.2.0', '2026-02-03');

        self::assertSame(<<<'MD'
            # Changelog

            ## [Unreleased]

            ## [1.2.0] - 2026-02-03

            ### Added

            - Something new.

            ## [1.1.0] - 2026-01-10

            ### Fixed

            - A bug.

            ## [1.0.0] - 2025-12-01

            - First release.

            [Unreleased]: https://github.com/FunkyOz/event-source/compare/1.2.0...HEAD
            [1.2.0]: https://github.com/FunkyOz/event-source/compare/1.1.0...1.2.0
            [1.1.0]: https://github.com/FunkyOz/event-source/compare/1.0.0...1.1.0
            [1.0.0]: https://github.com/FunkyOz/event-source/releases/tag/1.0.0

            MD, $prepared);
        self::assertSame('1.2.0', (new Changelog($prepared))->latestVersion());
    }

    public function testNotes(): void
    {
        $changelog = new Changelog(self::CHANGELOG);

        self::assertSame("### Fixed\n\n- A bug.\n", $changelog->notes('1.1.0'));
        // The last section ends at the link references
        self::assertSame("- First release.\n", $changelog->notes('1.0.0'));
    }

    public function testNotesMakeRelativeLinksAbsolute(): void
    {
        $changelog = new Changelog(<<<'MD'
            ## [1.1.0] - 2026-01-10

            - See [the guide](docs/guide.md), [an anchor](#top), [a site](https://example.com) and [mail](mailto:a@b.c).

            [Unreleased]: https://github.com/FunkyOz/event-source/compare/1.1.0...HEAD

            MD);

        self::assertSame(
            "- See [the guide](https://github.com/FunkyOz/event-source/blob/1.1.0/docs/guide.md), [an anchor](#top), [a site](https://example.com) and [mail](mailto:a@b.c).\n",
            $changelog->notes('1.1.0'),
        );
    }

    public function testFirstRelease(): void
    {
        $changelog = new Changelog("## [Unreleased]\n\n- First.\n\n[Unreleased]: https://github.com/a/b/compare/0.0.0...HEAD\n");

        self::assertNull($changelog->latestVersion());
        self::assertStringContainsString(
            "[Unreleased]: https://github.com/a/b/compare/1.0.0...HEAD\n[1.0.0]: https://github.com/a/b/releases/tag/1.0.0\n",
            $changelog->prepare('1.0.0', '2026-01-01'),
        );
    }

    public function testPreReleaseVersions(): void
    {
        $prepared = (new Changelog(self::CHANGELOG))->prepare('2.0.0-beta.1', '2026-02-03');

        self::assertStringContainsString('## [2.0.0-beta.1] - 2026-02-03', $prepared);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideInvalidPreparations(): iterable
    {
        yield 'not a version' => ['v1.2', '2026-02-03', 'Invalid version'];
        yield 'invalid date' => ['1.2.0', '03/02/2026', 'Invalid date'];
        yield 'existing version' => ['1.1.0', '2026-02-03', 'already in the changelog'];
        yield 'older version' => ['1.0.5', '2026-02-03', 'must be greater than the latest version, 1.1.0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideInvalidPreparations')]
    public function testRejectsInvalidPreparations(string $version, string $date, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new Changelog(self::CHANGELOG))->prepare($version, $date);
    }

    public function testRefusesToReleaseNothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing to release');
        (new Changelog("## [Unreleased]\n\n## [1.0.0] - 2025-01-01\n\n- First.\n"))->prepare('1.1.0', '2026-01-01');
    }

    public function testRequiresAnUnreleasedSection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no "## [Unreleased]" section');
        (new Changelog("## [1.0.0] - 2025-01-01\n\n- First.\n"))->prepare('1.1.0', '2026-01-01');
    }

    public function testNotesOfAnUnknownVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('composer release:prepare 9.9.9');
        (new Changelog(self::CHANGELOG))->notes('9.9.9');
    }

    public function testNotesOfAnEmptyVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('section of version 1.0.0 is empty');
        (new Changelog("## [1.0.0] - 2025-01-01\n\n"))->notes('1.0.0');
    }

    public function testTheRealChangelogIsValid(): void
    {
        $changelog = Changelog::fromFile(__DIR__ . '/../../CHANGELOG.md');

        self::assertSame('1.0.10', $changelog->latestVersion());
        self::assertStringContainsString('## [2.0.0] - ', $changelog->prepare('2.0.0', '2026-10-23'));
    }

    public function testMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Changelog::fromFile(__DIR__ . '/missing.md');
    }
}
