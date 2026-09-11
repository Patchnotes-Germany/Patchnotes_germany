<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What administrators and editors did (SPEC.md § 15).
 *
 * Trust is the product here, so every editorial intervention — approving a card, correcting facts,
 * re-running a model, toggling a source — is recorded with who, what and when. The actor label
 * survives account deletion; the relation does not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_subject', columns: ['subject_type', 'subject_id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    /** Kept even if the account is deleted, so the log stays readable. */
    #[ORM\Column(length: 128)]
    private string $actorLabel;

    /** e.g. "card.approve", "card.correct", "source.toggle", "ai.rerun". */
    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $action;

    #[ORM\Column(length: 32, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $subjectType = null;

    #[ORM\Column(length: 96, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $subjectId = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(?User $actor, string $actorLabel, string $action, array $data = [])
    {
        $this->actor = $actor;
        $this->actorLabel = $actorLabel;
        $this->action = $action;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function actorLabel(): string
    {
        return $this->actorLabel;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    public function setSubject(?string $type, ?string $id): void
    {
        $this->subjectType = $type;
        $this->subjectId = $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
