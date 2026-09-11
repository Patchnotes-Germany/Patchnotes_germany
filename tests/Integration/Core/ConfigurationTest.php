<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core;

use App\Core\Config\PatchnotesConfig;
use App\Core\PatchnotesBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The whole "patchnotes" tree must load from the real configuration files, because every
 * installation is configured through it (SPEC.md § 24.19).
 */
#[CoversClass(PatchnotesBundle::class)]
final class ConfigurationTest extends KernelTestCase
{
    public function testTheConfigurationServiceIsWiredFromTheRealConfiguration(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(PatchnotesConfig::class);

        self::assertInstanceOf(PatchnotesConfig::class, $config);
        self::assertSame(['ru', 'uk', 'en', 'tr'], $config->languages());
        self::assertSame('en', $config->masterLanguage());
        self::assertSame('Europe/Berlin', $config->timezone()->getName());
    }

    public function testRepositoriesPointIntoTheContainerVolume(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(PatchnotesConfig::class);
        self::assertInstanceOf(PatchnotesConfig::class, $config);

        foreach (['laws', 'content'] as $name) {
            $repository = $config->repository($name);
            self::assertStringEndsWith('/var/repos/'.$name, (string) $repository['local_path']);
        }
    }

    public function testEveryAiTaskOfTheCatalogueIsRouted(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(PatchnotesConfig::class);
        self::assertInstanceOf(PatchnotesConfig::class, $config);

        /** @var array<string, array{chain: list<string>}> $tasks */
        $tasks = $config->ai()['tasks'];

        foreach (\App\Ai\Enum\AiTask::cases() as $task) {
            self::assertArrayHasKey($task->value, $tasks, \sprintf('Task "%s" has no routing.', $task->value));
            self::assertNotSame([], $tasks[$task->value]['chain']);
        }
    }

    public function testReviewPolicyDefaultsMatchTheSpecification(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(PatchnotesConfig::class);
        self::assertInstanceOf(PatchnotesConfig::class, $config);

        $review = $config->review();

        self::assertTrue($review['auto_publish']);
        self::assertSame(0.8, $review['min_verify_score']);
        self::assertSame(72, $review['settling_window_hours']);
    }
}
