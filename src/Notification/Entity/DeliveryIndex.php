<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Content\Enum\SubjectType;
use App\Notification\Enum\NotificationKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The minimal, long-lived record that a recipient was informed about a subject (SPEC.md § 24.9).
 *
 * It outlives the detailed Notification rows (which are pruned after 90 days) because corrections
 * must reach exactly the people who received the original message. On account deletion the
 * recipient key is anonymised instead of removed, so the counts stay correct.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delivery_index')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_index', columns: ['recipient_key', 'subject_id', 'kind'])]
#[ORM\Index(name: 'idx_delivery_index_subject', columns: ['subject_type', 'subject_id'])]
class DeliveryIndex
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** "user:{id}", "tg:{id}" or "anon:{hash}" after the account was deleted. */
    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $recipientKey;

    #[ORM\Column(length: 16, enumType: SubjectType::class)]
    private SubjectType $subjectType;

    #[ORM\Column(length: 96, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $subjectId;

    #[ORM\Column(length: 32, enumType: NotificationKind::class)]
    private NotificationKind $kind;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSentAt;

    public function __construct(
        string $recipientKey,
        SubjectType $subjectType,
        string $subjectId,
        NotificationKind $kind,
        \DateTimeImmutable $firstSentAt,
    ) {
        $this->recipientKey = $recipientKey;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->kind = $kind;
        $this->firstSentAt = $firstSentAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function recipientKey(): string
    {
        return $this->recipientKey;
    }

    public function anonymise(string $anonymousKey): void
    {
        $this->recipientKey = $anonymousKey;
    }

    public function subjectType(): SubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function kind(): NotificationKind
    {
        return $this->kind;
    }

    public function firstSentAt(): \DateTimeImmutable
    {
        return $this->firstSentAt;
    }
}
