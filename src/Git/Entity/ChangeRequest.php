<?php

declare(strict_types=1);

namespace App\Git\Entity;

use App\Content\Entity\Bill;
use App\Content\Entity\Change;
use App\Git\Enum\ChangeRequestKind;
use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\RepositoryName;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pull request (GitLab: merge request) in the laws or content repository (SPEC.md § 3.1, § 4.5).
 *
 * A change has many change requests: one act can reach the consolidated texts of different laws on
 * different days, so the old "laws_pr_url" field is deliberately not used (SPEC.md § 24.4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'change_request')]
#[ORM\UniqueConstraint(name: 'uniq_change_request_forge', columns: ['repository', 'forge_id'])]
#[ORM\Index(name: 'idx_change_request_status', columns: ['status'])]
#[ORM\Index(name: 'idx_change_request_branch', columns: ['repository', 'branch'])]
class ChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'repository', length: 16, enumType: RepositoryName::class)]
    private RepositoryName $repository;

    /** Number or id at the forge; null while the forge is "none" (local branches only). */
    #[ORM\Column(length: 64, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $forgeId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $branch;

    #[ORM\Column(length: 16, enumType: ChangeRequestKind::class)]
    private ChangeRequestKind $kind;

    #[ORM\Column(length: 16, enumType: ChangeRequestStatus::class)]
    private ChangeRequestStatus $status = ChangeRequestStatus::Open;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $labels = [];

    /**
     * Result of the automated checks reported back into the pull request (SPEC.md § 4.6, § 7.4).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $checks = [];

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'change_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Change $change = null;

    #[ORM\ManyToOne(targetEntity: Bill::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Bill $bill = null;

    /**
     * For preview pull requests: how closely the AI-applied text matched the official consolidated
     * version, between 0 and 1. Feeds the AI quality statistics (SPEC.md § 4.5).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $previewAccuracy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mergedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 40, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $mergeCommit = null;

    public function __construct(RepositoryName $repository, string $branch, ChangeRequestKind $kind)
    {
        $this->repository = $repository;
        $this->branch = $branch;
        $this->kind = $kind;
        $this->openedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function repository(): RepositoryName
    {
        return $this->repository;
    }

    public function forgeId(): ?string
    {
        return $this->forgeId;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function setForgeReference(?string $forgeId, ?string $url): void
    {
        $this->forgeId = $forgeId;
        $this->url = $url;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function kind(): ChangeRequestKind
    {
        return $this->kind;
    }

    public function status(): ChangeRequestStatus
    {
        return $this->status;
    }

    public function setStatus(ChangeRequestStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * @param list<string> $labels
     */
    public function setLabels(array $labels): void
    {
        $this->labels = $labels;
    }

    /**
     * @return array<string, mixed>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @param array<string, mixed> $checks
     */
    public function setChecks(array $checks): void
    {
        $this->checks = $checks;
    }

    public function change(): ?Change
    {
        return $this->change;
    }

    public function setChange(?Change $change): void
    {
        $this->change = $change;
    }

    public function bill(): ?Bill
    {
        return $this->bill;
    }

    public function setBill(?Bill $bill): void
    {
        $this->bill = $bill;
    }

    public function previewAccuracy(): ?float
    {
        return $this->previewAccuracy;
    }

    public function setPreviewAccuracy(?float $accuracy): void
    {
        $this->previewAccuracy = $accuracy;
    }

    public function openedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function mergedAt(): ?\DateTimeImmutable
    {
        return $this->mergedAt;
    }

    public function mergeCommit(): ?string
    {
        return $this->mergeCommit;
    }

    public function markMerged(\DateTimeImmutable $at, ?string $mergeCommit): void
    {
        $this->status = ChangeRequestStatus::Merged;
        $this->mergedAt = $at;
        $this->mergeCommit = $mergeCommit;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function markClosed(\DateTimeImmutable $at): void
    {
        $this->status = ChangeRequestStatus::Closed;
        $this->closedAt = $at;
    }
}
