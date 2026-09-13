<?php

declare(strict_types=1);

namespace App\Content\Taxonomy;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads `taxonomy.yml` from the content repository (SPEC.md § 9.1).
 *
 * The file is edited by people through pull requests, so a broken or half-written version must not
 * take the site down: parsing is forgiving about missing parts and simply yields a smaller
 * vocabulary. What it never does is invent tags.
 */
final readonly class TaxonomyFile
{
    public const string FILENAME = 'taxonomy.yml';

    public function parse(string $yaml): Taxonomy
    {
        try {
            /** @var array<string, mixed>|null $data */
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new \RuntimeException('taxonomy.yml is not valid YAML: '.$exception->getMessage(), 0, $exception);
        }

        if (!\is_array($data)) {
            return new Taxonomy([], [], [], []);
        }

        [$groups, $exclusive, $names] = $this->readGroups($data['groups'] ?? null);

        return new Taxonomy(
            $groups,
            $exclusive,
            $this->stringList($data['topics'] ?? null),
            $this->stringList($data['lands'] ?? null),
            $names,
        );
    }

    /**
     * Both shapes are accepted: a group may list its tags as a plain list of keys, or as a map of
     * key => {names, description}. The specification shows the first, the generated file uses the
     * second, and a contributor should not have to care.
     *
     * @return array{array<string, list<string>>, list<string>, array<string, array<string, string>>}
     */
    private function readGroups(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [[], [], []];
        }

        $groups = [];
        $exclusive = [];
        $names = [];

        /** @var array<string, mixed> $raw */
        foreach ($raw as $groupKey => $group) {
            $groupKey = (string) $groupKey;

            if (!\is_array($group)) {
                continue;
            }

            if (filter_var($group['exclusive'] ?? false, \FILTER_VALIDATE_BOOL)) {
                $exclusive[] = $groupKey;
            }

            $tags = $group['tags'] ?? null;

            if (!\is_array($tags)) {
                $groups[$groupKey] = [];

                continue;
            }

            $keys = [];

            foreach ($tags as $key => $value) {
                if (\is_int($key) && \is_string($value)) {
                    $keys[] = $value;

                    continue;
                }

                $tagKey = (string) $key;
                $keys[] = $tagKey;

                if (\is_array($value) && \is_array($value['names'] ?? null)) {
                    /** @var array<string, mixed> $rawNames */
                    $rawNames = $value['names'];
                    $names[$tagKey] = array_map(strval(...), array_filter($rawNames, is_scalar(...)));
                }
            }

            $groups[$groupKey] = $keys;
        }

        return [$groups, $exclusive, $names];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
            $raw,
        ), static fn (string $value): bool => '' !== $value));
    }
}
