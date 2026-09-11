<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Content\Enum\SubjectType;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationKind;
use App\Notification\Enum\NotificationStatus;
use App\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One planned or completed delivery (SPEC.md § 12.1, § 24.9).
 *
 * The idempotency key (recipient, kind, channel, subject, variant) is what makes the whole
 * notification layer safe to replay: a repeated pipeline run schedules nothing twice.
 * Delivery details are pruned after retention.notification_details_days, while the minimal fact
 * "this recipient was told about this subject" lives on in DeliveryIndex.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\UniqueConstraint(name: 'uniq_notification_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_notification_scheduled', columns: ['status', 'scheduled_at'])]
#[ORM\Index(name: 'idx_notification_subject', columns: ['subject_type', 'subject_id'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: TelegramLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TelegramLink $telegramLink = null;

    #[ORM\Column(length: 32, enumType: NotificationKind::class)]
    private NotificationKind $kind;

    #[ORM\Column(length: 24, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(length: 16, enumType: SubjectType::class)]
    private SubjectType $subjectType;

    #[ORM\Column(length: 96, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $subjectId;

    /** Reminder offset ("-14d", "-1d", "0d"), correction id or ISO week (SPEC.md § 24.9). */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $variant;

    #[ORM\Column(length: 128, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $idempotencyKey;

    #[ORM\Column(length: 16, enumType: NotificationStatus::class)]
    private NotificationStatus $status = NotificationStatus::Scheduled;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** Technical error only; never any personal data or message content (SPEC.md § 12.1). */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        NotificationKind $kind,
        NotificationChannel $channel,
        SubjectType $subjectType,
        string $subjectId,
        string $variant,
        string $lang,
        \DateTimeImmutable $scheduledAt,
    ) {
        $this->kind = $kind;
        $this->channel = $channel;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->variant = $variant;
        $this->lang = $lang;
        $this->scheduledAt = $scheduledAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->idempotencyKey = '';
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

    public function setRecipient(?User $user, ?TelegramLink $telegramLink): void
    {
        $this->user = $user;
        $this->telegramLink = $telegramLink;
        $this->idempotencyKey = self::buildIdempotencyKey(
            $this->recipientKey(),
            $this->kind,
            $this->channel,
            $this->subjectId,
            $this->variant,
        );
    }

    /** Stable recipient reference used in the idempotency key and the delivery index. */
    public function recipientKey(): string
    {
        if ($this->user instanceof User) {
            return 'user:'.$this->user->id();
        }

        if ($this->telegramLink instanceof TelegramLink) {
            return 'tg:'.$this->telegramLink->id();
        }

        throw new \LogicException('A notification needs a recipient.');
    }

    public static function buildIdempotencyKey(
        string $recipientKey,
        NotificationKind $kind,
        NotificationChannel $channel,
        string $subjectId,
        string $variant,
    ): string {
        return substr(\sprintf('%s|%s|%s|%s|%s', $recipientKey, $kind->value, $channel->value, $subjectId, $variant), 0, 128);
    }

    public function kind(): NotificationKind
    {
        return $this->kind;
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function subjectType(): SubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function variant(): string
    {
        return $this->variant;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function scheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function reschedule(\DateTimeImmutable $at): void
    {
        $this->scheduledAt = $at;
        $this->status = NotificationStatus::Deferred;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->status = NotificationStatus::Sent;
        $this->sentAt = $at;
        $this->error = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = NotificationStatus::Failed;
        $this->error = substr($error, 0, 512);
    }

    public function cancel(): void
    {
        $this->status = NotificationStatus::Cancelled;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
