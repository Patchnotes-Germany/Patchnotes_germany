<?php

declare(strict_types=1);

namespace App\Content\Check;

/**
 * Tells whether a translation is actually in the language it claims (SPEC.md § 7.4, check 5).
 *
 * This is not a general language identifier and does not pretend to be one. It answers a single,
 * much narrower question: did the model return Russian when we asked for Ukrainian, or hand back
 * the English master untranslated? That happens, and it is embarrassing rather than dangerous — so
 * a cheap, dependency-free check that is confident about *this* project's languages beats a library
 * that can name two hundred.
 *
 * Where it is unsure it says so, and the caller treats that as "no finding" rather than a failure.
 */
final class LanguageDetector
{
    /** Below this many letters, any verdict would be noise. */
    private const int MINIMUM_LETTERS = 40;

    /** Letters that exist in Ukrainian but not in Russian, and the other way round. */
    private const string UKRAINIAN_ONLY = 'іїєґ';
    private const string RUSSIAN_ONLY = 'ыэъё';

    /** Letters that only Turkish has among the Latin-script languages here. */
    private const string TURKISH_ONLY = 'ışğ';

    /** @var array<string, list<string>> */
    private const array STOPWORDS = [
        'ru' => ['что', 'если', 'который', 'может', 'нужно', 'также', 'этот', 'вы', 'для'],
        'uk' => ['що', 'якщо', 'який', 'може', 'потрібно', 'також', 'цей', 'ви', 'для'],
        'en' => ['the', 'and', 'that', 'this', 'from', 'with', 'you', 'for', 'law'],
        'tr' => ['ve', 'bir', 'için', 'olan', 'bu', 'ile', 'daha', 'gerek'],
        'de' => ['und', 'der', 'die', 'das', 'nicht', 'wird', 'werden', 'für', 'gesetz'],
    ];

    /**
     * @return string|null the detected language, or null when the text is too short or unclear
     */
    public function detect(string $text): ?string
    {
        $text = mb_strtolower(strip_tags($text));
        $letters = (int) preg_match_all('/\p{L}/u', $text);

        if ($letters < self::MINIMUM_LETTERS) {
            return null;
        }

        $cyrillic = (int) preg_match_all('/\p{Cyrillic}/u', $text);

        return $cyrillic > $letters / 2
            ? $this->cyrillicLanguage($text)
            : $this->latinLanguage($text);
    }

    /**
     * Whether the text may be in the expected language. Unknown counts as "may be": the check must
     * not block a short but perfectly good section.
     */
    public function looksLike(string $text, string $expected): bool
    {
        $detected = $this->detect($text);

        return null === $detected || $detected === $expected;
    }

    private function cyrillicLanguage(string $text): ?string
    {
        $ukrainian = $this->countAny($text, self::UKRAINIAN_ONLY);
        $russian = $this->countAny($text, self::RUSSIAN_ONLY);

        if ($ukrainian > $russian) {
            return 'uk';
        }

        if ($russian > $ukrainian) {
            return 'ru';
        }

        // Neither alphabet gave itself away: fall back to the word lists.
        return $this->byStopwords($text, ['ru', 'uk']);
    }

    private function latinLanguage(string $text): ?string
    {
        if ($this->countAny($text, self::TURKISH_ONLY) > 0) {
            return 'tr';
        }

        // Umlauts alone say nothing here: by the rules of this project every card keeps the German
        // names of institutions and documents ("Ausländerbehörde", "Bürgergeld"), so an English or
        // Turkish card contains them too. Only the word lists may decide.
        return $this->byStopwords($text, ['en', 'tr', 'de']);
    }

    /**
     * @param list<string> $candidates
     */
    private function byStopwords(string $text, array $candidates): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $scores = [];

        foreach ($candidates as $language) {
            $scores[$language] = \count(array_intersect($words, self::STOPWORDS[$language] ?? []));
        }

        arsort($scores);
        $best = array_key_first($scores);

        if (!\is_string($best) || 0 === $scores[$best]) {
            return null;
        }

        // A tie tells us nothing.
        $tied = array_filter($scores, static fn (int $score): bool => $score === $scores[$best]);

        return \count($tied) > 1 ? null : $best;
    }

    private function countAny(string $text, string $characters): int
    {
        $count = 0;

        foreach (preg_split('//u', $characters, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $count += mb_substr_count($text, $character);
        }

        return $count;
    }
}
