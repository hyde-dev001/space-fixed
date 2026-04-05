<?php

namespace App\Support\Tax;

final class VatInclusiveCalculator
{
    public static function extract(float $inclusiveTotal, float $vatRatePercent = 12.0): array
    {
        $total = round(max(0.0, $inclusiveTotal), 2);
        $rate = max(0.0, $vatRatePercent);

        if ($total <= 0 || $rate <= 0) {
            return [
                'total' => $total,
                'vat' => 0.0,
                'net' => $total,
            ];
        }

        $vat = round($total * ($rate / (100 + $rate)), 2);
        $net = round($total - $vat, 2);

        return [
            'total' => $total,
            'vat' => $vat,
            'net' => $net,
        ];
    }
}
