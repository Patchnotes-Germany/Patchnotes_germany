<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Content\Enum\SubjectType;
use App\Content\Enum\TranslationKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One card in one language (SPEC.md § 5.4, generalised over subjects by § 24.7).
 *
 * Sections are stored by their stable keys — summary, what_changes, who, when, what_to_do,
 * details — with localized headings applied at render time. Placeholders such as
 * {{ amount:blue_card_salary_threshold.new }} are resolved by our own parser; card content is never
 * rendered as a Twig template (SSTI, SPEC.md § 24.13).
 */
#[ORM\Entity]
#[ORM\Table(name: 'card')]
#[ORM\UniqueConstraint(name: 'uniq_card_subject_lang', columns: ['subject_type', 'subject_id', 'lang'])]
#[ORM\Index(name: 'idx_card_stale', columns: ['stale'])]
class Card
{
    public const SECTION_KEYS = ['summary', 'what_changes', 'who', 'when', 'what_to_do', 'details'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: SubjectType::class)]
    private SubjectType $subjectType;

    /** Change id, "dip-{id}", or an ISO week for digests and plenary summaries. */
    #[ORM\Column(length: 96, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $subjectId;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    /**
     * Section key => text with placeholders.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $sections = [];

    /** Rendered, sanitized HTML with placeholders resolved; a cache, safe to drop. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $renderedHtml = null;

    /**
     * sha256 of the master card's section bodies and used placeholders. When it no longer matches
     * the master, the translation is stale and gets re-queued (SPEC.md § 5.4).
     */
    #[ORM\Column(length: 64, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $masterHash = null;

    #[ORM\Column(length: 16, enumType: TranslationKind::class)]
    private TranslationKind $translationKind = TranslationKind::Machine;

    #[ORM\Column(options: ['default' => false])]
    private bool $stale = false;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $translatedBy = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $reviewedBy = null;

    /**
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $promptVersions = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(SubjectType $subjectType, string $subjectId, string $lang)
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->lang = $lang;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function subjectType(): SubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /**
     * @return array<string, string>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    public function section(string $key): ?string
    {
        return $this->sections[$key] ?? null;
    }

    /**
     * @param array<string, string> $sections
     */
    public function setSections(array $sections): void
    {
        $this->sections = $sections;
        $this->renderedHtml = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function renderedHtml(): ?string
    {
        return $this->renderedHtml;
    }

    public function setRenderedHtml(?string $html): void
    {
        $this->renderedHtml = $html;
    }

    public function masterHash(): ?string
    {
        return $this->masterHash;
    }

    public function setMasterHash(?string $hash): void
    {
        $this->masterHash = $hash;
    }

    public function translationKind(): TranslationKind
    {
        return $this->translationKind;
    }

    public function setTranslationKind(TranslationKind $kind): void
    {
        $this->translationKind = $kind;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    public function markStale(bool $stale = true): void
    {
        $this->stale = $stale;
    }

    public function translatedBy(): ?string
    {
        return $this->translatedBy;
    }

    public function setTranslatedBy(?string $model): void
    {
        $this->translatedBy = $model;
    }

    public function reviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?string $reviewer): void
    {
        $this->reviewedBy = $reviewer;
    }

    /**
     * @return array<string, int>
     */
    public function promptVersions(): array
    {
        return $this->promptVersions;
    }

    /**
     * @param array<string, int> $versions
     */
    public function setPromptVersions(array $versions): void
    {
        $this->promptVersions = $versions;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
