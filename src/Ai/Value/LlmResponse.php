<?php

declare(strict_types=1);

namespace App\Ai\Value;

/**
 * What a model answered, plus what it cost (SPEC.md § 8.1, § 8.2).
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        /** Model that actually answered, in "provider:model-id" form. */
        public string $model,
        public LlmUsage $usage,
        public int $durationMs = 0,
        /** True when the answer came from the response cache instead of the provider. */
        public bool $fromCache = false,
        public ?string $finishReason = null,
    ) {
    }

    public function withCacheFlag(bool $fromCache): self
    {
        return new self($this->content, $this->model, $this->usage, $this->durationMs, $fromCache, $this->finishReason);
    }

    /**
     * The answer decoded as JSON. Models like to wrap JSON in prose or a code fence, so the object
     * is extracted before decoding — validation against the schema happens one layer up.
     *
     * @return array<string, mixed>|null null when the answer is not usable JSON
     */
    public function json(): ?array
    {
        $candidate = self::extractJson($this->content);
        if (null === $candidate) {
            return null;
        }

        try {
            $decoded = json_decode($candidate, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // A bare list is not an answer object: every task schema describes an object.
        if (!\is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    public static function extractJson(string $content): ?string
    {
        $content = trim($content);

        // ```json … ``` fences are the most common wrapper.
        if (1 === preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $content, $matches)) {
            return $matches[1];
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if (false === $start || false === $end || $end <= $start) {
            return null;
        }

        return substr($content, $start, $end - $start + 1);
    }
}
