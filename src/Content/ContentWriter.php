<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Card\CardFile;
use App\Content\Facts\FactsFile;
use App\Git\Worktree;

/**
 * Writes one change into the `content` repository (SPEC.md § 5.1, § 5.6).
 *
 * The layout is the interface: `changes/<year>/<change-id>/facts.yml` plus one `<lang>.md` per
 * language. Because the directory name is the natural change id of § 24.1, the repository can be
 * read back into the database without a mapping table — that is what makes `rebuild-from-git`
 * possible (§ 24.11).
 *
 * Like the laws writer, this one never writes a timestamp: the same input must produce the same
 * bytes, or every pipeline rerun would open a pull request for nothing.
 */
final readonly class ContentWriter
{
    public function __construct(
        private FactsFile $facts = new FactsFile(),
        private CardFile $cards = new CardFile(),
    ) {
    }

    /**
     * `changes/2026/2026-bund-bgbl-i-123`.
     */
    public function directoryFor(string $changeId, ?int $year = null): string
    {
        return \sprintf('changes/%d/%s', $year ?? $this->yearOf($changeId), $changeId);
    }

    /**
     * Every file of the change, keyed by its path in the repository.
     *
     * @param array<string, mixed>                                                                                                         $facts
     * @param array<string, array{sections: array<string, string>, front_matter?: array<string, mixed>, headings?: array<string, string>}> $cards keyed by language
     *
     * @return array<string, string>
     */
    public function renderFiles(string $changeId, array $facts, array $cards): array
    {
        $directory = $this->directoryFor($changeId, $this->yearOf($changeId, $facts));

        $files = [$directory.'/'.FactsFile::FILENAME => $this->facts->render($facts)];

        // Sorted by language so the file list is stable whatever order the translations finished in.
        ksort($cards);

        foreach ($cards as $language => $card) {
            $frontMatter = ['lang' => $language] + ($card['front_matter'] ?? []);

            $files[$directory.'/'.$language.'.md'] = $this->cards->render(
                $frontMatter,
                $card['sections'],
                $card['headings'] ?? [],
            );
        }

        return $files;
    }

    /**
     * @param array<string, mixed>                                                                                                         $facts
     * @param array<string, array{sections: array<string, string>, front_matter?: array<string, mixed>, headings?: array<string, string>}> $cards
     *
     * @return list<string> the paths written
     */
    public function write(Worktree $worktree, string $changeId, array $facts, array $cards): array
    {
        $written = [];

        foreach ($this->renderFiles($changeId, $facts, $cards) as $path => $contents) {
            $worktree->writeFile($path, $contents);
            $written[] = $path;
        }

        return $written;
    }

    /**
     * Reads the change back out of the repository, which is how the importer and
     * `rebuild-from-git` work (SPEC.md § 24.11).
     *
     * @return array{facts: array<string, mixed>, cards: array<string, Card\ParsedCard>}|null
     */
    public function read(Worktree $worktree, string $changeId): ?array
    {
        $directory = $this->directoryFor($changeId);
        $factsYaml = $worktree->readFile($directory.'/'.FactsFile::FILENAME);

        if (null === $factsYaml) {
            return null;
        }

        $facts = $this->facts->parse($factsYaml);
        $cards = [];

        foreach ($worktree->listFiles($directory) as $path) {
            if (1 !== preg_match('#/(?<lang>[a-z]{2})\.md$#', $path, $matches)) {
                continue;
            }

            $contents = $worktree->readFile($path);

            if (null !== $contents) {
                $cards[$matches['lang']] = $this->cards->parse($contents);
            }
        }

        ksort($cards);

        return ['facts' => $facts, 'cards' => $cards];
    }

    /**
     * The year of the change id ("2026-bund-…"), falling back to the promulgation date and finally
     * to today — a change must never be filed under a year that is not in its own id.
     *
     * @param array<string, mixed> $facts
     */
    private function yearOf(string $changeId, array $facts = []): int
    {
        if (1 === preg_match('/^(\d{4})-/', $changeId, $matches)) {
            return (int) $matches[1];
        }

        /** @var array<string, mixed> $dates */
        $dates = \is_array($facts['dates'] ?? null) ? $facts['dates'] : [];
        $promulgated = $dates['promulgated'] ?? null;

        if (\is_string($promulgated) && 1 === preg_match('/^(\d{4})/', $promulgated, $matches)) {
            return (int) $matches[1];
        }

        return (int) date('Y');
    }
}
