<?php

declare(strict_types=1);

namespace App\Laws\Entity;

use App\Content\Entity\Change;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The German text of a norm at one commit of the laws repository (SPEC.md § 17).
 *
 * Versions are what the website diffs, blames and translates; they are rebuilt from git, never
 * edited in place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'norm_version')]
#[ORM\UniqueConstraint(name: 'uniq_norm_version_commit', columns: ['norm_id', 'git_commit'])]
#[ORM\Index(name: 'idx_norm_version_hash', columns: ['norm_id', 'content_hash'])]
class NormVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Norm::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Norm $norm;

    /** sha256 of the normalised Markdown body, used to detect real changes. */
    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $contentHash;

    #[ORM\Column(type: Types::TEXT)]
    private string $contentDe;

    #[ORM\Column(length: 40, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $gitCommit;

    /** Commit date in UTC; legal dates live in the change facts, not in git metadata (SPEC.md § 4.5). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $committedAt;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'change_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Change $change = null;

    public function __construct(
        Norm $norm,
        string $contentDe,
        string $contentHash,
        string $gitCommit,
        \DateTimeImmutable $committedAt,
    ) {
        $this->norm = $norm;
        $this->contentDe = $contentDe;
        $this->contentHash = $contentHash;
        $this->gitCommit = $gitCommit;
        $this->committedAt = $committedAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function norm(): Norm
    {
        return $this->norm;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function contentDe(): string
    {
        return $this->contentDe;
    }

    public function gitCommit(): string
    {
        return $this->gitCommit;
    }

    public function committedAt(): \DateTimeImmutable
    {
        return $this->committedAt;
    }

    public function change(): ?Change
    {
        return $this->change;
    }

    public function setChange(?Change $change): void
    {
        $this->change = $change;
    }
}
