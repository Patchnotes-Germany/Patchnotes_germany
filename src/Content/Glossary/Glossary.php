<?php

declare(strict_types=1);

namespace App\Content\Glossary;

/**
 * The German terms of one language and how they are rendered (SPEC.md § 5.5).
 *
 * The rule behind it: a term someone reads in a letter from an authority stays German, with a short
 * explanation — "Aufenthaltstitel (вид на жительство)". Translating it away would leave the reader
 * unable to recognise the word on the document in their hand.
 *
 * German terms are compared exactly: "Straße" and "Strasse" are different entries.
 */
final readonly class Glossary
{
    /**
     * @param array<string, GlossaryEntry> $entries keyed by the German term
     */
    public function __construct(
        public string $language,
        private array $entries = [],
    ) {
    }

    /**
     * @return array<string, GlossaryEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function has(string $germanTerm): bool
    {
        return isset($this->entries[$germanTerm]);
    }

    public function get(string $germanTerm): ?GlossaryEntry
    {
        return $this->entries[$germanTerm] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * The entries a text actually uses — this is what goes into a translation prompt, instead of
     * the whole glossary, so the prompt stays short and the model stays focused.
     *
     * @return list<GlossaryEntry>
     */
    public function mentionedIn(string $text): array
    {
        $found = [];

        foreach ($this->entries as $term => $entry) {
            if (str_contains($text, $term)) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * @param list<GlossaryEntry>|null $entries defaults to all of them
     *
     * @return list<array{german: string, render: string, explanation: string|null}>
     */
    public function forPrompt(?array $entries = null): array
    {
        $entries ??= array_values($this->entries);

        return array_map(static fn (GlossaryEntry $entry): array => [
            'german' => $entry->germanTerm,
            'render' => $entry->render,
            'explanation' => $entry->explanation,
        ], $entries);
    }
}
