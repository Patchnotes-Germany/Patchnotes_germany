<?php

declare(strict_types=1);

namespace App\Git\Enum;

/**
 * Why a pull request exists (SPEC.md § 4.5, § 5.6).
 */
enum ChangeRequestKind: string
{
    /** Official consolidated text changed at the source; auto-merged after the safeguards pass. */
    case Official = 'official';
    /** AI applied the amending commands of a promulgated act before the source caught up; never auto-merged. */
    case Preview = 'preview';
    /** A bill that has not been adopted; opened as a draft pull request. */
    case Draft = 'draft';
    /** Cards, facts and translations in the content repository. */
    case Content = 'content';

    public function isAutoMergeable(): bool
    {
        return self::Official === $this;
    }
}
