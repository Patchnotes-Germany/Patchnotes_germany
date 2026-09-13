<?php

declare(strict_types=1);

namespace App\Source\Http;

/**
 * A source could not be read. Never thrown for "nothing changed" — that is a normal answer.
 */
final class SourceUnavailable extends \RuntimeException
{
    public static function httpError(string $url, int $statusCode): self
    {
        return new self(\sprintf('Source answered HTTP %d for %s', $statusCode, $url));
    }

    public static function transportError(string $url, \Throwable $previous): self
    {
        return new self(\sprintf('Source %s is unreachable: %s', $url, $previous->getMessage()), 0, $previous);
    }

    /**
     * We never work around a robots.txt exclusion (SPEC.md § 0.3.4, § 24.17).
     */
    public static function disallowedByRobots(string $url): self
    {
        return new self(\sprintf('robots.txt of %s disallows automated access to this path', $url));
    }
}
