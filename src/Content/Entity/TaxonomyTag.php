<?php

declare(strict_types=1);

namespace App\Content\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tag of taxonomy.yml (SPEC.md § 9.1): the shared vocabulary of user profiles and change
 * audiences. AI may only choose from these tags; anything else lands in suggested_tags for a human.
 *
 * "tag_group" and "tag_key" avoid the reserved words GROUP and KEY (SPEC.md § 17).
 */
#[ORM\Entity]
#[ORM\Table(name: 'taxonomy_tag')]
#[ORM\UniqueConstraint(name: 'uniq_taxonomy_tag_key', columns: ['tag_key'])]
#[ORM\Index(name: 'idx_taxonomy_group', columns: ['tag_group'])]
class TaxonomyTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $tagKey;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $tagGroup;

    /**
     * Display names per language.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $names = [];

    /**
     * "Who should pick this" help text per language.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $descriptions = [];

    /** The group allows a single choice only (e.g. residence status, age band). */
    #[ORM\Column(options: ['default' => false])]
    private bool $exclusiveGroup = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(string $tagKey, string $tagGroup)
    {
        $this->tagKey = $tagKey;
        $this->tagGroup = $tagGroup;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tagKey(): string
    {
        return $this->tagKey;
    }

    public function tagGroup(): string
    {
        return $this->tagGroup;
    }

    /**
     * @return array<string, string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function name(string $language): string
    {
        return $this->names[$language] ?? $this->tagKey;
    }

    /**
     * @param array<string, string> $names
     */
    public function setNames(array $names): void
    {
        $this->names = $names;
    }

    /**
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        return $this->descriptions;
    }

    /**
     * @param array<string, string> $descriptions
     */
    public function setDescriptions(array $descriptions): void
    {
        $this->descriptions = $descriptions;
    }

    public function isExclusiveGroup(): bool
    {
        return $this->exclusiveGroup;
    }

    public function setExclusiveGroup(bool $exclusive): void
    {
        $this->exclusiveGroup = $exclusive;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
