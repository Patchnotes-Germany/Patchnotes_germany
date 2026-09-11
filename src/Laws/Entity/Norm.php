<?php

declare(strict_types=1);

namespace App\Laws\Entity;

use App\Laws\Enum\NormStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One norm of a law: a §, Artikel, Anlage, Eingangsformel or Schlussformel — one file in the laws
 * repository (SPEC.md § 4.1, § 4.2).
 *
 * The key comes from the source designation ("p18g", "art3", "anl1"); norms without one get
 * "n-{doknr}", duplicates inside a law are prefixed with their parent ("anl2-p1") — SPEC.md § 24.1.
 */
#[ORM\Entity]
#[ORM\Table(name: 'norm')]
#[ORM\UniqueConstraint(name: 'uniq_norm_law_key', columns: ['law_id', 'norm_key'])]
#[ORM\Index(name: 'idx_norm_status', columns: ['status'])]
class Norm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Law::class, inversedBy: 'norms')]
    #[ORM\JoinColumn(nullable: false)]
    private Law $law;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $normKey;

    /** Designation as printed, e.g. "§ 18g" or "Art 3". */
    #[ORM\Column(length: 64, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $designation = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $title = null;

    /** Order inside the law; "position" avoids the reserved word "order" (SPEC.md § 17). */
    #[ORM\Column(options: ['default' => 0])]
    private int $position;

    #[ORM\Column(length: 16, enumType: NormStatus::class)]
    private NormStatus $status = NormStatus::InForce;

    /** Identifier at the source; not a stable identity on its own (SPEC.md § 24.1). */
    #[ORM\Column(length: 64, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $sourceDoknr = null;

    #[ORM\ManyToOne(targetEntity: NormVersion::class)]
    #[ORM\JoinColumn(name: 'current_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?NormVersion $currentVersion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Law $law, string $normKey, int $position = 0)
    {
        $this->law = $law;
        $this->normKey = $normKey;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function law(): Law
    {
        return $this->law;
    }

    public function normKey(): string
    {
        return $this->normKey;
    }

    /** The canonical reference used in facts, placeholders, URLs and the database (SPEC.md § 24.1). */
    public function reference(): string
    {
        return $this->law->reference().'/'.$this->normKey;
    }

    public function designation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): void
    {
        $this->designation = $designation;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function status(): NormStatus
    {
        return $this->status;
    }

    public function setStatus(NormStatus $status): void
    {
        $this->status = $status;
    }

    public function sourceDoknr(): ?string
    {
        return $this->sourceDoknr;
    }

    public function setSourceDoknr(?string $doknr): void
    {
        $this->sourceDoknr = $doknr;
    }

    public function currentVersion(): ?NormVersion
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(?NormVersion $version): void
    {
        $this->currentVersion = $version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
