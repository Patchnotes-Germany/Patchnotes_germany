<?php

declare(strict_types=1);

namespace App\Ai\Schema;

use App\Ai\Enum\AiTask;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The answer schemas of config/ai/schemas/<task>.json (SPEC.md § 8.4).
 *
 * A task without a schema is allowed — some answers are prose — but a task whose schema file is
 * broken is a configuration error worth failing loudly for.
 */
final class SchemaRegistry
{
    /** @var array<string, array<string, mixed>|null> */
    private array $schemas = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/ai/schemas')]
        private readonly string $directory,
    ) {
    }

    public function has(AiTask $task): bool
    {
        return null !== $this->for($task);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function for(AiTask $task): ?array
    {
        if (\array_key_exists($task->value, $this->schemas)) {
            return $this->schemas[$task->value];
        }

        $path = $this->directory.'/'.$task->value.'.json';

        if (!is_file($path)) {
            return $this->schemas[$task->value] = null;
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new \RuntimeException(\sprintf('The schema file "%s" cannot be read.', $path));
        }

        try {
            /** @var array<string, mixed> $schema */
            $schema = (array) json_decode($contents, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('The schema file "%s" is not valid JSON: %s', $path, $exception->getMessage()), 0, $exception);
        }

        return $this->schemas[$task->value] = $schema;
    }

    /**
     * The version of the schema, part of the cache key so a changed schema invalidates old answers
     * (SPEC.md § 8.2).
     */
    public function version(AiTask $task): int
    {
        $schema = $this->for($task);

        return is_numeric($schema['version'] ?? null) ? (int) $schema['version'] : 1;
    }
}
