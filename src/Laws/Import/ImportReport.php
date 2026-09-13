<?php

declare(strict_types=1);

namespace App\Laws\Import;

/**
 * What an import of the `laws` repository into the database did (SPEC.md § 24.11: these tables are
 * derived data and can always be rebuilt from git).
 */
final class ImportReport
{
    public int $lawsCreated = 0;
    public int $lawsUpdated = 0;
    public int $lawsUnchanged = 0;
    public int $normsCreated = 0;
    public int $normVersionsCreated = 0;
    public int $normsRepealed = 0;

    /** @var list<string> */
    public array $errors = [];

    public function lawsSeen(): int
    {
        return $this->lawsCreated + $this->lawsUpdated + $this->lawsUnchanged;
    }

    /**
     * @return array<string, int|list<string>>
     */
    public function toArray(): array
    {
        return [
            'laws_created' => $this->lawsCreated,
            'laws_updated' => $this->lawsUpdated,
            'laws_unchanged' => $this->lawsUnchanged,
            'norms_created' => $this->normsCreated,
            'norm_versions_created' => $this->normVersionsCreated,
            'norms_repealed' => $this->normsRepealed,
            'errors' => $this->errors,
        ];
    }
}
