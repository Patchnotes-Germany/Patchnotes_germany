<?php

declare(strict_types=1);

namespace App\Content\Check;

/**
 * How serious a quality finding is (SPEC.md § 7.4).
 */
enum IssueSeverity: string
{
    /** Worth knowing, changes nothing. */
    case Info = 'info';
    /** Published, but visible to the editors. */
    case Warning = 'warning';
    /** Nothing is published: the subject goes to needs_review. */
    case Error = 'error';
}
