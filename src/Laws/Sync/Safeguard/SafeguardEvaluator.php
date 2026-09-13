<?php

declare(strict_types=1);

namespace App\Laws\Sync\Safeguard;

use App\Core\Config\PatchnotesConfig;
use App\Laws\Sync\LawSyncResult;
use App\Laws\Sync\SyncOutcome;

/**
 * The checks that stand between an automatic synchronisation and an automatic merge
 * (SPEC.md § 4.6).
 *
 * Their purpose is narrow and important: a broken parser or a changed source format must never be
 * able to silently delete the text of German laws. When anything looks wrong the pull request stays
 * open with a `needs-review` label and an administrator is alerted — publishing nothing is always
 * better than publishing something wrong (SPEC.md § 0.5, closing note).
 */
final readonly class SafeguardEvaluator
{
    /** Elements the law Markdown may contain; everything else is a parser accident. */
    private const array ALLOWED_HTML = ['table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'sup', 'sub', 'br'];

    /** Below this many laws the "too many changed" ratio carries no information. */
    private const int MINIMUM_LAWS_FOR_RATIO_CHECK = 20;

    public function __construct(private PatchnotesConfig $config)
    {
    }

    public function evaluate(SafeguardInput $input): SafeguardReport
    {
        $violations = [
            ...$this->checkDeletions($input),
            ...$this->checkRunSize($input),
            ...$this->checkEncoding($input),
            ...$this->checkHtml($input),
            ...$this->checkDeterminism($input),
        ];

        return new SafeguardReport($violations);
    }

    /**
     * A pull request that removes more than `max_law_deletion_ratio` of a law's text — without the
     * source declaring the law repealed — is almost certainly a conversion failure.
     *
     * @return list<SafeguardViolation>
     */
    private function checkDeletions(SafeguardInput $input): array
    {
        $limit = $this->ratio('max_law_deletion_ratio', 0.4);
        $violations = [];

        foreach ($input->laws as $law) {
            if (SyncOutcome::Repealed === $law->outcome || $input->sourceMarksRepealed($law->slug)) {
                continue;
            }

            $ratio = $law->deletionRatio();
            if ($ratio > $limit) {
                $violations[] = new SafeguardViolation(
                    'law_text_deleted',
                    \sprintf(
                        '%s: %.0f %% of the text disappeared (limit %.0f %%), and the source does not declare the law repealed.',
                        $law->slug,
                        $ratio * 100,
                        $limit * 100,
                    ),
                    $law->slug,
                );
            }
        }

        return $violations;
    }

    /**
     * More than `max_changed_laws_ratio` of a jurisdiction changing at once means the source changed,
     * not the law.
     *
     * @return list<SafeguardViolation>
     */
    private function checkRunSize(SafeguardInput $input): array
    {
        $limit = $this->ratio('max_changed_laws_ratio', 0.3);

        // The ratio only says something about a full run. A targeted synchronisation of a handful
        // of laws, or the very first import, would otherwise always trip it.
        if ($input->lawsInJurisdiction < self::MINIMUM_LAWS_FOR_RATIO_CHECK) {
            return [];
        }

        $changed = \count(array_filter($input->laws, static fn (LawSyncResult $law): bool => $law->changesTheRepository()));
        $ratio = $changed / $input->lawsInJurisdiction;

        if ($ratio <= $limit) {
            return [];
        }

        return [new SafeguardViolation(
            'too_many_laws_changed',
            \sprintf(
                '%d of %d laws changed in one run (%.0f %%, limit %.0f %%) — this looks like a source or parser change.',
                $changed,
                $input->lawsInJurisdiction,
                $ratio * 100,
                $limit * 100,
            ),
        )];
    }

    /**
     * @return list<SafeguardViolation>
     */
    private function checkEncoding(SafeguardInput $input): array
    {
        $violations = [];

        foreach ($input->files as $path => $content) {
            if (!mb_check_encoding($content, 'UTF-8')) {
                $violations[] = new SafeguardViolation('invalid_encoding', $path.' is not valid UTF-8.', $path);
                continue;
            }

            // Tabs and newlines are fine; other control characters are not.
            if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
                $violations[] = new SafeguardViolation('control_characters', $path.' contains control characters.', $path);
            }

            if (str_contains($content, "\u{FEFF}")) {
                $violations[] = new SafeguardViolation('byte_order_mark', $path.' contains a byte order mark.', $path);
            }
        }

        return $violations;
    }

    /**
     * @return list<SafeguardViolation>
     */
    private function checkHtml(SafeguardInput $input): array
    {
        $violations = [];

        foreach ($input->files as $path => $content) {
            preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $content, $matches);

            foreach (array_unique($matches[1]) as $tag) {
                if (!\in_array(strtolower($tag), self::ALLOWED_HTML, true)) {
                    $violations[] = new SafeguardViolation(
                        'disallowed_html',
                        \sprintf('%s contains the HTML element <%s>, which is not on the allow list.', $path, $tag),
                        $path,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Converting the same source twice must produce the same bytes; otherwise the repository would
     * fill up with diffs that mean nothing.
     *
     * @return list<SafeguardViolation>
     */
    private function checkDeterminism(SafeguardInput $input): array
    {
        if ($input->deterministic) {
            return [];
        }

        return [new SafeguardViolation(
            'not_deterministic',
            'Re-converting the source produced a different result; the conversion is not deterministic.',
        )];
    }

    private function ratio(string $key, float $default): float
    {
        /** @var array{safeguards?: array<string, float|int|string>} $sources */
        $sources = $this->config->sources();
        $value = $sources['safeguards'][$key] ?? $default;

        return (float) $value;
    }
}
