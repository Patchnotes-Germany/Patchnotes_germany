<?php

declare(strict_types=1);

namespace App\Source\Enum;

/**
 * Health of a source adapter, shown in the admin and on the public /status page (SPEC.md § 6.1).
 *
 * "blocked" means the source forbids automated access (terms of use, robots.txt, CAPTCHA); we never
 * circumvent such a protection (SPEC.md § 0.3.4, § 24.17).
 */
enum SourceHealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Down = 'down';
    case Blocked = 'blocked';
    case Disabled = 'disabled';
}
