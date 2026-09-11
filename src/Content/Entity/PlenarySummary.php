<?php

declare(strict_types=1);

namespace App\Content\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Bundestag this week": a strictly neutral summary of the plenary protocols of one ISO week
 * (SPEC.md § 6.2 D, § 16.3). Texts live in Cards with subject type "plenary".
 */
#[ORM\Entity]
#[ORM\Table(name: 'plenary_summary')]
#[ORM\UniqueConstraint(name: 'uniq_plenary_week', columns: ['iso_week'])]
class PlenarySummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $isoWeek;

    /**
     * Plenarprotokoll references the summary is based on.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $protocols = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

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
     * @return list<array<string, mixed>>
     */
    public function protocols(): array
    {
        return $this->protocols;
    }

    /**
     * @param list<array<string, mixed>> $protocols
     */
    public function setProtocols(array $protocols): void
    {
        $this->protocols = $protocols;
    }

    public function generatedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function markGenerated(\DateTimeImmutable $at): void
    {
        $this->generatedAt = $at;
    }
}
