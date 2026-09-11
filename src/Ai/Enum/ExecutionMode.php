<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * How requests of a provider are executed (SPEC.md § 8.3).
 */
enum ExecutionMode: string
{
    /** The server calls the provider itself. */
    case Direct = 'direct';
    /** Jobs wait in the database until the owner's computer picks them up over HTTPS. */
    case RemoteWorker = 'remote_worker';
}
