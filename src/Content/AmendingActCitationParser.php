<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Value\AmendingActKind;
use App\Content\Value\AmendingActReference;

/**
 * Reads the amending act out of the free-text notes of gesetze-im-internet (SPEC.md § 24.1).
 *
 * Real examples this has to survive:
 *
 *   Zuletzt geändert durch Art. 1 G v. 21.7.2026 I Nr. 221      (electronic gazette, since 2023)
 *   Zuletzt geändert durch Art. 11 Abs. 17 G v. 16.4.2026 I Nr. 107
 *   Zuletzt geändert durch Art. 2c G v. 24.7.2026 I Nr. 228     (lettered articles)
 *   Geändert durch Art. 584 V v. 31.8.2015 I 1474               (printed gazette, page)
 *   Neugefasst durch Bek. v. 25.2.2008 I 162;                   (re-publication)
 *   Änderung durch Art. 3 G v. 22.7.2026 I Nr. 222 textlich nachgewiesen, …  (announced only)
 *
 * The same act is cited by the gazette adapter as well, and both must produce the same change id —
 * otherwise one act would create two pull requests and two cards.
 */
final class AmendingActCitationParser
{
    /**
     * The citation core: an optional article, the kind of act, the date, the part of the gazette
     * and either an issue number (since 2023) or a page.
     */
    private const string PATTERN = '/'
        .'(?:Art\.\s*(?<article>\d+\s*[a-z]?)(?:\s+Abs\.\s*(?<paragraph>\d+))?\s+)?'
        .'\b(?<kind>Bek\.|VO|G|V)\s+v\.\s*'
        .'(?<day>\d{1,2})\.\s*(?<month>\d{1,2})\.\s*(?<year>\d{4})\s+'
        .'(?<part>I{1,2})\s+'
        .'(?:Nr\.\s*(?<number>\d+)|(?<page>\d{1,6}))'
        .'/u';

    /**
     * Returns the first citation found in the note, or null when the note names no act.
     */
    public function parse(?string $note): ?AmendingActReference
    {
        if (null === $note || '' === trim($note)) {
            return null;
        }

        if (1 !== preg_match(self::PATTERN, $note, $matches)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!j.n.Y',
            \sprintf('%d.%d.%d', (int) $matches['day'], (int) $matches['month'], (int) $matches['year']),
        );

        if (false === $date) {
            return null;
        }

        // An unmatched alternative is either absent or an empty string, depending on its position.
        $number = '' !== ($matches['number'] ?? '') ? (int) $matches['number'] : null;
        $page = null === $number && '' !== ($matches['page'] ?? '') ? (int) $matches['page'] : null;

        if (null === $number && null === $page) {
            return null;
        }

        return new AmendingActReference(
            kind: AmendingActKind::fromCitation($matches['kind']),
            date: $date,
            part: $matches['part'],
            number: $number,
            page: $page,
            article: $this->article($matches),
            sourceText: trim($note),
        );
    }

    /**
     * The change id an act gets in our system, or null when the note names no act.
     */
    public function changeId(?string $note, string $jurisdiction = 'bund'): ?string
    {
        return $this->parse($note)?->changeId($jurisdiction);
    }

    /**
     * "Hinweis" notes announce a change that is *not yet* in the consolidated text — the trigger for
     * a preview pull request (SPEC.md § 4.5). Notes that end in "ist berücksichtigt" are already
     * incorporated and must not trigger one.
     */
    public function isPending(string $note): bool
    {
        return !str_contains($note, 'ist berücksichtigt');
    }

    /**
     * @param array<int|string, string> $matches as returned by preg_match with named groups
     */
    private function article(array $matches): ?string
    {
        $article = isset($matches['article']) ? trim(preg_replace('/\s+/', '', $matches['article']) ?? '') : '';
        if ('' === $article) {
            return null;
        }

        $label = 'Art. '.$article;
        if (isset($matches['paragraph']) && '' !== $matches['paragraph']) {
            $label .= ' Abs. '.$matches['paragraph'];
        }

        return $label;
    }
}
