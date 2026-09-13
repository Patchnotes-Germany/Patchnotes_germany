<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Client\LlmClientFactory;
use App\Ai\Client\LlmClientRegistry;
use App\Ai\Cost\CostCalculator;
use App\Ai\Cost\UsageRecorder;
use App\Ai\Enum\AiTask;
use App\Ai\Routing\ModelRouter;
use App\Ai\Value\ModelReference;
use App\Core\Config\PatchnotesConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Which models a task is actually offered (SPEC.md § 8.2).
 *
 * The configured chain is an intention; what matters is what survives missing credentials, unknown
 * providers and the "verify on a different provider" rule. No budget is configured here, so the
 * guard answers without touching the database.
 */
#[CoversClass(ModelRouter::class)]
final class ModelRouterTest extends TestCase
{
    public function testItResolvesAliasesInOrder(): void
    {
        $route = $this->router()->route(AiTask::ChangeAnalyze);

        self::assertSame(['openai:big', 'anthropic:medium'], array_map(strval(...), $route->chain));
        self::assertSame('large', $route->chain[0]->alias);
        self::assertSame(0.0, $route->temperature);
    }

    public function testTheTaskTemperatureAndBatchPolicyAreCarried(): void
    {
        $route = $this->router()->route(AiTask::CardTranslate);

        self::assertSame(0.2, $route->temperature);
        self::assertTrue($route->allowsBatch());
    }

    /**
     * A provider without an API key is not an error: the operator simply has not signed up for it.
     */
    public function testAProviderWithoutCredentialsIsSkipped(): void
    {
        $config = $this->config();
        unset($config['ai']['providers']['openai']['api_key']);

        $route = $this->router($config)->route(AiTask::ChangeAnalyze);

        self::assertSame(['anthropic:medium'], array_map(strval(...), $route->chain));
    }

    public function testAnAliasWithoutAValueIsSkipped(): void
    {
        $config = $this->config();
        $config['ai']['models']['small'] = '';

        $route = $this->router($config)->route(AiTask::LawTopics);

        self::assertSame(['local:local-model'], array_map(strval(...), $route->chain));
    }

    public function testAChainWithNothingUsableComesBackEmpty(): void
    {
        $config = $this->config();
        $config['ai']['models']['large'] = '';
        $config['ai']['models']['medium'] = '';

        $route = $this->router($config)->route(AiTask::ChangeAnalyze);

        self::assertTrue($route->isEmpty());
    }

    public function testAModelPointingAtAnUnknownProviderIsSkipped(): void
    {
        $config = $this->config();
        $config['ai']['models']['large'] = 'nosuch:model';

        $route = $this->router($config)->route(AiTask::ChangeAnalyze);

        self::assertSame(['anthropic:medium'], array_map(strval(...), $route->chain));
    }

    /**
     * Verification asks for a second opinion from another provider (SPEC.md § 24.14).
     */
    public function testVerificationPrefersADifferentProvider(): void
    {
        $config = $this->config();
        $config['ai']['tasks']['card_verify']['chain'] = ['@large', '@medium'];

        $route = $this->router($config)->route(AiTask::CardVerify, ModelReference::parse('openai:big'));

        self::assertSame(['anthropic:medium'], array_map(strval(...), $route->chain));
        self::assertFalse($route->sameProviderFallback);
    }

    /**
     * With a single provider configured the second opinion can only come from another model of the
     * same provider — which the caller must know, because it caps the score (SPEC.md § 24.14).
     */
    public function testWithOneProviderVerificationFallsBackToAnotherModelAndSaysSo(): void
    {
        $config = $this->config();
        $config['ai']['models']['medium'] = 'openai:small';
        $config['ai']['tasks']['card_verify']['chain'] = ['@large', '@medium'];

        $route = $this->router($config)->route(AiTask::CardVerify, ModelReference::parse('openai:big'));

        self::assertSame(['openai:small'], array_map(strval(...), $route->chain));
        self::assertTrue($route->sameProviderFallback);
    }

    public function testATaskWithoutAvoidanceKeepsItsChain(): void
    {
        $route = $this->router()->route(AiTask::CardVerify);

        self::assertSame(['anthropic:medium'], array_map(strval(...), $route->chain));
        self::assertFalse($route->sameProviderFallback);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function router(?array $config = null): ModelRouter
    {
        $patchnotes = new PatchnotesConfig($config ?? $this->config());
        $costs = new CostCalculator($patchnotes);
        $factory = new LlmClientFactory(new MockHttpClient(), new NullLogger());

        // No budget is configured, so the guard answers without ever asking what was spent — the
        // entity manager is a stub that is never touched.
        $budget = new BudgetGuard(
            $patchnotes,
            new UsageRecorder($this->createStub(EntityManagerInterface::class), $costs),
            $costs,
            new NullLogger(),
        );

        return new ModelRouter($patchnotes, new LlmClientRegistry($patchnotes, $factory), $budget, new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'ai' => [
                'providers' => [
                    'openai' => ['type' => 'openai', 'api_key' => 'test-key'],
                    'anthropic' => ['type' => 'anthropic', 'api_key' => 'test-key'],
                    'local' => ['type' => 'openai_compatible', 'base_url' => 'http://localhost:1234/v1'],
                ],
                'models' => [
                    'large' => 'openai:big',
                    'medium' => 'anthropic:medium',
                    'small' => 'openai:small',
                    'local_default' => 'local:local-model',
                ],
                'tasks' => [
                    'change_analyze' => ['chain' => ['@large', '@medium'], 'temperature' => 0],
                    'card_verify' => ['chain' => ['@medium'], 'temperature' => 0, 'prefer_different_provider_than' => 'card_write'],
                    'card_translate' => ['chain' => ['@medium', '@local_default'], 'temperature' => 0.2, 'batch' => 'bulk_only'],
                    'law_topics' => ['chain' => ['@small', '@local_default'], 'temperature' => 0],
                ],
                'budget' => ['monthly_limit_eur' => 0, 'on_exceed' => 'degrade', 'alert_thresholds' => [0.8, 1.0]],
                'pricing' => [],
                'fx' => ['usd_eur' => 0.92],
            ],
        ];
    }
}
