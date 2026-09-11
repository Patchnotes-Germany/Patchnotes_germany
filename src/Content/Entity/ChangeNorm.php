<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Laws\Entity\Norm;
use App\Laws\Entity\NormVersion;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which norms a change touched, and their text before and after (SPEC.md § 17).
 *
 * This is what the website's diff view and the "affected laws" list are built from.
 */
#[ORM\Entity]
#[ORM\Table(name: 'change_norm')]
#[ORM\UniqueConstraint(name: 'uniq_change_norm', columns: ['change_id', 'norm_id'])]
class ChangeNorm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'change_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Change $change;

    #[ORM\ManyToOne(targetEntity: Norm::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Norm $norm;

    /** Null when the norm was introduced by this change. */
    #[ORM\ManyToOne(targetEntity: NormVersion::class)]
    #[ORM\JoinColumn(name: 'before_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?NormVersion $beforeVersion = null;

    /** Null when the norm was repealed by this change. */
    #[ORM\ManyToOne(targetEntity: NormVersion::class)]
    #[ORM\JoinColumn(name: 'after_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?NormVersion $afterVersion = null;

    public function __construct(Change $change, Norm $norm)
    {
        $this->change = $change;
        $this->norm = $norm;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function change(): Change
    {
        return $this->change;
    }

    public function norm(): Norm
    {
        return $this->norm;
    }

    public function beforeVersion(): ?NormVersion
    {
        return $this->beforeVersion;
    }

    public function afterVersion(): ?NormVersion
    {
        return $this->afterVersion;
    }

    public function setVersions(?NormVersion $before, ?NormVersion $after): void
    {
        $this->beforeVersion = $before;
        $this->afterVersion = $after;
    }

    public function isIntroduction(): bool
    {
        return !$this->beforeVersion instanceof NormVersion && $this->afterVersion instanceof NormVersion;
    }

    public function isRepeal(): bool
    {
        return $this->beforeVersion instanceof NormVersion && !$this->afterVersion instanceof NormVersion;
    }
}
