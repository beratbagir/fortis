<?php

namespace App\Services\Claude;

/**
 * Claude API kullanımının USD maliyetini config/ai.php (models + pricing_only) tablosundan hesaplar.
 */
class CostCalculator
{
    /**
     * @param  array<string, array{input: float, output: float, cache_write: float, cache_read: float}>  $pricing
     */
    public function __construct(private readonly array $pricing) {}

    public function cost(string $model, int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): float
    {
        $price = $this->pricing[$model] ?? null;

        if ($price === null) {
            return 0.0;
        }

        return round((
            $inputTokens * $price['input']
            + $outputTokens * $price['output']
            + $cacheWriteTokens * $price['cache_write']
            + $cacheReadTokens * $price['cache_read']
        ) / 1_000_000, 6);
    }
}
