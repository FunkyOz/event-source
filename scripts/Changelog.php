<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tooling;

/**
 * Reads and updates CHANGELOG.md, which follows the Keep a Changelog format
 *
 * @see https://keepachangelog.com/en/1.1.0/
 */
final readonly class Changelog
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/';
    private const HEADING_PATTERN = '/^## \[([^\]]+)\].*$/m';

    public function __construct(private string $contents) {}

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $path));
        }

        return new self($contents);
    }

    /**
     * Move the Unreleased entries under a new version, and update the comparison links
     */
    public function prepare(string $version, string $date): string
    {
        self::assertVersion($version);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException(sprintf('Invalid date "%s", expected YYYY-MM-DD.', $date));
        }
        if (null !== $this->findSection($version)) {
            throw new \InvalidArgumentException(sprintf('Version %s is already in the changelog.', $version));
        }
        $previous = $this->latestVersion();
        if (null !== $previous && version_compare($version, $previous, '<=')) {
            throw new \InvalidArgumentException(sprintf('Version %s must be greater than the latest version, %s.', $version, $previous));
        }

        $unreleased = $this->findSection('Unreleased');
        if (null === $unreleased) {
            throw new \RuntimeException('The changelog has no "## [Unreleased]" section.');
        }
        if ('' === trim($unreleased['body'])) {
            throw new \RuntimeException('The "Unreleased" section is empty: there is nothing to release.');
        }

        $contents = substr_replace(
            $this->contents,
            "## [Unreleased]\n\n## [{$version}] - {$date}\n\n" . trim($unreleased['body']) . "\n\n",
            $unreleased['offset'],
            $unreleased['length'],
        );

        return $this->updateLinks($contents, $version, $previous);
    }

    /**
     * The entries of a version, used as the notes of its GitHub release. Relative links point to the repository,
     * so they're made absolute, to the files as they were at that version
     */
    public function notes(string $version): string
    {
        $section = $this->findSection($version);
        if (null === $section) {
            throw new \InvalidArgumentException(sprintf('Version %s is not in the changelog. Run "composer release:prepare %s" first.', $version, $version));
        }
        $notes = trim($section['body']);
        if ('' === $notes) {
            throw new \RuntimeException(sprintf('The changelog section of version %s is empty.', $version));
        }

        $repository = $this->repositoryUrl();
        if (null !== $repository) {
            $notes = (string) preg_replace(
                '/\]\((?!https?:\/\/|#|mailto:)([^)\s]+)\)/',
                "]({$repository}/blob/{$version}/\$1)",
                $notes,
            );
        }

        return $notes . "\n";
    }

    /**
     * The most recent released version, the first one after Unreleased
     */
    public function latestVersion(): ?string
    {
        preg_match_all(self::HEADING_PATTERN, $this->contents, $matches);
        foreach ($matches[1] as $name) {
            if ('Unreleased' !== $name) {
                return $name;
            }
        }

        return null;
    }

    public static function assertVersion(string $version): void
    {
        if (1 !== preg_match(self::VERSION_PATTERN, $version)) {
            throw new \InvalidArgumentException(sprintf('Invalid version "%s", expected a semantic version like 2.1.0 or 2.1.0-beta.1.', $version));
        }
    }

    /**
     * @return array{offset: int, length: int, body: string}|null The whole section, heading included, and its body
     */
    private function findSection(string $name): ?array
    {
        preg_match_all(self::HEADING_PATTERN, $this->contents, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $index => [$heading, $_]) {
            if ($heading !== $name) {
                continue;
            }
            $start = $matches[0][$index][1];
            $bodyStart = $start + strlen($matches[0][$index][0]);
            // The section ends at the next heading, or at the link references
            $end = $matches[0][$index + 1][1] ?? $this->linksOffset() ?? strlen($this->contents);

            return [
                'offset' => $start,
                'length' => $end - $start,
                'body' => substr($this->contents, $bodyStart, $end - $bodyStart),
            ];
        }

        return null;
    }

    /**
     * The repository URL, from the comparison links
     */
    private function repositoryUrl(): ?string
    {
        return 1 === preg_match('/^\[Unreleased\]: (\S+)\/compare\//m', $this->contents, $match) ? $match[1] : null;
    }

    private function linksOffset(): ?int
    {
        return 1 === preg_match('/^\[[^\]]+\]: /m', $this->contents, $match, PREG_OFFSET_CAPTURE) ? $match[0][1] : null;
    }

    private function updateLinks(string $contents, string $version, ?string $previous): string
    {
        if (1 !== preg_match('/^\[Unreleased\]: (.+\/compare\/)\S+\.\.\.HEAD$/m', $contents, $match)) {
            return $contents;
        }
        $base = $match[1];
        $links = "[Unreleased]: {$base}{$version}...HEAD\n";
        $links .= null === $previous
            ? "[{$version}]: " . str_replace('/compare/', '/releases/tag/', $base) . "{$version}\n"
            : "[{$version}]: {$base}{$previous}...{$version}\n";

        return str_replace($match[0] . "\n", $links, $contents);
    }
}
