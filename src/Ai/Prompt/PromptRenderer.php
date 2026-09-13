<?php

declare(strict_types=1);

namespace App\Ai\Prompt;

use App\Ai\Enum\AiTask;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Renders the versioned prompt templates of templates/ai/<task>/v<N>.{system,user}.twig
 * (SPEC.md § 8.4).
 *
 * Prompts are versioned rather than edited in place: the version is stored with every answer and is
 * part of the cache key, so improving a prompt never silently changes what was already published,
 * and old results stay explainable.
 */
final class PromptRenderer
{
    /** @var array<string, int> */
    private array $versions = [];

    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%/templates/ai')]
        private readonly string $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(AiTask $task, array $context, ?int $version = null): RenderedPrompt
    {
        $version ??= $this->latestVersion($task);
        $prefix = \sprintf('ai/%s/v%d', $task->value, $version);

        return new RenderedPrompt(
            trim($this->twig->render($prefix.'.system.twig', $context)),
            trim($this->twig->render($prefix.'.user.twig', $context)),
            $version,
        );
    }

    /**
     * The highest version present on disk — adding v2 next to v1 is all it takes to switch.
     */
    public function latestVersion(AiTask $task): int
    {
        if (isset($this->versions[$task->value])) {
            return $this->versions[$task->value];
        }

        $files = glob($this->directory.'/'.$task->value.'/v*.system.twig') ?: [];
        $latest = 0;

        foreach ($files as $file) {
            if (1 === preg_match('/v(\d+)\.system\.twig$/', $file, $matches)) {
                $latest = max($latest, (int) $matches[1]);
            }
        }

        if (0 === $latest) {
            throw new \RuntimeException(\sprintf('No prompt template for task "%s"; expected %s/%s/v1.system.twig.', $task->value, $this->directory, $task->value));
        }

        return $this->versions[$task->value] = $latest;
    }
}
