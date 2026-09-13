<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

/**
 * Derives the stable key — and therefore the file name — of a norm (SPEC.md § 24.1).
 *
 *   "§ 18g"        → p18g
 *   "§§ 5 bis 7"   → p5-7        (joint norms, e.g. "§§ 5 bis 7 (weggefallen)")
 *   "Art 3"        → art3
 *   "Anlage 1"     → anl1
 *   "Eingangsformel" → eingangsformel
 *   no designation → n-{doknr}
 *
 * Keys must stay stable across re-publications, because they are the file names in git and the
 * reference format `{jurisdiction}/{law-slug}/{norm-key}` used in facts, URLs and the database.
 * The source's `doknr` is *not* the identity: it changes when a law is re-issued (§ 24.1).
 */
final class NormKeyFactory
{
    private const array TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss',
    ];

    /**
     * @param string|null $designation the source designation (`enbez`)
     * @param string|null $documentId  the source document number, used as the fallback identity
     * @param string|null $parentKey   key of the enclosing Anlage/Artikel, used to disambiguate
     *                                 duplicate designations inside one law
     */
    public function fromDesignation(?string $designation, ?string $documentId = null, ?string $parentKey = null): string
    {
        $key = $this->baseKey($designation, $documentId);

        return null !== $parentKey && '' !== $parentKey ? $parentKey.'-'.$key : $key;
    }

    /**
     * Ensures the key is unique within the law; duplicates get a numeric suffix so that no norm is
     * ever silently overwritten.
     *
     * @param list<string> $taken
     */
    public function unique(string $key, array $taken): string
    {
        if (!\in_array($key, $taken, true)) {
            return $key;
        }

        $suffix = 2;
        while (\in_array($key.'-'.$suffix, $taken, true)) {
            ++$suffix;
        }

        return $key.'-'.$suffix;
    }

    private function baseKey(?string $designation, ?string $documentId): string
    {
        $designation = trim((string) $designation);

        if ('' === $designation) {
            return $this->fallback($documentId);
        }

        $normalised = $this->normalise($designation);

        // "§§ 5 bis 7", "§§ 5 und 6", "§§ 5-7" → p5-7
        if (1 === preg_match('/^§§\s*(\d+[a-z]*)\s*(?:bis|und|-|,)\s*(\d+[a-z]*)/u', $normalised, $matches)) {
            return 'p'.strtolower($matches[1]).'-'.strtolower($matches[2]);
        }

        // "§ 18g", "§ 35a"
        if (1 === preg_match('/^§+\s*(\d+[a-z]*)/u', $normalised, $matches)) {
            return 'p'.strtolower($matches[1]);
        }

        // "Art 3", "Artikel 3a"
        if (1 === preg_match('/^Art(?:ikel)?\.?\s*(\d+[a-z]*)/ui', $normalised, $matches)) {
            return 'art'.strtolower($matches[1]);
        }

        // "Anlage 1", "Anlage I", "Anhang 2", or a bare "Anlage"
        if (1 === preg_match('/^An(?:lage|hang)\.?\s*([\dIVXivx]*[a-z]?)/ui', $normalised, $matches)) {
            $number = strtolower($matches[1]);

            return 'anl'.$number;
        }

        $slug = $this->slugify($normalised);

        return '' !== $slug ? $slug : $this->fallback($documentId);
    }

    private function fallback(?string $documentId): string
    {
        $documentId = trim((string) $documentId);

        return 'n-'.('' !== $documentId ? strtolower($documentId) : 'unknown');
    }

    private function normalise(string $value): string
    {
        // Non-breaking spaces around "§" are common in the source.
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00a0}", "\u{202f}"], ' ', $value)));
    }

    private function slugify(string $value): string
    {
        $value = strtr($value, self::TRANSLITERATION);
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }
}
