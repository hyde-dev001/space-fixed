<?php

namespace App\Services;

class RefundLineCalculatorService
{
    public function computeLineAmount(float $unitPrice, int $qty): float
    {
        return round(max(0, $unitPrice) * max(0, $qty), 2);
    }

    /**
     * @param array<int, float|int|string> $lineAmounts
     */
    public function aggregateAmount(array $lineAmounts): float
    {
        return round(array_sum(array_map(static fn ($amount) => (float) $amount, $lineAmounts)), 2);
    }
}
