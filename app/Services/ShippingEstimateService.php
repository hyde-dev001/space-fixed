<?php

namespace App\Services;

class ShippingEstimateService
{
    private const BASE_FEE = 49.0;
    private const FIRST_TIER_KM = 5.0;
    private const FIRST_TIER_RATE = 6.0;
    private const SECOND_TIER_RATE = 5.0;
    private const DEFAULT_ALLOWANCE = 49;

    /**
     * Calculate estimate from route distance in kilometers.
     *
     * Formula:
     * fee = (49 + 6 * min(d, 5) + 5 * max(d - 5, 0)) * surge
     */
    public function calculate(float $distanceKm, float $surgeMultiplier = 1.0, int $allowance = self::DEFAULT_ALLOWANCE): array
    {
        $distance = max(0.0, $distanceKm);
        $surge = $surgeMultiplier > 0 ? $surgeMultiplier : 1.0;

        $firstTierKm = min($distance, self::FIRST_TIER_KM);
        $secondTierKm = max($distance - self::FIRST_TIER_KM, 0.0);

        $baseEstimate = self::BASE_FEE
            + ($firstTierKm * self::FIRST_TIER_RATE)
            + ($secondTierKm * self::SECOND_TIER_RATE);

        $estimatedFee = (int) round($baseEstimate * $surge);
        $minEstimate = max(0, $estimatedFee - $allowance);
        $maxEstimate = $estimatedFee + $allowance;

        return [
            'distance_km' => round($distance, 2),
            'surge_multiplier' => round($surge, 2),
            'allowance' => $allowance,
            'base_fee' => $estimatedFee,
            'min_fee' => $minEstimate,
            'max_fee' => $maxEstimate,
        ];
    }
}
