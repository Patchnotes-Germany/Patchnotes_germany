<?php

declare(strict_types=1);

namespace App\Ai\Cost;

use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use App\Core\Config\PatchnotesConfig;

/**
 * What a call cost, in euro (SPEC.md § 8.2, § 24.14).
 *
 * Prices are configuration, not code: they are given per million tokens in the provider's own
 * currency and converted with the configured rate. A model without a price entry costs nothing —
 * which is exactly right for a model running on the operator's own computer, and is also what the
 * budget's "degrade" mode falls back to.
 */
final readonly class CostCalculator
{
    public function __construct(private PatchnotesConfig $config)
    {
    }

    /**
     * @return string a decimal string, to be stored without floating point drift
     */
    public function costEur(ModelReference $model, LlmUsage $usage): string
    {
        $price = $this->priceFor($model);

        if (null === $price) {
            return '0.000000';
        }

        $inputPerMtok = (float) ($price['input_per_mtok'] ?? 0);
        $outputPerMtok = (float) ($price['output_per_mtok'] ?? 0);
        // Cached input is billed at a discount; without a configured rate it counts as normal input.
        $cachedPerMtok = isset($price['cached_input_per_mtok']) && is_numeric($price['cached_input_per_mtok'])
            ? (float) $price['cached_input_per_mtok']
            : $inputPerMtok;

        $freshInput = max(0, $usage->inputTokens - $usage->cachedInputTokens);

        $cost = ($freshInput * $inputPerMtok
            + $usage->cachedInputTokens * $cachedPerMtok
            + $usage->outputTokens * $outputPerMtok) / 1_000_000;

        return \sprintf('%.6F', $cost * $this->rateToEur((string) ($price['currency'] ?? 'USD')));
    }

    /**
     * A model nobody is billed for: a local one, or one whose price is configured as zero.
     */
    public function isFree(ModelReference $model): bool
    {
        $price = $this->priceFor($model);

        if (null === $price) {
            return true;
        }

        return 0.0 === (float) ($price['input_per_mtok'] ?? 0)
            && 0.0 === (float) ($price['output_per_mtok'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function priceFor(ModelReference $model): ?array
    {
        /** @var array<string, array<string, mixed>> $pricing */
        $pricing = $this->config->ai()['pricing'] ?? [];

        return $pricing[(string) $model] ?? null;
    }

    private function rateToEur(string $currency): float
    {
        if ('EUR' === strtoupper($currency)) {
            return 1.0;
        }

        /** @var array<string, mixed> $fx */
        $fx = $this->config->ai()['fx'] ?? [];
        $rate = (float) ($fx['usd_eur'] ?? 1.0);

        return $rate > 0.0 ? $rate : 1.0;
    }
}
