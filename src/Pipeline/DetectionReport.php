<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * What one scan of the `laws` repository found (SPEC.md § 7.1).
 */
final class DetectionReport
{
    public int $commitsScanned = 0;
    public int $baselineCommitsSkipped = 0;
    public int $commitsWithoutChangeId = 0;
    public int $changesCreated = 0;
    public int $changesUpdated = 0;
    public int $normsLinked = 0;

    /** @var list<string> */
    public array $changeIds = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commits_scanned' => $this->commitsScanned,
            'baseline_skipped' => $this->baselineCommitsSkipped,
            'without_change_id' => $this->commitsWithoutChangeId,
            'changes_created' => $this->changesCreated,
            'changes_updated' => $this->changesUpdated,
            'norms_linked' => $this->normsLinked,
        ];
    }
}
