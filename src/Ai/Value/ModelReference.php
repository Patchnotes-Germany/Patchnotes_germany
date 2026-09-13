<?php

declare(strict_types=1);

namespace App\Ai\Value;

/**
 * A model written as "provider:model-id" (SPEC.md § 8.2).
 *
 * Model ids are never hardcoded: they come from configuration and environment, which is what makes
 * "run this task on the machine under my desk instead of OpenAI" a one-line change.
 */
final readonly class ModelReference implements \Stringable
{
    public function __construct(
        /** Provider alias from patchnotes.ai.providers, e.g. "openai" or "local". */
        public string $provider,
        public string $model,
        /** The alias it was resolved from ("large", "local_default"), for logs and dashboards. */
        public ?string $alias = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->provider.':'.$this->model;
    }

    /**
     * @throws \InvalidArgumentException when the value is not "provider:model-id"
     */
    public static function parse(string $value, ?string $alias = null): self
    {
        $value = trim($value);
        $separator = strpos($value, ':');

        if (false === $separator || 0 === $separator || $separator === \strlen($value) - 1) {
            throw new \InvalidArgumentException(\sprintf('Model "%s" must be written as "provider:model-id", for example "openai:gpt-5" or "local:qwen/qwen3.8-27b".', $value));
        }

        return new self(
            substr($value, 0, $separator),
            substr($value, $separator + 1),
            $alias,
        );
    }

    public function withAlias(?string $alias): self
    {
        return new self($this->provider, $this->model, $alias);
    }
}
