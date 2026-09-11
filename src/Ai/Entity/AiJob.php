<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use App\Ai\Enum\AiJobStatus;
use App\Ai\Enum\AiTask;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A unit of AI work waiting for a remote worker (SPEC.md § 8.3).
 *
 * Jobs for the "local" provider are rows in MySQL rather than Messenger messages, because the
 * worker runs on the owner's computer and pulls them over HTTPS: it claims rows with
 * SELECT … FOR UPDATE SKIP LOCKED, reports back to /complete, and the pipeline continues from
 * there — no pipeline stage ever blocks waiting for a model.
 *
 * If the lease expires the job returns to the queue; if no worker shows up within
 * ai.local_worker_fallback_after_minutes it falls back to a cloud provider.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_job')]
#[ORM\Index(name: 'idx_ai_job_claim', columns: ['status', 'provider', 'priority', 'created_at'])]
#[ORM\Index(name: 'idx_ai_job_lease', columns: ['status', 'lease_until'])]
#[ORM\Index(name: 'idx_ai_job_input', columns: ['task', 'input_hash'])]
class AiJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: AiTask::class)]
    private AiTask $task;

    #[ORM\Column(length: 16, enumType: AiJobStatus::class)]
    private AiJobStatus $status = AiJobStatus::Pending;

    /** Provider alias from patchnotes.ai.providers, e.g. "local". */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $provider;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model = null;

    /** sha256 over task, prompt version, schema version, model, temperature and input (§ 8.2 cache). */
    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $inputHash;

    /**
     * The request as handed to the worker: system prompt, user prompt, schema, parameters.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $result = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leaseUntil = null;

    #[ORM\ManyToOne(targetEntity: WorkerToken::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkerToken $leasedBy = null;

    /** What the result belongs to, so the pipeline can continue after /complete. */
    #[ORM\Column(length: 16, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $subjectType = null;

    #[ORM\Column(length: 96, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $subjectId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(AiTask $task, string $provider, string $inputHash, array $payload)
    {
        $this->task = $task;
        $this->provider = $provider;
        $this->inputHash = $inputHash;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function task(): AiTask
    {
        return $this->task;
    }

    public function status(): AiJobStatus
    {
        return $this->status;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    public function inputHash(): string
    {
        return $this->inputHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function result(): ?array
    {
        return $this->result;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function leaseUntil(): ?\DateTimeImmutable
    {
        return $this->leaseUntil;
    }

    public function leasedBy(): ?WorkerToken
    {
        return $this->leasedBy;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    public function setSubject(?string $type, ?string $id): void
    {
        $this->subjectType = $type;
        $this->subjectId = $id;
    }

    public function lease(WorkerToken $worker, \DateTimeImmutable $until): void
    {
        $this->status = AiJobStatus::Leased;
        $this->leasedBy = $worker;
        $this->leaseUntil = $until;
        ++$this->attempts;
        $this->touch();
    }

    public function extendLease(\DateTimeImmutable $until): void
    {
        $this->leaseUntil = $until;
        $this->touch();
    }

    public function releaseLease(): void
    {
        $this->status = AiJobStatus::Pending;
        $this->leasedBy = null;
        $this->leaseUntil = null;
        $this->touch();
    }

    /**
     * @param array<string, mixed> $result
     */
    public function complete(array $result, ?string $model = null): void
    {
        $this->status = AiJobStatus::Succeeded;
        $this->result = $result;
        $this->model = $model ?? $this->model;
        $this->error = null;
        $this->leaseUntil = null;
        $this->finishedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function fail(string $error): void
    {
        $this->status = AiJobStatus::Failed;
        $this->error = $error;
        $this->leaseUntil = null;
        $this->finishedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markFellBack(): void
    {
        $this->status = AiJobStatus::FellBack;
        $this->leaseUntil = null;
        $this->finishedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function cancel(): void
    {
        $this->status = AiJobStatus::Cancelled;
        $this->leaseUntil = null;
        $this->touch();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
