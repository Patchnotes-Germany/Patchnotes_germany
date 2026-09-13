<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/**
 * A model call failed. Every failure is recoverable by design: the router moves on to the next
 * model in the chain and, if none answers, the task stays queued instead of publishing something
 * wrong (SPEC.md § 8.2).
 */
class LlmException extends \RuntimeException
{
    public static function http(string $provider, int $statusCode, string $body): self
    {
        return new self(\sprintf(
            'Provider "%s" answered HTTP %d: %s',
            $provider,
            $statusCode,
            mb_substr(trim($body), 0, 500),
        ));
    }

    public static function transport(string $provider, \Throwable $previous): self
    {
        return new self(\sprintf('Provider "%s" is unreachable: %s', $provider, $previous->getMessage()), 0, $previous);
    }

    public static function misconfigured(string $provider, string $detail): self
    {
        return new self(\sprintf('Provider "%s" is not configured properly: %s', $provider, $detail));
    }

    public static function unsupported(string $provider, string $feature): self
    {
        return new self(\sprintf('Provider "%s" does not support %s.', $provider, $feature));
    }
}
