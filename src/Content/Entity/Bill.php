<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Content\Enum\LegislativeStage;
use App\Content\Enum\ReviewState;
use App\Pipeline\Enum\PipelineState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A bill tracked through the DIP API of the Bundestag (SPEC.md § 6.2 D).
 *
 * Bills are full subjects of the pipeline (SPEC.md § 24.7): they carry their own audience, topics,
 * impact and review state, and they produce cards exactly like changes do. Once a bill becomes law,
 * it is linked to the amending act and the change, which gives the timeline on the website.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bill')]
#[ORM\UniqueConstraint(name: 'uniq_bill_dip_id', columns: ['dip_id'])]
#[ORM\Index(name: 'idx_bill_stage', columns: ['stage'])]
#[ORM\Index(name: 'idx_bill_updated', columns: ['source_updated_at'])]
class Bill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Vorgang id in DIP; the content repository uses "dip-{id}" as the directory name. */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $dipId;

    #[ORM\Column(length: 1024)]
    private string $title;

    /** Wer hat den Entwurf eingebracht: Bundesregierung, Bundesrat, Fraktionen … */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $initiator = null;

    #[ORM\Column(length: 16, enumType: LegislativeStage::class)]
    private LegislativeStage $stage = LegislativeStage::Discussed;

    /** Raw stage description from DIP, e.g. "2. Beratung/Schlussabstimmung". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stageLabel = null;

    /**
     * Full stage history; mirrored to bills/<id>/facts.yml, therefore restorable (SPEC.md § 24.11).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $stageHistory = [];

    /**
     * Drucksachen and Plenarprotokolle references.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $documents = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $facts = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $audience = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $topics = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $lands = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $impact = 0;

    #[ORM\Column(length: 24, enumType: ReviewState::class)]
    private ReviewState $reviewState = ReviewState::Draft;

    #[ORM\Column(length: 40, enumType: PipelineState::class)]
    private PipelineState $pipelineState = PipelineState::Detected;

    #[ORM\ManyToOne(targetEntity: AmendingAct::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AmendingAct $amendingAct = null;

    /** Last "aktualisiert" timestamp reported by DIP; drives incremental fetching. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sourceUpdatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(string $dipId, string $title)
    {
        $this->dipId = $dipId;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function dipId(): string
    {
        return $this->dipId;
    }

    /** Subject id used for cards and the content repository directory. */
    public function subjectId(): string
    {
        return 'dip-'.$this->dipId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function initiator(): ?string
    {
        return $this->initiator;
    }

    public function setInitiator(?string $initiator): void
    {
        $this->initiator = $initiator;
    }

    public function stage(): LegislativeStage
    {
        return $this->stage;
    }

    public function stageLabel(): ?string
    {
        return $this->stageLabel;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function recordStage(LegislativeStage $stage, ?string $label, \DateTimeImmutable $at, array $details = []): void
    {
        $this->stage = $stage;
        $this->stageLabel = $label;
        $this->stageHistory[] = [
            'stage' => $stage->value,
            'label' => $label,
            'at' => $at->format(\DATE_ATOM),
        ] + $details;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stageHistory(): array
    {
        return $this->stageHistory;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    public function setStageHistory(array $history): void
    {
        $this->stageHistory = $history;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function setDocuments(array $documents): void
    {
        $this->documents = $documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * @param array<string, mixed> $facts
     */
    public function setFacts(array $facts): void
    {
        $this->facts = $facts;
    }

    /**
     * @return array<string, mixed>
     */
    public function audience(): array
    {
        return $this->audience;
    }

    /**
     * @param array<string, mixed> $audience
     */
    public function setAudience(array $audience): void
    {
        $this->audience = $audience;
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

    /**
     * @return list<string>
     */
    public function lands(): array
    {
        return $this->lands;
    }

    /**
     * @param list<string> $lands
     */
    public function setLands(array $lands): void
    {
        $this->lands = $lands;
    }

    public function impact(): int
    {
        return $this->impact;
    }

    public function setImpact(int $impact): void
    {
        $this->impact = $impact;
    }

    public function reviewState(): ReviewState
    {
        return $this->reviewState;
    }

    public function setReviewState(ReviewState $state): void
    {
        $this->reviewState = $state;
    }

    public function pipelineState(): PipelineState
    {
        return $this->pipelineState;
    }

    public function setPipelineState(PipelineState $state): void
    {
        $this->pipelineState = $state;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function amendingAct(): ?AmendingAct
    {
        return $this->amendingAct;
    }

    public function setAmendingAct(?AmendingAct $act): void
    {
        $this->amendingAct = $act;
    }

    public function sourceUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    public function setSourceUpdatedAt(?\DateTimeImmutable $at): void
    {
        $this->sourceUpdatedAt = $at;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function markPublished(\DateTimeImmutable $at): void
    {
        $this->publishedAt = $at;
        $this->updatedAt = $at;
    }
}
