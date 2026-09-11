<?php

declare(strict_types=1);

namespace App\User\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Proof of consent: what was agreed to, in which version of the text, and when (SPEC.md § 10).
 *
 * Deliberately minimal — no IP address, no user agent: the version and the timestamp are what a
 * data protection audit needs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'consent_record')]
#[ORM\Index(name: 'idx_consent_user_type', columns: ['user_id', 'consent_type'])]
class ConsentRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** e.g. "terms", "privacy", "newsletter", "telegram". */
    #[ORM\Column(name: 'consent_type', length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $type;

    #[ORM\Column(length: 32)]
    private string $textVersion;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $type, string $textVersion)
    {
        $this->user = $user;
        $this->type = $type;
        $this->textVersion = $textVersion;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function textVersion(): string
    {
        return $this->textVersion;
    }

    public function grantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt = $at;
    }

    public function isActive(): bool
    {
        return !$this->revokedAt instanceof \DateTimeImmutable;
    }
}
