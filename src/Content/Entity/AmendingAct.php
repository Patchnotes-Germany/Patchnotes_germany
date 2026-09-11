<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Source\Entity\SourceDocument;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A promulgated act as published in a gazette — Bundesgesetzblatt or a state GVBl (SPEC.md § 6.2 C).
 *
 * Discovered before the consolidated texts catch up, which is what makes early notifications
 * possible. The AI extraction (promulgation_extract) is cached here and is regenerable (§ 24.11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'amending_act')]
#[ORM\UniqueConstraint(name: 'uniq_amending_act_citation', columns: ['citation'])]
#[ORM\Index(name: 'idx_amending_act_date', columns: ['jurisdiction_code', 'date'])]
class AmendingAct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Normalised citation, e.g. "BGBl. 2026 I Nr. 123" (SPEC.md § 24.1). */
    #[ORM\Column(length: 128, options: ['collation' => 'utf8mb4_bin'])]
    private string $citation;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $jurisdictionCode;

    /** Gazette key, e.g. "bgbl-i", "bgbl-ii", "gvbl-be". */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $gazette;

    #[ORM\Column]
    private int $year;

    #[ORM\Column(length: 32)]
    private string $number;

    /** Date of promulgation: a legal date, stored without time zone (SPEC.md § 17). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 1024)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    #[ORM\ManyToOne(targetEntity: SourceDocument::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SourceDocument $rawDocument = null;

    /**
     * Result of the promulgation_extract task: articles, target laws, entry-into-force rules.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extractedJson = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $citation,
        string $jurisdictionCode,
        string $gazette,
        int $year,
        string $number,
        \DateTimeImmutable $date,
        string $title,
        string $url,
    ) {
        $this->citation = $citation;
        $this->jurisdictionCode = $jurisdictionCode;
        $this->gazette = $gazette;
        $this->year = $year;
        $this->number = $number;
        $this->date = $date;
        $this->title = $title;
        $this->url = $url;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function citation(): string
    {
        return $this->citation;
    }

    public function jurisdictionCode(): string
    {
        return $this->jurisdictionCode;
    }

    public function gazette(): string
    {
        return $this->gazette;
    }

    public function year(): int
    {
        return $this->year;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function rawDocument(): ?SourceDocument
    {
        return $this->rawDocument;
    }

    public function setRawDocument(?SourceDocument $document): void
    {
        $this->rawDocument = $document;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractedJson(): ?array
    {
        return $this->extractedJson;
    }

    /**
     * @param array<string, mixed>|null $extracted
     */
    public function setExtractedJson(?array $extracted): void
    {
        $this->extractedJson = $extracted;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Change id derived from the gazette reference: "2026-bund-bgbl-i-123" (SPEC.md § 24.1). */
    public function changeId(): string
    {
        return \sprintf('%d-%s-%s-%s', $this->year, $this->jurisdictionCode, $this->gazette, strtolower($this->number));
    }
}
