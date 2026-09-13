<?php

declare(strict_types=1);

namespace App\Content\Facts;

use App\Content\Check\QualityIssue;
use App\Content\Check\QualityReport;
use App\Content\Dates\EffectiveDateCalculator;

/**
 * Checks every figure and date in facts.yml against the German source (SPEC.md § 7.4, check 1).
 *
 * This is the check that makes the whole pipeline trustworthy, and the only one that can catch a
 * model quietly inventing a number. It is deliberately deterministic: each `source_quote` must be
 * findable in the German text, each amount must appear inside its own quote, and a derived date
 * must agree with the arithmetic of § 24.5 rather than with the model.
 *
 * Matching is forgiving about how German writes things — non-breaking spaces, "48 300" versus
 * "48.300" versus "48300", upper and lower case — and unforgiving about everything else.
 */
final readonly class FactVerifier
{
    public function __construct(private EffectiveDateCalculator $dates)
    {
    }

    /**
     * @param array<string, mixed> $facts      the parsed facts.yml
     * @param string               $germanText the diff and the amending act, as fetched
     */
    public function verify(array $facts, string $germanText): QualityReport
    {
        $report = new QualityReport();
        $haystack = $this->normalise($germanText);

        $this->verifyAmounts($facts, $haystack, $report);
        $this->verifyDates($facts, $haystack, $report);

        return $report;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function verifyAmounts(array $facts, string $haystack, QualityReport $report): void
    {
        /** @var list<mixed> $amounts */
        $amounts = \is_array($facts['amounts'] ?? null) ? array_values($facts['amounts']) : [];

        foreach ($amounts as $index => $amount) {
            if (!\is_array($amount)) {
                continue;
            }

            $path = \sprintf('amounts[%d]', $index);
            $key = \is_string($amount['key'] ?? null) ? $amount['key'] : '?';
            $quote = \is_string($amount['source_quote'] ?? null) ? trim($amount['source_quote']) : '';

            if ('' === $quote) {
                $report->add(QualityIssue::error(
                    'fact.quote_missing',
                    \sprintf('The amount "%s" carries no source_quote, so it cannot be verified.', $key),
                    $path,
                ));

                continue;
            }

            if (!$this->contains($haystack, $quote)) {
                $report->add(QualityIssue::error(
                    'fact.quote_not_found',
                    \sprintf('The quote for "%s" does not appear in the German text: "%s".', $key, $this->shorten($quote)),
                    $path.'.source_quote',
                ));

                continue;
            }

            // The quote is real; now make sure it is the quote for *this* value. A correct sentence
            // copied from the wrong place is exactly how a wrong figure reaches a reader.
            $this->verifyValueInQuote($amount, 'new', $quote, $path, $key, $report);
            $this->verifyValueInQuote($amount, 'old', $quote, $path, $key, $report);
        }
    }

    /**
     * @param array<string, mixed> $amount
     */
    private function verifyValueInQuote(
        array $amount,
        string $field,
        string $quote,
        string $path,
        string $key,
        QualityReport $report,
    ): void {
        $value = $amount[$field] ?? null;

        if (!is_numeric($value)) {
            return;
        }

        // The "old" value often stands in a different sentence than the new one, so a missing old
        // value is a warning; a missing new value is what the card is actually about.
        $severity = 'new' === $field ? 'error' : 'warning';

        if ($this->quoteContainsNumber($quote, (float) $value)) {
            return;
        }

        $message = \sprintf(
            'The %s value of "%s" (%s) does not appear in its own source_quote.',
            $field,
            $key,
            (string) $value,
        );

        $report->add('error' === $severity
            ? QualityIssue::error('fact.value_not_in_quote', $message, $path.'.'.$field)
            : QualityIssue::warning('fact.value_not_in_quote', $message, $path.'.'.$field));
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function verifyDates(array $facts, string $haystack, QualityReport $report): void
    {
        /** @var array<string, mixed> $dates */
        $dates = \is_array($facts['dates'] ?? null) ? $facts['dates'] : [];
        $promulgated = $this->date($dates['promulgated'] ?? null);

        foreach (['effective', 'deadlines'] as $section) {
            /** @var list<mixed> $entries */
            $entries = \is_array($dates[$section] ?? null) ? array_values($dates[$section]) : [];

            foreach ($entries as $index => $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                $this->verifyDateEntry($entry, \sprintf('dates.%s[%d]', $section, $index), $haystack, $promulgated, $report);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function verifyDateEntry(
        array $entry,
        string $path,
        string $haystack,
        ?\DateTimeImmutable $promulgated,
        QualityReport $report,
    ): void {
        $ruleText = \is_string($entry['rule_text'] ?? null) ? trim($entry['rule_text']) : '';
        $derived = filter_var($entry['derived'] ?? false, \FILTER_VALIDATE_BOOL);
        $date = $this->date($entry['date'] ?? null);

        if ('' !== $ruleText && !$this->contains($haystack, $ruleText)) {
            $report->add(QualityIssue::error(
                'fact.rule_not_found',
                \sprintf('The entry-into-force rule does not appear in the German text: "%s".', $this->shorten($ruleText)),
                $path.'.rule_text',
            ));
        }

        if ($derived) {
            // A derived date is checked against the calculator, never against the literal text
            // (SPEC.md § 24.5).
            if (!$date instanceof \DateTimeImmutable || '' === $ruleText || !$promulgated instanceof \DateTimeImmutable) {
                $report->add(QualityIssue::error(
                    'fact.derived_incomplete',
                    'A derived date needs a rule_text, a date and dates.promulgated.',
                    $path,
                ));

                return;
            }

            if (!$this->dates->agrees($ruleText, $promulgated, $date)) {
                $calculated = $this->dates->calculate($ruleText, $promulgated);

                $report->add(QualityIssue::error(
                    'fact.derived_mismatch',
                    \sprintf(
                        'The date %s does not follow from "%s" (%s).',
                        $date->format('Y-m-d'),
                        $this->shorten($ruleText),
                        $calculated instanceof \DateTimeImmutable
                            ? 'the rule gives '.$calculated->format('Y-m-d')
                            : 'the rule could not be computed, so the date must not be marked derived',
                    ),
                    $path.'.date',
                ));
            }

            return;
        }

        // A fixed date should be readable in the act. This is a warning rather than an error: the
        // date may legitimately come from the gazette metadata instead of the text we were given.
        if ($date instanceof \DateTimeImmutable && '' === $ruleText && !$this->containsDate($haystack, $date)) {
            $report->add(QualityIssue::warning(
                'fact.date_not_found',
                \sprintf('The date %s does not appear in the German text.', $date->format('Y-m-d')),
                $path.'.date',
            ));
        }
    }

    private function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $this->normalise($needle));
    }

    /**
     * Whether the quote mentions the number, in any German spelling of it.
     */
    private function quoteContainsNumber(string $quote, float $value): bool
    {
        $normalised = $this->normalise($quote);
        $asInt = (int) round($value);

        $candidates = [(string) $asInt];

        if ((float) $asInt !== $value) {
            $candidates[] = str_replace('.', ',', (string) $value);
            $candidates[] = (string) $value;
        }

        return array_any($candidates, static fn ($candidate): bool => str_contains($normalised, $candidate));
    }

    private function containsDate(string $haystack, \DateTimeImmutable $date): bool
    {
        $months = [
            1 => 'januar', 'februar', 'märz', 'april', 'mai', 'juni',
            'juli', 'august', 'september', 'oktober', 'november', 'dezember',
        ];

        $day = (int) $date->format('j');
        $month = (int) $date->format('n');
        $year = $date->format('Y');

        $spellings = [
            \sprintf('%d. %s %s', $day, $months[$month], $year),
            \sprintf('%02d.%02d.%s', $day, $month, $year),
            \sprintf('%d.%d.%s', $day, $month, $year),
            $date->format('Y-m-d'),
        ];

        return array_any($spellings, fn (string $spelling): bool => str_contains($haystack, $this->normalise($spelling)));
    }

    /**
     * Lower case, ordinary spaces, and numbers without thousands separators — the differences
     * between "48 300 Euro", "48.300 Euro" and "48300 Euro" are typography, not facts.
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        // 48.300 / 48 300 / 1.234.567 -> 48300 / 1234567
        $text = (string) preg_replace_callback(
            '/\b\d{1,3}(?:[. ]\d{3})+\b/u',
            static fn (array $m): string => str_replace(['.', ' '], '', $m[0]),
            $text,
        );

        return trim($text);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'…' : $text;
    }
}
