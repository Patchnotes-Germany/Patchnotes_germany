<?php

declare(strict_types=1);

namespace App\Content\Check;

use App\Content\Card\PlaceholderParser;
use App\Content\Entity\Card;
use App\Content\Glossary\Glossary;
use App\Content\Glossary\GlossaryEntry;

/**
 * The deterministic checks a card must pass before it is published (SPEC.md § 7.4, checks 3–7).
 *
 * None of these needs a model, and that is the point: they are the part of the quality gate that
 * cannot itself hallucinate. The placeholder check in particular is what guarantees the promise of
 * § 5.4 — a translation can rearrange a sentence, but it cannot change a sum or a date, because it
 * never holds one.
 */
final readonly class TranslationChecker
{
    /** "Коротко" is a teaser on cards and in notifications; longer than this and it is cut off. */
    public const int MAX_SUMMARY_CHARS = 280;

    public function __construct(
        private PlaceholderParser $placeholders,
        private LanguageDetector $languages,
        private ForbiddenWording $forbidden,
        private int $maxCardChars = 8000,
    ) {
    }

    /**
     * @param array<string, string> $master      section key => text, in the master language
     * @param array<string, string> $translation section key => text, in $language
     */
    public function check(array $master, array $translation, string $language, ?Glossary $glossary = null): QualityReport
    {
        $report = new QualityReport();

        $this->checkPlaceholders($master, $translation, $report);
        $this->checkSections($master, $translation, $report);
        $this->checkLength($translation, $report);
        $this->checkLanguage($translation, $language, $report);
        $this->checkWording($translation, $language, $report);

        if ($glossary instanceof Glossary) {
            $this->checkGlossary($master, $translation, $glossary, $report);
        }

        return $report;
    }

    /**
     * Runs the checks that do not compare against a master — used on the master card itself.
     *
     * @param array<string, string> $sections
     */
    public function checkMaster(array $sections, string $language): QualityReport
    {
        $report = new QualityReport();

        $this->checkBareValues($sections, $report);
        $this->checkLength($sections, $report);
        $this->checkWording($sections, $language, $report);

        return $report;
    }

    /**
     * @param array<string, string> $master
     * @param array<string, string> $translation
     */
    private function checkPlaceholders(array $master, array $translation, QualityReport $report): void
    {
        $expected = array_keys($this->placeholders->parseSections($master));
        $actual = array_keys($this->placeholders->parseSections($translation));

        foreach (array_diff($expected, $actual) as $missing) {
            $report->add(QualityIssue::error(
                'translation.placeholder_missing',
                \sprintf('The placeholder %s from the master is missing.', $missing),
            ));
        }

        foreach (array_diff($actual, $expected) as $added) {
            $report->add(QualityIssue::error(
                'translation.placeholder_added',
                \sprintf('The translation introduces a placeholder the master does not have: %s.', $added),
            ));
        }

        $this->checkBareValues($translation, $report);
    }

    /**
     * A figure or date typed out instead of using a placeholder is unverifiable and unformatted.
     *
     * @param array<string, string> $sections
     */
    private function checkBareValues(array $sections, QualityReport $report): void
    {
        foreach ($sections as $key => $text) {
            foreach ($this->placeholders->bareValues($text) as $value) {
                $report->add(QualityIssue::error(
                    'translation.bare_value',
                    \sprintf('"%s" is written out instead of using a placeholder from facts.yml.', $value),
                    'sections.'.$key,
                ));
            }
        }
    }

    /**
     * @param array<string, string> $master
     * @param array<string, string> $translation
     */
    private function checkSections(array $master, array $translation, QualityReport $report): void
    {
        foreach (Card::SECTION_KEYS as $key) {
            $masterText = trim($master[$key] ?? '');
            $translatedText = trim($translation[$key] ?? '');

            if ('' !== $masterText && '' === $translatedText) {
                $report->add(QualityIssue::error(
                    'translation.section_missing',
                    \sprintf('The section "%s" is empty although the master has text for it.', $key),
                    'sections.'.$key,
                ));
            }
        }

        foreach (array_keys($translation) as $key) {
            if (!\in_array($key, Card::SECTION_KEYS, true)) {
                $report->add(QualityIssue::error(
                    'translation.unknown_section',
                    \sprintf('"%s" is not one of the fixed section keys.', $key),
                    'sections.'.$key,
                ));
            }
        }
    }

    /**
     * @param array<string, string> $sections
     */
    private function checkLength(array $sections, QualityReport $report): void
    {
        $summary = trim($sections['summary'] ?? '');

        if (mb_strlen($summary) > self::MAX_SUMMARY_CHARS) {
            $report->add(QualityIssue::error(
                'card.summary_too_long',
                \sprintf('The summary is %d characters long, the limit is %d.', mb_strlen($summary), self::MAX_SUMMARY_CHARS),
                'sections.summary',
            ));
        }

        $total = array_sum(array_map(mb_strlen(...), $sections));

        if ($total > $this->maxCardChars) {
            $report->add(QualityIssue::warning(
                'card.too_long',
                \sprintf('The card is %d characters long, the limit is %d.', $total, $this->maxCardChars),
            ));
        }
    }

    /**
     * @param array<string, string> $sections
     */
    private function checkLanguage(array $sections, string $language, QualityReport $report): void
    {
        // Placeholders are Latin identifiers — "{{ norm:bund/aufenthg_2004/p18g }}" — and every
        // card is full of them. Left in, they outweigh the Cyrillic of a perfectly good Russian
        // card and the detector calls it a European language. They are not text, so they go first.
        $text = $this->placeholders->replace(
            implode("\n", array_map(trim(...), $sections)),
            static fn (): string => ' ',
        );

        $detected = $this->languages->detect($text);

        if (null !== $detected && $detected !== $language) {
            $report->add(QualityIssue::error(
                'translation.wrong_language',
                \sprintf('The text looks like "%s" but should be "%s".', $detected, $language),
            ));
        }
    }

    /**
     * @param array<string, string> $sections
     */
    private function checkWording(array $sections, string $language, QualityReport $report): void
    {
        foreach ($sections as $key => $text) {
            foreach ($this->forbidden->findIn($text, $language) as $hit) {
                $report->add(QualityIssue::error(
                    'card.forbidden_wording',
                    match ($hit['category']) {
                        'advice' => \sprintf('"%s" reads as legal advice; describe what the law provides instead.', $hit['phrase']),
                        'opinion' => \sprintf('"%s" is an evaluation; the text has to stay neutral.', $hit['phrase']),
                        default => \sprintf('"%s" is not allowed in a card.', $hit['phrase']),
                    },
                    'sections.'.$key,
                ));
            }
        }
    }

    /**
     * @param array<string, string> $master
     * @param array<string, string> $translation
     */
    private function checkGlossary(array $master, array $translation, Glossary $glossary, QualityReport $report): void
    {
        $masterText = implode("\n", $master);
        $translatedText = implode("\n", $translation);

        foreach ($glossary->mentionedIn($masterText) as $entry) {
            if (!$this->usesTerm($translatedText, $entry)) {
                // A warning, not an error: Russian, Ukrainian and Turkish decline these words, and
                // blocking a good translation over an ending would be worse than a note for the
                // editor.
                $report->add(QualityIssue::warning(
                    'translation.glossary_term_missing',
                    \sprintf('The glossary renders "%s" as "%s", which does not appear in the translation.', $entry->germanTerm, $entry->render),
                ));
            }
        }
    }

    private function usesTerm(string $text, GlossaryEntry $entry): bool
    {
        return str_contains($text, $entry->expectedInTranslation());
    }
}
