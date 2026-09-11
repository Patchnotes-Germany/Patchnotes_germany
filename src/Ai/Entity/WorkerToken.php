<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Credential of a remote AI worker (SPEC.md § 8.3, § 15).
 *
 * Issued and revoked in the admin; only the hash is stored, so a leaked database row cannot be
 * replayed against the worker API. The heartbeat tells the dashboard whether the owner's computer
 * is online and whether cloud fallback is about to kick in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'worker_token')]
#[ORM\UniqueConstraint(name: 'uniq_worker_token_hash', columns: ['token_hash'])]
class WorkerToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(string $name, string $tokenHash)
    {
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function heartbeat(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }

    public function isActive(): bool
    {
        return !$this->revokedAt instanceof \DateTimeImmutable;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt = $at;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
