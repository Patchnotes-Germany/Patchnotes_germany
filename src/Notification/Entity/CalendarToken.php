<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Secret token of a personal iCal feed, /{locale}/calendar/{token}.ics (SPEC.md § 12.2).
 *
 * The token is the only credential a calendar client sends, so it can be rotated from the settings:
 * the old row is revoked and stops working immediately.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_token')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_token', columns: ['token'])]
class CalendarToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $token;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return !$this->revokedAt instanceof \DateTimeImmutable;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt = $at;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
    }
}
