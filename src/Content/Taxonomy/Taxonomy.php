<?php

declare(strict_types=1);

namespace App\Content\Taxonomy;

/**
 * The vocabulary shared by user profiles and change audiences (SPEC.md § 9.1).
 *
 * One dictionary for both sides is what makes the matching of § 9.2 deterministic and explainable:
 * a change says `any_of: [blue_card]`, a person ticked `blue_card`, and the site can tell them
 * exactly why they are seeing the card. A tag outside this vocabulary matches nobody, which is why
 * the AI may only choose from here and anything else goes to `suggested_tags` for a human.
 */
final readonly class Taxonomy
{
    /**
     * @param array<string, list<string>>          $groups    group key => tag keys, in file order
     * @param list<string>                         $exclusive groups where only one answer is possible
     * @param list<string>                         $topics
     * @param list<string>                         $lands
     * @param array<string, array<string, string>> $tagNames  tag key => language => name
     */
    public function __construct(
        private array $groups,
        private array $exclusive,
        private array $topics,
        private array $lands,
        private array $tagNames = [],
    ) {
    }

    /**
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return list<string>
     */
    public function tagKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->groups) ?: [[]])));
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return $this->topics;
    }

    /**
     * @return list<string>
     */
    public function lands(): array
    {
        return $this->lands;
    }

    public function hasTag(string $tag): bool
    {
        return \in_array($tag, $this->tagKeys(), true);
    }

    public function hasTopic(string $topic): bool
    {
        return \in_array($topic, $this->topics, true);
    }

    public function hasLand(string $land): bool
    {
        return \in_array($land, $this->lands, true);
    }

    public function groupOf(string $tag): ?string
    {
        foreach ($this->groups as $group => $tags) {
            if (\in_array($tag, $tags, true)) {
                return $group;
            }
        }

        return null;
    }

    public function isExclusiveGroup(string $group): bool
    {
        return \in_array($group, $this->exclusive, true);
    }

    public function nameOf(string $tag, string $language): string
    {
        return $this->tagNames[$tag][$language] ?? $tag;
    }

    /**
     * Tags an answer used that this vocabulary does not contain — the ones a human has to look at.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public function unknownTags(array $tags): array
    {
        return array_values(array_filter($tags, fn (string $tag): bool => !$this->hasTag($tag)));
    }

    /**
     * @param list<string> $topics
     *
     * @return list<string>
     */
    public function unknownTopics(array $topics): array
    {
        return array_values(array_filter($topics, fn (string $topic): bool => !$this->hasTopic($topic)));
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups && [] === $this->topics;
    }
}
