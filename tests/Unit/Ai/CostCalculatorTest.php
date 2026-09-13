<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Cost\CostCalculator;
use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use App\Core\Config\PatchnotesConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a call costs (SPEC.md § 8.2, § 24.14): prices come from configuration in the provider's
 * currency and are converted to euro.
 */
#[CoversClass(CostCalculator::class)]
final class CostCalculatorTest extends TestCase
{
    public function testItBillsInputAndOutputPerMillionTokens(): void
    {
        $costs = $this->calculator();

        // 1M input at 2 USD + 1M output at 8 USD = 10 USD, at 0.90 EUR/USD = 9 EUR.
        $cost = $costs->costEur(ModelReference::parse('openai:test-model'), new LlmUsage(1_000_000, 1_000_000));

        self::assertSame('9.000000', $cost);
    }

    public function testCachedInputIsBilledAtItsOwnRate(): void
    {
        $costs = $this->calculator();

        // 1M input of which 800k cached: 200k × 2 + 800k × 0.20 = 0.4 + 0.16 = 0.56 USD → 0.504 EUR.
        $cost = $costs->costEur(ModelReference::parse('openai:test-model'), new LlmUsage(1_000_000, 0, 800_000));

        self::assertSame('0.504000', $cost);
    }

    public function testAPriceInEuroIsNotConverted(): void
    {
        $costs = $this->calculator();

        $cost = $costs->costEur(ModelReference::parse('euro:test-model'), new LlmUsage(1_000_000, 0));

        self::assertSame('3.000000', $cost);
    }

    /**
     * The model on the operator's own computer has no price entry — and that is what the budget's
     * "degrade" mode falls back to, so it must be recognised as free.
     */
    public function testAModelWithoutAPriceIsFree(): void
    {
        $costs = $this->calculator();
        $local = ModelReference::parse('local:qwen/qwen3.8-27b');

        self::assertTrue($costs->isFree($local));
        self::assertSame('0.000000', $costs->costEur($local, new LlmUsage(500_000, 500_000)));
    }

    public function testAModelPricedAtZeroIsFreeToo(): void
    {
        self::assertTrue($this->calculator()->isFree(ModelReference::parse('openai:free-model')));
    }

    public function testAPaidModelIsNotFree(): void
    {
        self::assertFalse($this->calculator()->isFree(ModelReference::parse('openai:test-model')));
    }

    private function calculator(): CostCalculator
    {
        return new CostCalculator(new PatchnotesConfig([
            'ai' => [
                'pricing' => [
                    'openai:test-model' => [
                        'input_per_mtok' => 2.0,
                        'output_per_mtok' => 8.0,
                        'cached_input_per_mtok' => 0.2,
                        'currency' => 'USD',
                    ],
                    'openai:free-model' => [
                        'input_per_mtok' => 0.0,
                        'output_per_mtok' => 0.0,
                        'currency' => 'USD',
                    ],
                    'euro:test-model' => [
                        'input_per_mtok' => 3.0,
                        'output_per_mtok' => 0.0,
                        'currency' => 'EUR',
                    ],
                ],
                'fx' => ['usd_eur' => 0.9],
            ],
        ]));
    }
}
