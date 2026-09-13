<?php

declare(strict_types=1);

namespace App\Ai\Value;

/**
 * Reference to a batch that a provider is working on (SPEC.md § 24.14).
 *
 * Batches outlive the process that submitted them, so the handle carries everything needed to poll
 * and collect results later — including the provider-side ids of any uploaded input file, so a
 * finished batch can clean up after itself.
 */
final readonly class BatchHandle
{
    /**
     * @param array<string, string> $metadata provider-specific ids, e.g. the input file
     */
    public function __construct(
        public string $provider,
        public string $id,
        public \DateTimeImmutable $submittedAt,
        public int $requestCount = 0,
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'submitted_at' => $this->submittedAt->format(\DATE_ATOM),
            'request_count' => $this->requestCount,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $metadata */
        $metadata = \is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return new self(
            (string) ($data['provider'] ?? ''),
            (string) ($data['id'] ?? ''),
            new \DateTimeImmutable((string) ($data['submitted_at'] ?? 'now')),
            (int) ($data['request_count'] ?? 0),
            $metadata,
        );
    }
}
