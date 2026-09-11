<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Web Push endpoint of one browser (SPEC.md § 12.2). Endpoints that the push service rejects as
 * gone are deleted, so a stale device stops costing delivery attempts.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_endpoint', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $endpointHash;

    #[ORM\Column(length: 255)]
    private string $publicKey;

    #[ORM\Column(length: 255)]
    private string $authToken;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authToken, string $lang)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->lang = $lang;
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

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function authToken(): string
    {
        return $this->authToken;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
    }
}
