<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Enum\AiTask;
use App\Ai\Prompt\PromptRenderer;
use App\Ai\Schema\JsonSchemaValidator;
use App\Ai\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every task of SPEC.md § 8.4 must have a versioned prompt and an answer schema.
 *
 * This is the test that fails when a task is added to the catalogue and its templates are
 * forgotten — the kind of gap that would otherwise only show up in production, on the one law that
 * needed that task.
 */
#[CoversClass(PromptRenderer::class)]
#[CoversClass(SchemaRegistry::class)]
final class PromptTemplateTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{AiTask}>
     */
    public static function tasks(): iterable
    {
        foreach (AiTask::cases() as $task) {
            yield $task->value => [$task];
        }
    }

    #[DataProvider('tasks')]
    public function testEveryTaskHasAPromptPair(AiTask $task): void
    {
        $renderer = self::getContainer()->get(PromptRenderer::class);
        \assert($renderer instanceof PromptRenderer);

        $version = $renderer->latestVersion($task);

        self::assertGreaterThanOrEqual(1, $version);

        $directory = \dirname(__DIR__, 3).'/templates/ai/'.$task->value;
        self::assertFileExists(\sprintf('%s/v%d.system.twig', $directory, $version));
        self::assertFileExists(\sprintf('%s/v%d.user.twig', $directory, $version));
    }

    #[DataProvider('tasks')]
    public function testEveryTaskHasAUsableAnswerSchema(AiTask $task): void
    {
        $schemas = self::getContainer()->get(SchemaRegistry::class);
        \assert($schemas instanceof SchemaRegistry);

        $schema = $schemas->for($task);

        self::assertIsArray($schema, \sprintf('Task "%s" has no answer schema.', $task->value));
        self::assertSame('object', $schema['type'] ?? null);
        self::assertArrayHasKey('required', $schema);
        self::assertArrayHasKey('properties', $schema);
        self::assertGreaterThanOrEqual(1, $schemas->version($task));
    }

    /**
     * The schemas are consumed by our own validator, so an empty answer must actually be rejected
     * by it — a schema it silently accepts everything against would be worse than none.
     */
    #[DataProvider('tasks')]
    public function testTheSchemaRejectsAnEmptyAnswer(AiTask $task): void
    {
        $schemas = self::getContainer()->get(SchemaRegistry::class);
        \assert($schemas instanceof SchemaRegistry);
        $schema = $schemas->for($task);
        self::assertIsArray($schema);

        self::assertNotSame([], new JsonSchemaValidator()->validate($schema, []));
    }

    /**
     * The rules of § 8.5 are in every system prompt, because they are included from one file.
     */
    #[DataProvider('tasks')]
    public function testEverySystemPromptCarriesTheSharedRules(AiTask $task): void
    {
        $renderer = self::getContainer()->get(PromptRenderer::class);
        \assert($renderer instanceof PromptRenderer);
        $path = \sprintf(
            '%s/templates/ai/%s/v%d.system.twig',
            \dirname(__DIR__, 3),
            $task->value,
            $renderer->latestVersion($task),
        );

        self::assertStringContainsString("include('ai/_rules.twig')", (string) file_get_contents($path));
    }
}
