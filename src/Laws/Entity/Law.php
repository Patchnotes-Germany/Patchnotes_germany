<?php

declare(strict_types=1);

namespace App\Laws\Entity;

use App\Laws\Enum\LawStatus;
use App\Laws\Enum\LawType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One law or regulation, mirroring `{jurisdiction}/{slug}/_law.yml` in the laws repository
 * (SPEC.md § 4.3). The database is a cache of git: patchnotes:rebuild-from-git recreates it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'law')]
#[ORM\UniqueConstraint(name: 'uniq_law_jurisdiction_slug', columns: ['jurisdiction_code', 'slug'])]
#[ORM\Index(name: 'idx_law_status', columns: ['status'])]
#[ORM\Index(name: 'idx_law_abbreviation', columns: ['abbreviation'])]
class Law
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Jurisdiction::class)]
    #[ORM\JoinColumn(name: 'jurisdiction_code', referencedColumnName: 'code', nullable: false)]
    private Jurisdiction $jurisdiction;

    /** Slug as used by the source, e.g. "aufenthg_2004"; unique within the jurisdiction. */
    #[ORM\Column(length: 128, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $slug;

    #[ORM\Column(length: 16, enumType: LawType::class)]
    private LawType $type = LawType::Gesetz;

    #[ORM\Column(length: 16, enumType: LawStatus::class)]
    private LawStatus $status = LawStatus::InForce;

    /** Official abbreviation ("AufenthG"); German abbreviations are compared binary ("Straße" ≠ "Strasse"). */
    #[ORM\Column(length: 64, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $abbreviation = null;

    #[ORM\Column(length: 512)]
    private string $title;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $shortTitle = null;

    /** Ausfertigungsdatum — a legal date without time zone. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateOfIssue = null;

    /** Promulgation reference of the original act, e.g. "BGBl. I 2004, 1950". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $promulgation = null;

    /** Verbatim "Stand" note of the source, e.g. "Zuletzt geändert durch Art. 5 G v. 10.6.2026 I Nr. 123". */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusNote = null;

    /** Normalised citation of the last amending act (SPEC.md § 24.1). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $lastAmendingActCitation = null;

    /**
     * Teil / Kapitel / Abschnitt tree: {label, title, children[], norms[]} (SPEC.md § 24.1).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $structure = [];

    /**
     * Topics from taxonomy.yml, assigned once on import and correctable by humans.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $topics = [];

    #[ORM\Column(length: 64)]
    private string $sourceName;

    #[ORM\Column(type: Types::TEXT)]
    private string $sourceUrl;

    /** Document id at the source (doknr for gesetze-im-internet). */
    #[ORM\Column(length: 64, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $sourceDocumentId = null;

    /** Commit in the laws repository this row was built from. */
    #[ORM\Column(length: 40, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $lastSyncedCommit = null;

    /**
     * Consecutive successful source runs in which the law was absent; it counts as repealed only
     * after sources.repeal_confirmations runs and a 404 (SPEC.md § 24.3).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $missingRuns = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Norm> */
    #[ORM\OneToMany(targetEntity: Norm::class, mappedBy: 'law')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $norms;

    public function __construct(Jurisdiction $jurisdiction, string $slug, string $title, string $sourceName, string $sourceUrl)
    {
        $this->jurisdiction = $jurisdiction;
        $this->slug = $slug;
        $this->title = $title;
        $this->sourceName = $sourceName;
        $this->sourceUrl = $sourceUrl;
        $this->norms = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function jurisdiction(): Jurisdiction
    {
        return $this->jurisdiction;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** Norm-reference prefix used everywhere: "bund/aufenthg_2004" (SPEC.md § 24.1). */
    public function reference(): string
    {
        return $this->jurisdiction->code().'/'.$this->slug;
    }

    public function type(): LawType
    {
        return $this->type;
    }

    public function setType(LawType $type): void
    {
        $this->type = $type;
    }

    public function status(): LawStatus
    {
        return $this->status;
    }

    public function setStatus(LawStatus $status): void
    {
        $this->status = $status;
    }

    public function abbreviation(): ?string
    {
        return $this->abbreviation;
    }

    public function setAbbreviation(?string $abbreviation): void
    {
        $this->abbreviation = $abbreviation;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function shortTitle(): ?string
    {
        return $this->shortTitle;
    }

    public function setShortTitle(?string $shortTitle): void
    {
        $this->shortTitle = $shortTitle;
    }

    public function dateOfIssue(): ?\DateTimeImmutable
    {
        return $this->dateOfIssue;
    }

    public function setDateOfIssue(?\DateTimeImmutable $dateOfIssue): void
    {
        $this->dateOfIssue = $dateOfIssue;
    }

    public function promulgation(): ?string
    {
        return $this->promulgation;
    }

    public function setPromulgation(?string $promulgation): void
    {
        $this->promulgation = $promulgation;
    }

    public function statusNote(): ?string
    {
        return $this->statusNote;
    }

    public function setStatusNote(?string $statusNote): void
    {
        $this->statusNote = $statusNote;
    }

    public function lastAmendingActCitation(): ?string
    {
        return $this->lastAmendingActCitation;
    }

    public function setLastAmendingActCitation(?string $citation): void
    {
        $this->lastAmendingActCitation = $citation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function structure(): array
    {
        return $this->structure;
    }

    /**
     * @param list<array<string, mixed>> $structure
     */
    public function setStructure(array $structure): void
    {
        $this->structure = $structure;
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
        $this->topics = $topics;
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }

    public function sourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(string $sourceUrl): void
    {
        $this->sourceUrl = $sourceUrl;
    }

    public function sourceDocumentId(): ?string
    {
        return $this->sourceDocumentId;
    }

    public function setSourceDocumentId(?string $documentId): void
    {
        $this->sourceDocumentId = $documentId;
    }

    public function lastSyncedCommit(): ?string
    {
        return $this->lastSyncedCommit;
    }

    public function setLastSyncedCommit(?string $commit): void
    {
        $this->lastSyncedCommit = $commit;
    }

    public function missingRuns(): int
    {
        return $this->missingRuns;
    }

    public function recordMissingRun(): void
    {
        ++$this->missingRuns;
    }

    public function resetMissingRuns(): void
    {
        $this->missingRuns = 0;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, Norm>
     */
    public function norms(): Collection
    {
        return $this->norms;
    }
}
