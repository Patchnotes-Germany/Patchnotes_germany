<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-recipient delivery settings (SPEC.md § 10, § 12.1): which channels, which kinds, quiet hours
 * and how many instant alerts a day are acceptable.
 *
 * A recipient is either an account or an anonymous Telegram chat — exactly one of the two.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_telegram', columns: ['telegram_link_id'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\OneToOne(targetEntity: TelegramLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TelegramLink $telegramLink = null;

    /**
     * Channel value => enabled.
     *
     * @var array<string, bool>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $channels = [];

    /**
     * Notification kind value => enabled.
     *
     * @var array<string, bool>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $kinds = [];

    /** Local time; null means "use the configured default" (22:00-08:00). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $quietHoursStart = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $quietHoursEnd = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxInstantPerDay = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function forUser(User $user): self
    {
        $preference = new self();
        $preference->user = $user;

        return $preference;
    }

    public static function forTelegram(TelegramLink $link): self
    {
        $preference = new self();
        $preference->telegramLink = $link;

        return $preference;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function telegramLink(): ?TelegramLink
    {
        return $this->telegramLink;
    }

    /**
     * @return array<string, bool>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    public function isChannelEnabled(string $channel, bool $default = true): bool
    {
        return $this->channels[$channel] ?? $default;
    }

    /**
     * @param array<string, bool> $channels
     */
    public function setChannels(array $channels): void
    {
        $this->channels = $channels;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, bool>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    public function isKindEnabled(string $kind, bool $default = true): bool
    {
        return $this->kinds[$kind] ?? $default;
    }

    /**
     * @param array<string, bool> $kinds
     */
    public function setKinds(array $kinds): void
    {
        $this->kinds = $kinds;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function quietHoursStart(): ?string
    {
        return $this->quietHoursStart;
    }

    public function quietHoursEnd(): ?string
    {
        return $this->quietHoursEnd;
    }

    public function setQuietHours(?string $start, ?string $end): void
    {
        $this->quietHoursStart = $start;
        $this->quietHoursEnd = $end;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function maxInstantPerDay(): ?int
    {
        return $this->maxInstantPerDay;
    }

    public function setMaxInstantPerDay(?int $max): void
    {
        $this->maxInstantPerDay = $max;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
