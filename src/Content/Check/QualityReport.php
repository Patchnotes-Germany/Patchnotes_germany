<?php

declare(strict_types=1);

namespace App\Content\Check;

/**
 * The result of the deterministic checks of SPEC.md § 7.4.
 *
 * One error is enough to stop publication: the pipeline moves the subject to `needs_review` rather
 * than putting a wrong figure in front of someone who is deciding about their residence permit.
 * Warnings travel with the card into the pull request, where a person sees them.
 */
final class QualityReport
{
    /** @var list<QualityIssue> */
    private array $issues = [];

    /**
     * @param list<QualityIssue> $issues
     */
    public function __construct(array $issues = [])
    {
        foreach ($issues as $issue) {
            $this->add($issue);
        }
    }

    public function add(QualityIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    public function merge(self $other): void
    {
        foreach ($other->issues() as $issue) {
            $this->add($issue);
        }
    }

    /**
     * @return list<QualityIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<QualityIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (QualityIssue $i): bool => $i->isError()));
    }

    public function passed(): bool
    {
        return [] === $this->errors();
    }

    public function isEmpty(): bool
    {
        return [] === $this->issues;
    }

    public function count(): int
    {
        return \count($this->issues);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (QualityIssue $i): array => $i->toArray(), $this->issues);
    }

    /**
     * A short Markdown list for the pull request comment.
     */
    public function toMarkdown(): string
    {
        if ($this->isEmpty()) {
            return 'All automatic checks passed.';
        }

        $lines = [];
        foreach ($this->issues as $issue) {
            $lines[] = \sprintf(
                '- %s **%s**: %s',
                match ($issue->severity) {
                    IssueSeverity::Error => '❌',
                    IssueSeverity::Warning => '⚠️',
                    IssueSeverity::Info => 'ℹ️',
                },
                $issue->code,
                $issue->describe(),
            );
        }

        return implode("\n", $lines);
    }
}
