<?php

declare(strict_types=1);

namespace App\Source\Entity;

use App\Source\Enum\SourceRunStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One synchronisation run of one source (SPEC.md § 6.1): when, how long, how many documents were
 * seen and changed, and what went wrong. Primary data — not restored by rebuild-from-git (§ 24.11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'source_run')]
#[ORM\Index(name: 'idx_source_run_started', columns: ['source_key', 'started_at'])]
class SourceRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(name: 'source_key', referencedColumnName: 'source_key', nullable: false)]
    private Source $source;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 16, enumType: SourceRunStatus::class)]
    private SourceRunStatus $status = SourceRunStatus::Running;

    #[ORM\Column(options: ['default' => 0])]
    private int $documentsSeen = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $documentsChanged = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $errorCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $log = null;

    /** Ties every log line and pipeline message of this run together (SPEC.md § 19). */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $correlationId;

    public function __construct(Source $source, string $correlationId)
    {
        $this->source = $source;
        $this->correlationId = $correlationId;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function status(): SourceRunStatus
    {
        return $this->status;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function documentsSeen(): int
    {
        return $this->documentsSeen;
    }

    public function documentsChanged(): int
    {
        return $this->documentsChanged;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function log(): ?string
    {
        return $this->log;
    }

    public function recordDocument(bool $changed): void
    {
        ++$this->documentsSeen;
        if ($changed) {
            ++$this->documentsChanged;
        }
    }

    public function recordError(string $message): void
    {
        ++$this->errorCount;
        $this->log = trim(($this->log ?? '')."\n".$message);
    }

    public function finish(SourceRunStatus $status): void
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();
    }
}
