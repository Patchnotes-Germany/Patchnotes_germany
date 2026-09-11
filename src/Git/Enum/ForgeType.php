<?php

declare(strict_types=1);

namespace App\Git\Enum;

/**
 * Supported forges (SPEC.md § 3.1). "none" keeps everything local: branches and merges without any
 * API calls, used in development and in tests.
 */
enum ForgeType: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Gitea = 'gitea';
    case None = 'none';

    /**
     * GitLab speaks of merge requests; the internal model is one ChangeRequest either way.
     */
    public function changeRequestTerm(): string
    {
        return self::GitLab === $this ? 'merge request' : 'pull request';
    }
}
