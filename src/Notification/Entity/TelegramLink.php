<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\User\Doctrine\EncryptedJsonType;
use App\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Telegram chat that receives notifications (SPEC.md § 12.2).
 *
 * The bot works with or without an account: an anonymous subscriber only ever gives us a chat id
 * and a set of tags, which are encrypted just like a profile. /stop deletes the row entirely.
 */
#[ORM\Entity]
#[ORM\Table(name: 'telegram_link')]
#[ORM\UniqueConstraint(name: 'uniq_telegram_chat', columns: ['chat_id'])]
#[ORM\Index(name: 'idx_telegram_land', columns: ['land'])]
class TelegramLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Telegram chat ids are 64-bit signed integers, which PHP handles natively. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $chatId;

    /** Set once the chat is linked to an account with a one-time code. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    #[ORM\Column(length: 8, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $land = null;

    /**
     * Anonymous profile tags, encrypted at rest.
     *
     * @var list<string>
     */
    #[ORM\Column(type: EncryptedJsonType::NAME, nullable: true)]
    private ?array $tags = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $topics = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $answeredGroups = [];

    /** /pause stops deliveries without losing the profile. */
    #[ORM\Column(options: ['default' => false])]
    private bool $paused = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct(int $chatId, string $lang)
    {
        $this->chatId = $chatId;
        $this->lang = $lang;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function chatId(): int
    {
        return $this->chatId;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function linkTo(?User $user): void
    {
        $this->user = $user;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function setLang(string $lang): void
    {
        $this->lang = $lang;
    }

    public function land(): ?string
    {
        return $this->land;
    }

    public function setLand(?string $land): void
    {
        $this->land = $land;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags ?? [];
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = array_values(array_unique($tags));
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return $this->topics;
    }

    /**
     * @param list<string> $topics
     */
    public function setTopics(array $topics): void
    {
        $this->topics = array_values(array_unique($topics));
    }

    /**
     * @return list<string>
     */
    public function answeredGroups(): array
    {
        return $this->answeredGroups;
    }

    /**
     * @param list<string> $groups
     */
    public function setAnsweredGroups(array $groups): void
    {
        $this->answeredGroups = array_values(array_unique($groups));
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function setPaused(bool $paused): void
    {
        $this->paused = $paused;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }
}
