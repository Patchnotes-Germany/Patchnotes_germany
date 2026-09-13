<?php

declare(strict_types=1);

namespace App\Laws;

use App\Git\Worktree;
use App\Laws\Enum\LawStatus;
use App\Laws\Value\NormalizedLaw;
use App\Laws\Value\NormalizedNorm;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a normalized law into the `laws` repository (SPEC.md § 4.1–4.3).
 *
 * Layout per law: `_law.yml` with the machine-readable metadata, one Markdown file per norm with
 * front matter, and a generated `README.md` so the directory is readable on the forge.
 *
 * Two rules keep the diffs honest: **no timestamps anywhere** (they would produce empty diffs on
 * every run, SPEC.md § 4.2) and a **fixed key order** in the YAML, so unrelated lines never move.
 */
final readonly class LawWriter
{
    public function __construct(private string $serverName = 'localhost')
    {
    }

    /**
     * Renders the whole law without touching the file system, so a synchronisation can compare the
     * result with what the repository already contains before it decides to open a pull request.
     *
     * @return array<string, string> path relative to the repository root => file content
     */
    public function renderFiles(NormalizedLaw $law): array
    {
        $directory = $this->directoryFor($law);

        $files = [
            $directory.'/_law.yml' => $this->renderLawYaml($law),
            $directory.'/README.md' => $this->renderReadme($law),
        ];

        foreach ($law->norms as $norm) {
            $files[$directory.'/'.$norm->fileName()] = $this->renderNorm($law, $norm);
        }

        return $files;
    }

    /**
     * @return list<string> the paths written, relative to the repository root
     */
    public function write(Worktree $worktree, NormalizedLaw $law): array
    {
        $files = $this->renderFiles($law);

        foreach ($files as $path => $content) {
            $worktree->writeFile($path, $content);
        }

        $written = array_keys($files);
        $this->removeVanishedNorms($worktree, $this->directoryFor($law), $written);

        return $written;
    }

    /**
     * A repealed law keeps its history but moves out of the active tree (SPEC.md § 24.3).
     */
    public function moveToRepealed(Worktree $worktree, NormalizedLaw $law): string
    {
        $from = $law->jurisdiction.'/'.$law->slug;
        $to = $law->jurisdiction.'/_repealed/'.$law->slug;

        if ($worktree->directoryExists($from)) {
            $worktree->moveDirectory($from, $to);
        }

        return $to;
    }

    public function directoryFor(NormalizedLaw $law): string
    {
        $base = LawStatus::Repealed === $law->status
            ? $law->jurisdiction.'/_repealed'
            : $law->jurisdiction;

        return $base.'/'.$law->slug;
    }

    public function renderNorm(NormalizedLaw $law, NormalizedNorm $norm): string
    {
        $frontMatter = array_filter([
            'id' => $norm->sourceId,
            'law' => $law->slug,
            'jurisdiction' => $law->jurisdiction,
            'designation' => $norm->designation,
            'title' => $norm->title,
            'status' => $norm->status->value,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);

        $heading = trim(($norm->designation ?? '').' '.($norm->title ?? ''));
        if ('' === $heading) {
            $heading = $norm->key;
        }

        return "---\n"
            .$this->yaml($frontMatter)
            ."---\n\n"
            .'# '.$heading."\n\n"
            .rtrim($norm->markdown)."\n";
    }

    public function renderLawYaml(NormalizedLaw $law): string
    {
        $data = [
            'slug' => $law->slug,
            'jurisdiction' => $law->jurisdiction,
            'type' => $law->type->value,
            'status' => $law->status->value,
            'abbreviation' => $law->abbreviation,
            'official_abbreviation' => $law->officialAbbreviation,
            'title' => $law->title,
            'short_title' => $law->shortTitle,
            'date_of_issue' => $law->dateOfIssue?->format('Y-m-d'),
            'promulgation' => $law->promulgation,
            'status_note' => $law->statusNote,
            'last_amending_act' => $law->lastAmendingAct,
            'source' => array_filter([
                'name' => $law->sourceName,
                'url' => $law->sourceUrl,
                'document_id' => $law->sourceDocumentId,
            ], static fn (?string $value): bool => null !== $value && '' !== $value),
            'norms' => $law->normKeys(),
        ];

        if ([] !== $law->structure) {
            $data['structure'] = $law->structure;
        }
        if ([] !== $law->pendingAmendments) {
            // Announced but not yet incorporated changes; the signal for preview pull requests.
            $data['pending_amendments'] = $law->pendingAmendments;
        }

        $data = array_filter($data, static fn (mixed $value): bool => null !== $value && '' !== $value && [] !== $value);

        return $this->yaml($data);
    }

    /**
     * The README is generated from `_law.yml`: forges render it when someone opens the directory,
     * which makes the repository browsable without our website (SPEC.md § 4.1).
     */
    public function renderReadme(NormalizedLaw $law): string
    {
        $lines = [
            '# '.($law->abbreviation ?? $law->slug).' — '.$law->title,
            '',
        ];

        if (null !== $law->shortTitle && $law->shortTitle !== $law->title) {
            $lines[] = '*'.$law->shortTitle.'*';
            $lines[] = '';
        }

        $facts = array_filter([
            'Stand' => $law->statusNote,
            'Zuletzt geändert durch' => $law->lastAmendingAct,
            'Ausfertigung' => $law->dateOfIssue?->format('d.m.Y'),
            'Fundstelle' => $law->promulgation,
            'Quelle' => $law->sourceUrl,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);

        foreach ($facts as $label => $value) {
            $lines[] = '- **'.$label.':** '.$value;
        }

        if (LawStatus::Repealed === $law->status) {
            $lines[] = '- **Status:** aufgehoben';
        }

        $lines[] = '';
        $lines[] = '## Normen';
        $lines[] = '';

        foreach ($law->norms as $norm) {
            $label = trim(($norm->designation ?? $norm->key).' '.($norm->title ?? ''));
            $lines[] = '- ['.$label.']('.$norm->fileName().')';
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'Diese Datei wird automatisch erzeugt. Verbindlich ist allein die amtliche Fassung;';
        $lines[] = 'Erklärungen in einfacher Sprache: https://'.$this->serverName.'/';

        return implode("\n", $lines)."\n";
    }

    /**
     * Norm files that the source no longer delivers are deleted, so a law never keeps orphans —
     * their history stays in git.
     *
     * @param list<string> $written
     */
    private function removeVanishedNorms(Worktree $worktree, string $directory, array $written): void
    {
        $keep = array_flip($written);

        foreach ($worktree->listFiles($directory) as $existing) {
            if (isset($keep[$existing]) || !str_ends_with($existing, '.md') || str_ends_with($existing, '/README.md')) {
                continue;
            }

            $worktree->deleteFile($existing);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function yaml(array $data): string
    {
        return Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
