<?php

declare(strict_types=1);

namespace App\Git\Value;

/**
 * Provenance trailers of a bot commit (SPEC.md § 3.2):
 *
 *   Source: gesetze-im-internet
 *   Source-Url: https://www.gesetze-im-internet.de/aufenthg_2004/
 *   Amending-Act: BGBl. 2026 I Nr. 123
 *   Amending-Act-Url: https://www.recht.bund.de/eli/bund/BGBl-1/2026/123/
 *   Change-Id: 2026-bund-bgbl-i-123
 *
 * They make every line in the laws repository traceable back to the document it came from, which is
 * what `git blame` on the website relies on.
 */
final readonly class CommitTrailers
{
    private const array ORDER = ['Source', 'Source-Url', 'Amending-Act', 'Amending-Act-Url', 'Change-Id'];

    /**
     * @param array<string, string> $trailers
     */
    private function __construct(public array $trailers)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function create(
        ?string $source = null,
        ?string $sourceUrl = null,
        ?string $amendingAct = null,
        ?string $amendingActUrl = null,
        ?string $changeId = null,
    ): self {
        $trailers = array_filter([
            'Source' => $source,
            'Source-Url' => $sourceUrl,
            'Amending-Act' => $amendingAct,
            'Amending-Act-Url' => $amendingActUrl,
            'Change-Id' => $changeId,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);

        return new self($trailers);
    }

    public function with(string $name, string $value): self
    {
        return new self([...$this->trailers, $name => $value]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->trailers;
    }

    /**
     * Appends the trailers to a commit message, separated by a blank line as git expects.
     */
    public function applyTo(string $message): string
    {
        if ($this->isEmpty()) {
            return rtrim($message)."\n";
        }

        $known = [];
        foreach (self::ORDER as $name) {
            if (isset($this->trailers[$name])) {
                $known[$name] = $this->trailers[$name];
            }
        }
        $lines = [];
        foreach ([...$known, ...$this->trailers] as $name => $value) {
            $line = \sprintf('%s: %s', $name, $value);
            if (!\in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return rtrim($message)."\n\n".implode("\n", $lines)."\n";
    }

    /**
     * Parses the trailers back out of a commit message, so a synchronisation can tell which change
     * a commit belongs to without a database lookup.
     */
    public static function parse(string $message): self
    {
        $trailers = [];
        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            if (1 === preg_match('/^([A-Z][A-Za-z-]*): (.+)$/', trim($line), $matches)) {
                $trailers[$matches[1]] = $matches[2];
            }
        }

        return new self($trailers);
    }

    public function get(string $name): ?string
    {
        return $this->trailers[$name] ?? null;
    }
}
