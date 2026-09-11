<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use App\Ai\Enum\AiTask;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One AI request for accounting (SPEC.md § 8.2).
 *
 * Feeds the monthly budget, the admin dashboard and the public transparency page. Costs are
 * computed from ai.pricing in the provider currency and converted with ai.fx; no prompt content and
 * no personal data are stored here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_usage')]
#[ORM\Index(name: 'idx_ai_usage_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_ai_usage_task', columns: ['task', 'created_at'])]
class AiUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: AiTask::class)]
    private AiTask $task;

    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $provider;

    #[ORM\Column(length: 128)]
    private string $model;

    #[ORM\Column(options: ['default' => 0])]
    private int $inputTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $outputTokens = 0;

    /** Prompt caching (Anthropic, OpenAI) makes long system prompts and glossaries much cheaper. */
    #[ORM\Column(options: ['default' => 0])]
    private int $cachedTokens = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $costEur = '0.000000';

    #[ORM\Column(options: ['default' => 0])]
    private int $durationMs = 0;

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(length: 16, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $subjectType = null;

    #[ORM\Column(length: 96, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $subjectId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(AiTask $task, string $provider, string $model, bool $success)
    {
        $this->task = $task;
        $this->provider = $provider;
        $this->model = $model;
        $this->success = $success;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function task(): AiTask
    {
        return $this->task;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    public function cachedTokens(): int
    {
        return $this->cachedTokens;
    }

    public function setTokens(int $input, int $output, int $cached = 0): void
    {
        $this->inputTokens = $input;
        $this->outputTokens = $output;
        $this->cachedTokens = $cached;
    }

    public function costEur(): string
    {
        return $this->costEur;
    }

    public function setCostEur(string $cost): void
    {
        $this->costEur = $cost;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): void
    {
        $this->durationMs = $durationMs;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSubject(?string $type, ?string $id): void
    {
        $this->subjectType = $type;
        $this->subjectId = $id;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
