<?php

declare(strict_types=1);

namespace App\Content\Facts;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes `facts.yml` — the single source of truth for every number and date of a change
 * (SPEC.md § 5.3).
 *
 * Cards contain placeholders, never figures, so this file is what a reader ultimately sees when a
 * card says "the threshold rises". It is therefore written deterministically: the same facts always
 * produce byte-identical YAML, in a fixed key order. Without that, every pipeline rerun would
 * produce a diff, open a pull request and notify people about a change that did not happen.
 */
final readonly class FactsFile
{
    public const string FILENAME = 'facts.yml';

    /** Top-level keys in the order of SPEC.md § 5.3; unknown keys keep their place at the end. */
    private const array KEY_ORDER = [
        'id', 'kind', 'jurisdiction', 'lands', 'title_de', 'amending_act', 'affected_laws',
        'stage', 'dates', 'amounts', 'audience', 'topics', 'impact', 'impact_rationale',
        'confidence', 'uncertainties', 'review', 'ai', 'sources', 'corrections',
    ];

    /**
     * @return array<string, mixed>
     */
    public function parse(string $yaml): array
    {
        try {
            /** @var array<string, mixed>|null $parsed */
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new \RuntimeException('facts.yml is not valid YAML: '.$exception->getMessage(), 0, $exception);
        }

        return \is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $facts
     */
    public function render(array $facts): string
    {
        return Yaml::dump($this->order($facts), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    public function order(array $facts): array
    {
        $ordered = [];

        foreach (self::KEY_ORDER as $key) {
            if (\array_key_exists($key, $facts)) {
                $ordered[$key] = $facts[$key];
            }
        }

        // Anything the specification does not know about is kept rather than dropped: a hand-edited
        // field must survive a machine rewrite.
        foreach ($facts as $key => $value) {
            if (!\array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        if (isset($ordered['amounts']) && \is_array($ordered['amounts'])) {
            $ordered['amounts'] = $this->sortAmounts($ordered['amounts']);
        }

        return $ordered;
    }

    /**
     * Amounts are keyed and order-independent in meaning, so they are sorted by key: two runs that
     * find the same amounts must produce the same file.
     *
     * @param array<int|string, mixed> $amounts
     *
     * @return list<mixed>
     */
    private function sortAmounts(array $amounts): array
    {
        $list = array_values($amounts);

        usort($list, static function (mixed $left, mixed $right): int {
            $leftKey = \is_array($left) && \is_string($left['key'] ?? null) ? $left['key'] : '';
            $rightKey = \is_array($right) && \is_string($right['key'] ?? null) ? $right['key'] : '';

            return strcmp($leftKey, $rightKey);
        });

        return $list;
    }
}
