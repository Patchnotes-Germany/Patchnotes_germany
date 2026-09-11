<?php

declare(strict_types=1);

namespace App\Content\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The weekly digest of one ISO week (SPEC.md § 5.1, § 12.1).
 *
 * The texts themselves are Cards with subject type "digest"; this row holds which changes the week
 * contained and when it was generated and sent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'digest')]
#[ORM\UniqueConstraint(name: 'uniq_digest_week', columns: ['iso_week'])]
class Digest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO week identifier, e.g. "2026-W37" — also the directory name in the content repository. */
    #[ORM\Column(length: 16, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $isoWeek;

    /**
     * Change ids covered by this digest, grouped by topic for the template.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $changeIds = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $billIds = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(string $isoWeek)
    {
        $this->isoWeek = $isoWeek;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function isoWeek(): string
    {
        return $this->isoWeek;
    }

    public function subjectId(): string
    {
        return $this->isoWeek;
    }

    /**
     * @return list<string>
     */
    public function changeIds(): array
    {
        return $this->changeIds;
    }

    /**
     * @param list<string> $changeIds
     */
    public function setChangeIds(array $changeIds): void
    {
        $this->changeIds = $changeIds;
    }

    /**
     * @return list<string>
     */
    public function billIds(): array
    {
        return $this->billIds;
    }

    /**
     * @param list<string> $billIds
     */
    public function setBillIds(array $billIds): void
    {
        $this->billIds = $billIds;
    }

    public function generatedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function markGenerated(\DateTimeImmutable $at): void
    {
        $this->generatedAt = $at;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }
}
