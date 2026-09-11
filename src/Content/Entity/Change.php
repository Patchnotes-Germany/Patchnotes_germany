<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Content\Enum\ChangeKind;
use App\Content\Enum\EffectiveState;
use App\Content\Enum\LegislativeStage;
use App\Content\Enum\ReviewState;
use App\Pipeline\Enum\PipelineState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One change of the law: one amending act, or one independent edit of a law without an identifiable
 * act (SPEC.md § 7.2). The table is called law_change because CHANGE is reserved in MySQL (§ 17).
 *
 * The id is the natural change id of § 24.1 ("2026-bund-bgbl-i-123"), which is also the directory
 * name in the content repository — that is what makes rebuild-from-git possible without mapping
 * tables. Facts, audience and dates are mirrored from facts.yml, which stays the source of truth.
 */
#[ORM\Entity]
#[ORM\Table(name: 'law_change')]
#[ORM\Index(name: 'idx_law_change_published', columns: ['published_at'])]
#[ORM\Index(name: 'idx_law_change_review', columns: ['review_state'])]
#[ORM\Index(name: 'idx_law_change_pipeline', columns: ['pipeline_state'])]
#[ORM\Index(name: 'idx_law_change_jurisdiction', columns: ['jurisdiction_code', 'published_at'])]
class Change
{
    #[ORM\Id]
    #[ORM\Column(length: 96, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $id;

    #[ORM\Column(length: 16, enumType: ChangeKind::class)]
    private ChangeKind $kind;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $jurisdictionCode;

    /**
     * Empty means the whole country; otherwise the federal states concerned. If the jurisdiction is
     * not "bund", this is exactly [jurisdiction] (SPEC.md § 24.8).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $lands = [];

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $titleDe = null;

    #[ORM\Column(length: 16, enumType: LegislativeStage::class)]
    private LegislativeStage $stage = LegislativeStage::Promulgated;

    /** Derived from dates.effective by the daily job; never stored in git (SPEC.md § 24.6). */
    #[ORM\Column(length: 24, enumType: EffectiveState::class)]
    private EffectiveState $effectiveState = EffectiveState::Unknown;

    /** Earliest entry-into-force date, kept as a column so calendars and reminders can query it. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstEffectiveDate = null;

    /**
     * The full facts.yml content (amounts, dates, deadlines, sources, corrections …).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $facts = [];

    /**
     * audience: {general: bool, all_of: [], any_of: [], none_of: []} — SPEC.md § 9.2.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $audience = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $topics = [];

    /** 0 technical … 3 strong impact on daily life. */
    #[ORM\Column(options: ['default' => 0])]
    private int $impact = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $impactRationale = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $verifyScore = null;

    #[ORM\Column(length: 24, enumType: ReviewState::class)]
    private ReviewState $reviewState = ReviewState::Draft;

    #[ORM\Column(length: 40, enumType: PipelineState::class)]
    private PipelineState $pipelineState = PipelineState::Detected;

    /** Several acts are reflected in one diff: the card needs a human (SPEC.md § 24.4). */
    #[ORM\Column(options: ['default' => false])]
    private bool $mixedAttribution = false;

    #[ORM\ManyToOne(targetEntity: AmendingAct::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AmendingAct $amendingAct = null;

    #[ORM\ManyToOne(targetEntity: Bill::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Bill $bill = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Start of the settling window: analysis waits for all target laws or 72 hours (§ 24.4). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $settlingStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 32, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $correlationId = null;

    public function __construct(string $id, ChangeKind $kind, string $jurisdictionCode)
    {
        $this->id = $id;
        $this->kind = $kind;
        $this->jurisdictionCode = $jurisdictionCode;
        $this->detectedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->detectedAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): ChangeKind
    {
        return $this->kind;
    }

    public function setKind(ChangeKind $kind): void
    {
        $this->kind = $kind;
    }

    public function jurisdictionCode(): string
    {
        return $this->jurisdictionCode;
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

    public function isNationwide(): bool
    {
        return [] === $this->lands;
    }

    public function titleDe(): ?string
    {
        return $this->titleDe;
    }

    public function setTitleDe(?string $titleDe): void
    {
        $this->titleDe = $titleDe;
    }

    public function stage(): LegislativeStage
    {
        return $this->stage;
    }

    public function setStage(LegislativeStage $stage): void
    {
        $this->stage = $stage;
    }

    public function effectiveState(): EffectiveState
    {
        return $this->effectiveState;
    }

    public function setEffectiveState(EffectiveState $state): void
    {
        $this->effectiveState = $state;
    }

    public function firstEffectiveDate(): ?\DateTimeImmutable
    {
        return $this->firstEffectiveDate;
    }

    public function setFirstEffectiveDate(?\DateTimeImmutable $date): void
    {
        $this->firstEffectiveDate = $date;
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

    public function impact(): int
    {
        return $this->impact;
    }

    public function setImpact(int $impact): void
    {
        $this->impact = $impact;
    }

    public function impactRationale(): ?string
    {
        return $this->impactRationale;
    }

    public function setImpactRationale(?string $rationale): void
    {
        $this->impactRationale = $rationale;
    }

    public function confidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): void
    {
        $this->confidence = $confidence;
    }

    public function verifyScore(): ?float
    {
        return $this->verifyScore;
    }

    public function setVerifyScore(?float $score): void
    {
        $this->verifyScore = $score;
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

    public function hasMixedAttribution(): bool
    {
        return $this->mixedAttribution;
    }

    public function setMixedAttribution(bool $mixed): void
    {
        $this->mixedAttribution = $mixed;
    }

    public function amendingAct(): ?AmendingAct
    {
        return $this->amendingAct;
    }

    public function setAmendingAct(?AmendingAct $act): void
    {
        $this->amendingAct = $act;
    }

    public function bill(): ?Bill
    {
        return $this->bill;
    }

    public function setBill(?Bill $bill): void
    {
        $this->bill = $bill;
    }

    public function detectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function settlingStartedAt(): ?\DateTimeImmutable
    {
        return $this->settlingStartedAt;
    }

    public function startSettling(\DateTimeImmutable $at): void
    {
        $this->settlingStartedAt ??= $at;
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

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(?string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt instanceof \DateTimeImmutable && $this->reviewState->isPublishable();
    }
}
