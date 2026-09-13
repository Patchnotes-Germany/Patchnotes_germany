<?php

declare(strict_types=1);

namespace App\Git\Exception;

/**
 * Marker for everything the git layer can fail with.
 */
interface GitException extends \Throwable
{
}
