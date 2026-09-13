<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

/**
 * Splits German legal prose into sentences, one per line (SPEC.md § 4.2).
 *
 * This is the single most consequential rule of the whole repository format: because every sentence
 * lives on its own line, `git diff` and `git blame` answer "which amending act changed *this
 * sentence*" instead of "something in this paragraph moved". A wrong split silently rewrites lines
 * that did not change, so the rules here are conservative — when in doubt, do not split.
 *
 * Traps in German legal text: `§ 3 Abs. 1 Satz 2 Nr. 3.`, `z. B.`, `i. V. m.`, dates like
 * `1. Januar 2026`, ordinals (`Die 2. Verordnung …`), and enumeration markers (`1.`, `a)`).
 */
final readonly class SentenceSplitter
{
    /**
     * Abbreviations after which a full stop never ends a sentence. Multi-word entries are matched
     * with flexible whitespace ("z. B." also matches "z.B.").
     */
    public const array DEFAULT_ABBREVIATIONS = [
        'ABl.', 'Abs.', 'Abschn.', 'Alt.', 'Anh.', 'Anl.', 'Art.', 'Aufl.', 'BGBl.', 'GVBl.',
        'Bek.', 'Bd.', 'Beschl.', 'Bsp.', 'Rn.', 'Rspr.', 'Halbs.',
        'Buchst.', 'bzgl.', 'bzw.', 'ca.', 'd. h.', 'Drs.', 'ebd.', 'einschl.', 'entspr.', 'etc.',
        'evtl.', 'f.', 'ff.', 'gem.', 'ggf.', 'ggfs.', 'griech.', 'Hs.', 'i. d. F.', 'i. d. R.',
        'i. H. v.', 'i. S. d.', 'i. S. v.', 'i. V. m.', 'inkl.', 'insb.', 'insbes.', 'Kap.',
        'lfd.', 'lit.', 'max.', 'min.', 'Mio.', 'Mrd.', 'Nr.', 'Nrn.', 'Nds.', 'o. Ä.', 'o. g.',
        'p. a.', 'rd.', 'S.', 'sog.', 'St.', 'Std.', 'Str.', 'Tsd.', 'u. a.', 'u. Ä.', 'u. U.',
        'usw.', 'v. H.', 'v. T.', 'vgl.', 'Verf.', 'Verk.', 'VO.', 'z. B.', 'z. T.', 'zzgl.',
        'Ziff.', 'Buchstabe', 'Halbs.',
    ];

    private const array MONTHS = [
        'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    /** Words that continue a reference instead of starting a new sentence. */
    private const array REFERENCE_CONTINUATIONS = [
        'Satz', 'Sätze', 'Nummer', 'Nummern', 'Buchstabe', 'Halbsatz', 'Alternative', 'Variante',
        'Absatz', 'Abschnitt', 'Anlage', 'Anhang', 'Teil', 'Kapitel', 'Unterabsatz',
    ];

    /** @var list<string> */
    private array $abbreviations;

    /**
     * @param list<string> $additionalAbbreviations from patchnotes.normalization.abbreviations
     */
    public function __construct(array $additionalAbbreviations = [])
    {
        $this->abbreviations = array_values(array_unique([
            ...self::DEFAULT_ABBREVIATIONS,
            ...$additionalAbbreviations,
        ]));
    }

    /**
     * @return list<string> sentences, whitespace-normalised, never empty strings
     */
    public function split(string $paragraph): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $paragraph) ?? $paragraph);
        if ('' === $text) {
            return [];
        }

        $sentences = [];
        $start = 0;
        $length = mb_strlen($text);

        $parenthesisDepth = 0;

        for ($position = 0; $position < $length; ++$position) {
            $character = mb_substr($text, $position, 1);

            if ('(' === $character) {
                ++$parenthesisDepth;
                continue;
            }
            if (')' === $character) {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);
                continue;
            }

            if (!\in_array($character, ['.', '!', '?'], true)) {
                continue;
            }

            // Inside brackets a full stop belongs to a citation, never to a new sentence:
            // "(ABl. L 292 vom 10.11.2009, S. 31)".
            if ($parenthesisDepth > 0) {
                continue;
            }

            // A boundary needs whitespace after the punctuation; "3.500" or "18g." stay together.
            $next = mb_substr($text, $position + 1, 1);
            if ('' !== $next && ' ' !== $next) {
                continue;
            }

            if ('.' === $character && !$this->isSentenceEnd($text, $position)) {
                continue;
            }

            $sentence = trim(mb_substr($text, $start, $position - $start + 1));
            if ('' !== $sentence) {
                $sentences[] = $sentence;
            }
            $start = $position + 1;
        }

        $rest = trim(mb_substr($text, $start));
        if ('' !== $rest) {
            $sentences[] = $rest;
        }

        return $sentences;
    }

    /**
     * @param int $position index of the full stop
     */
    private function isSentenceEnd(string $text, int $position): bool
    {
        $before = mb_substr($text, 0, $position + 1);
        $after = ltrim(mb_substr($text, $position + 1));

        if ('' === $after) {
            return true;
        }

        foreach ($this->abbreviations as $abbreviation) {
            if ($this->endsWithAbbreviation($before, $abbreviation)) {
                return false;
            }
        }

        // A single letter before the stop is an initial or one part of a spaced abbreviation:
        // "Anlage A.", and the "z." of "z. B." or the "i." and "V." of "i. V. m.".
        if (1 === preg_match('/(?:^|[\s(])\p{L}\.$/u', $before)) {
            return false;
        }

        $firstWord = rtrim((string) preg_replace('/\s.*$/us', '', $after), ',;:');

        if (1 === preg_match('/(?:^|\s)(\d{1,3})\.$/u', $before)) {
            // Dates: "1. Januar 2026".
            if (\in_array($firstWord, self::MONTHS, true)) {
                return false;
            }

            $isReference = 1 === preg_match(
                '/(§+|Art(?:ikel)?|Abs(?:atz|\.)|Satz|Sätze|Nr\.|Nummer|Buchst(?:abe|\.)|Anlage|Anhang|Kapitel|Abschnitt|Teil|Buch)\s*\d{1,3}\.$/u',
                $before,
            );

            // "Die 2. Verordnung …": an ordinal that modifies the following noun continues the
            // sentence. A number that ends a reference ("… nach § 71 Absatz 1.") usually ends it.
            if (!$isReference && 1 === preg_match('/^\p{Lu}/u', $firstWord)) {
                return false;
            }

            // "… § 71 Absatz 1. Satz 2 gilt entsprechend": the reference continues.
            if ($isReference && \in_array($firstWord, self::REFERENCE_CONTINUATIONS, true)) {
                return false;
            }
        }

        // German sentences start with a capital letter, a digit, a bracket or a quotation mark.
        return 1 === preg_match('/^[\p{Lu}\d(„"\'§»–—]/u', $after);
    }

    private function endsWithAbbreviation(string $before, string $abbreviation): bool
    {
        // Allow "z. B." to also match "z.B." and non-breaking spaces.
        $pattern = '/(?:^|[\s(])'.str_replace('\ ', '\s*', preg_quote($abbreviation, '/')).'$/u';

        return 1 === preg_match($pattern, $before);
    }
}
