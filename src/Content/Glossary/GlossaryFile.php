<?php

declare(strict_types=1);

namespace App\Content\Glossary;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads `glossary/<lang>.yml` from the content repository (SPEC.md § 5.5).
 *
 * The file is written by contributors, so a malformed entry is skipped rather than allowed to break
 * a translation run; the placeholder file created at bootstrap contains only comments and yields an
 * empty glossary, which is a valid state.
 */
final readonly class GlossaryFile
{
    public function path(string $language): string
    {
        return 'glossary/'.$language.'.yml';
    }

    public function parse(string $language, string $yaml): Glossary
    {
        try {
            /** @var array<string, mixed>|null $data */
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new \RuntimeException(\sprintf('glossary/%s.yml is not valid YAML: %s', $language, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($data)) {
            return new Glossary($language);
        }

        $entries = [];

        foreach ($data as $term => $value) {
            $term = trim((string) $term);

            if ('' === $term) {
                continue;
            }

            // "Term: rendering" is accepted as a shorthand for an entry without an explanation.
            if (\is_string($value)) {
                $entries[$term] = new GlossaryEntry($term, $value);

                continue;
            }

            if (!\is_array($value)) {
                continue;
            }

            $render = $value['render'] ?? null;

            if (!\is_string($render) || '' === trim($render)) {
                continue;
            }

            $explanation = $value['explanation'] ?? null;

            $entries[$term] = new GlossaryEntry(
                $term,
                trim($render),
                \is_string($explanation) && '' !== trim($explanation) ? trim($explanation) : null,
                filter_var($value['keep_german'] ?? true, \FILTER_VALIDATE_BOOL),
            );
        }

        return new Glossary($language, $entries);
    }

    /**
     * @param array<string, GlossaryEntry>|list<GlossaryEntry> $entries
     */
    public function render(array $entries): string
    {
        $data = [];

        foreach ($entries as $entry) {
            $data[$entry->germanTerm] = array_filter([
                'render' => $entry->render,
                'explanation' => $entry->explanation,
                'keep_german' => $entry->keepGerman,
            ], static fn (mixed $value): bool => null !== $value);
        }

        // Sorted, so a regenerated glossary produces a readable diff instead of a reshuffle.
        ksort($data);

        return Yaml::dump($data, 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
