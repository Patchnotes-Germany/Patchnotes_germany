<?php

declare(strict_types=1);

namespace App\Content\Dates;

/**
 * Turns a German entry-into-force rule into a date (SPEC.md § 24.5).
 *
 * German acts rarely name a plain date. They say "am Tag nach der Verkündung" or "am ersten Tag des
 * dritten auf die Verkündung folgenden Kalendermonats", and that sentence has to become the date a
 * reader is told — and the date a reminder fires on. Doing that with a model would be a guess; here
 * it is arithmetic, covered by unit tests, and the AI's answer is checked against it.
 *
 * A rule this class does not understand returns null. That is a feature: the card then keeps the
 * literal rule text and goes to review, rather than showing an invented date.
 */
final class EffectiveDateCalculator
{
    /** "ersten" … "zwölften" — the ordinal in "am ersten Tag des …". */
    private const array ORDINALS = [
        'ersten' => 1, 'zweiten' => 2, 'dritten' => 3, 'vierten' => 4,
        'fünften' => 5, 'fuenften' => 5, 'sechsten' => 6, 'siebten' => 7, 'siebenten' => 7,
        'achten' => 8, 'neunten' => 9, 'zehnten' => 10, 'elften' => 11, 'zwölften' => 12,
        'zwoelften' => 12,
    ];

    /** Number words used in "drei Monate nach der Verkündung". */
    private const array NUMBER_WORDS = [
        'einem' => 1, 'einen' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5, 'fuenf' => 5,
        'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9, 'zehn' => 10, 'elf' => 11, 'zwölf' => 12,
        'zwoelf' => 12,
    ];

    private const array MONTHS = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5,
        'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10,
        'november' => 11, 'dezember' => 12,
    ];

    /**
     * @param \DateTimeImmutable $promulgatedOn the date the act was published in the gazette
     *
     * @return \DateTimeImmutable|null null when the rule is not one of the understood patterns
     */
    public function calculate(string $ruleText, \DateTimeImmutable $promulgatedOn): ?\DateTimeImmutable
    {
        $rule = $this->normalise($ruleText);
        $promulgated = $promulgatedOn->setTime(0, 0);

        // An explicit date in the rule wins: "tritt am 1. Januar 2027 in Kraft".
        $explicit = $this->explicitDate($rule);
        if ($explicit instanceof \DateTimeImmutable) {
            return $explicit;
        }

        // "am Tag nach der Verkündung"
        if (1 === preg_match('/\bam tag(?:e)? nach der verkündung\b/u', $rule)) {
            return $promulgated->modify('+1 day');
        }

        // "am Tag der Verkündung"
        if (1 === preg_match('/\bam tag(?:e)? der verkündung\b/u', $rule)) {
            return $promulgated;
        }

        // "am ersten Tag des dritten auf die Verkündung folgenden Kalendermonats"
        if (1 === preg_match('/\bam ersten tag des ([a-zäöüß]+) (?:auf die verkündung )?folgenden (?:kalender)?monats\b/u', $rule, $matches)) {
            $offset = self::ORDINALS[$matches[1]] ?? null;

            return null === $offset ? null : $this->firstDayOfMonthAfter($promulgated, $offset);
        }

        // "am ersten Tag des auf die Verkündung folgenden Kalendermonats" (no ordinal = the next one)
        if (1 === preg_match('/\bam ersten tag des (?:auf die verkündung )?folgenden (?:kalender)?monats\b/u', $rule)) {
            return $this->firstDayOfMonthAfter($promulgated, 1);
        }

        // "drei Monate nach der Verkündung" / "6 Monate nach der Verkündung"
        if (1 === preg_match('/\b(\d+|[a-zäöüß]+) (monat|monate|monaten) nach der verkündung\b/u', $rule, $matches)) {
            $count = $this->number($matches[1]);

            return null === $count ? null : $promulgated->modify(\sprintf('+%d months', $count));
        }

        // "drei Tage nach der Verkündung"
        if (1 === preg_match('/\b(\d+|[a-zäöüß]+) (tag|tage|tagen) nach der verkündung\b/u', $rule, $matches)) {
            $count = $this->number($matches[1]);

            return null === $count ? null : $promulgated->modify(\sprintf('+%d days', $count));
        }

        return null;
    }

    /**
     * Whether the AI's date agrees with the arithmetic (SPEC.md § 24.5: the derived date is checked
     * against the calculator, not against the literal text).
     */
    public function agrees(string $ruleText, \DateTimeImmutable $promulgatedOn, \DateTimeImmutable $claimed): bool
    {
        $calculated = $this->calculate($ruleText, $promulgatedOn);

        return $calculated instanceof \DateTimeImmutable
            && $calculated->format('Y-m-d') === $claimed->format('Y-m-d');
    }

    private function firstDayOfMonthAfter(\DateTimeImmutable $promulgated, int $months): \DateTimeImmutable
    {
        // "des dritten folgenden Kalendermonats": count from the month of promulgation, then take
        // its first day — the day of the month the act was published in is irrelevant.
        return $promulgated
            ->setDate((int) $promulgated->format('Y'), (int) $promulgated->format('n'), 1)
            ->modify(\sprintf('+%d months', $months));
    }

    private function explicitDate(string $rule): ?\DateTimeImmutable
    {
        // "1. Januar 2027"
        if (1 === preg_match('/\b(\d{1,2})\.\s*([a-zäöüß]+)\s+(\d{4})\b/u', $rule, $matches)) {
            $month = self::MONTHS[$matches[2]] ?? null;

            if (null !== $month) {
                return $this->makeDate((int) $matches[3], $month, (int) $matches[1]);
            }
        }

        // "01.01.2027"
        if (1 === preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $rule, $matches)) {
            return $this->makeDate((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        return null;
    }

    private function makeDate(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day));
    }

    private function number(string $value): ?int
    {
        if (1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return self::NUMBER_WORDS[$value] ?? null;
    }

    private function normalise(string $rule): string
    {
        $rule = mb_strtolower(trim($rule));
        // Non-breaking spaces are common in gazette texts and would break every pattern.
        $rule = str_replace(["\u{00A0}", "\u{202F}"], ' ', $rule);

        return (string) preg_replace('/\s+/u', ' ', $rule);
    }
}
