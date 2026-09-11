<?php

declare(strict_types=1);

namespace App\Content\Enum;

/**
 * Cards are generalised over their subject (SPEC.md § 24.7): a change, a bill, a weekly digest or a
 * plenary summary all use the same card structure, sections and translation pipeline.
 */
enum SubjectType: string
{
    case Change = 'change';
    case Bill = 'bill';
    case Digest = 'digest';
    case Plenary = 'plenary';
}
