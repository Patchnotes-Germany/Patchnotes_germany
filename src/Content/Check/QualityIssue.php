<?php

declare(strict_types=1);

namespace App\Content\Check;

/**
 * One finding of the deterministic quality checks (SPEC.md § 7.4).
 *
 * Findings are written into the pull request as a comment, so they are phrased for the person who
 * has to decide what to do about them: what is wrong, and where.
 */
final readonly class QualityIssue
{
    public function __construct(
        public IssueSeverity $severity,
        /** Machine-readable kind, e.g. "fact.quote_not_found", so the admin can filter. */
        public string $code,
        public string $message,
        /** Where it is: "amounts[0].source_quote", "sections.summary", "ru". */
        public ?string $path = null,
    ) {
    }

    public static function error(string $code, string $message, ?string $path = null): self
    {
        return new self(IssueSeverity::Error, $code, $message, $path);
    }

    public static function warning(string $code, string $message, ?string $path = null): self
    {
        return new self(IssueSeverity::Warning, $code, $message, $path);
    }

    public static function info(string $code, string $message, ?string $path = null): self
    {
        return new self(IssueSeverity::Info, $code, $message, $path);
    }

    public function isError(): bool
    {
        return IssueSeverity::Error === $this->severity;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
        ];
    }

    public function describe(): string
    {
        return null === $this->path
            ? $this->message
            : \sprintf('%s — %s', $this->path, $this->message);
    }
}
