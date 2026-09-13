<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Exception\GitException;

final class ForgeException extends \RuntimeException implements GitException
{
    public static function request(string $forge, string $operation, int $statusCode, string $body): self
    {
        return new self(\sprintf(
            '%s API call "%s" failed with HTTP %d: %s',
            $forge,
            $operation,
            $statusCode,
            mb_substr(trim($body), 0, 500),
        ));
    }

    public static function misconfigured(string $forge, string $detail): self
    {
        return new self(\sprintf('%s is not configured properly: %s', $forge, $detail));
    }
}
