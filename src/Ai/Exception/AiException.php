<?php

declare(strict_types=1);

namespace App\Ai\Exception;

use App\Ai\Enum\AiTask;

/**
 * The AI layer could not deliver an answer (SPEC.md § 8.2).
 *
 * Never a reason to publish something anyway: the pipeline stage stays where it is and is retried,
 * because a missing card is recoverable and a wrong one is not.
 */
final class AiException extends \RuntimeException
{
    public static function noRoute(AiTask $task): self
    {
        return new self(\sprintf(
            'No usable model is configured for task "%s"; check patchnotes.ai.models and the provider credentials.',
            $task->value,
        ));
    }

    public static function pausedByBudget(AiTask $task): self
    {
        return new self(\sprintf(
            'Task "%s" is paused because the monthly AI budget is exhausted.',
            $task->value,
        ));
    }

    public static function exhausted(AiTask $task, string $lastError): self
    {
        return new self(\sprintf(
            'Every model in the chain for task "%s" failed. Last error: %s',
            $task->value,
            $lastError,
        ));
    }
}
