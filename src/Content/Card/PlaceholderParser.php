<?php

declare(strict_types=1);

namespace App\Content\Card;

/**
 * Finds the `{{ … }}` placeholders in card text (SPEC.md § 24.13).
 *
 * Card content is **never** handed to Twig. It comes from a model and from community pull requests,
 * so rendering it as a template would be server-side template injection: `{{ app.request }}` or a
 * filter chain would execute inside our process. This parser understands exactly four placeholder
 * types and treats everything else as literal text.
 */
final class PlaceholderParser
{
    /**
     * `{{ type:argument }}` — spaces optional, argument limited to the characters our references
     * actually use (word characters, dot, slash, dash, colon for norm references).
     */
    private const string PATTERN = '/\{\{\s*([a-z_]+)\s*:\s*([A-Za-z0-9_.\/\-]+)\s*\}\}/u';

    /**
     * Every placeholder in the order it appears, duplicates included.
     *
     * @return list<Placeholder>
     */
    public function parse(string $text): array
    {
        if (false === preg_match_all(self::PATTERN, $text, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $placeholders = [];

        /** @var list<array{0: string, 1: string, 2: string}> $matches */
        foreach ($matches as $match) {
            $placeholders[] = new Placeholder($match[1], $match[2], $match[0]);
        }

        return $placeholders;
    }

    /**
     * The distinct placeholders of a whole card, keyed by their canonical form — this is what the
     * translation check compares between the master and a translation.
     *
     * @param array<string, string> $sections
     *
     * @return array<string, Placeholder>
     */
    public function parseSections(array $sections): array
    {
        $found = [];

        foreach ($sections as $text) {
            foreach ($this->parse($text) as $placeholder) {
                $found[$placeholder->canonical()] = $placeholder;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Replaces every placeholder through the resolver. A resolver returning null leaves the
     * placeholder untouched, so a missing value is visible instead of silently becoming an empty
     * space in a sentence about money.
     *
     * @param callable(Placeholder): ?string $resolve
     */
    public function replace(string $text, callable $resolve): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($resolve): string {
                $resolved = $resolve(new Placeholder($match[1], $match[2], $match[0]));

                return $resolved ?? $match[0];
            },
            $text,
        );
    }

    /**
     * Numbers and dates a writer typed literally instead of using a placeholder (§ 7.4 check 3).
     * Years on their own are ignored — "das Gesetz von 2026" is prose, not a value.
     *
     * @return list<string> the offending literals
     */
    public function bareValues(string $text): array
    {
        $withoutPlaceholders = (string) preg_replace(self::PATTERN, '', $text);
        $found = [];

        // 48.300, 48 300, 1.234,56 — thousands separators or decimals mean a real amount.
        if (preg_match_all('/\b\d{1,3}(?:[.\x{00A0}\x{202F} ]\d{3})+(?:,\d+)?\b/u', $withoutPlaceholders, $matches)) {
            $found = [...$found, ...$matches[0]];
        }

        // Amounts with a currency or percent sign, however short. The closing look-ahead replaces
        // \b, which never matches after "€" or "%" — those are not word characters.
        if (preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:€|EUR|Euro|%|Prozent)(?![\p{L}\p{N}])/u', $withoutPlaceholders, $matches)) {
            $found = [...$found, ...$matches[0]];
        }

        // Full dates in any of the usual spellings.
        if (preg_match_all('/\b\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\b|\b\d{4}-\d{2}-\d{2}\b/u', $withoutPlaceholders, $matches)) {
            $found = [...$found, ...$matches[0]];
        }

        return array_values(array_unique(array_map(trim(...), $found)));
    }
}
