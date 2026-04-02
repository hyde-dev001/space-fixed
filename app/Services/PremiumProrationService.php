<?php

namespace App\Services;

use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use Carbon\CarbonInterface;

class PremiumProrationService
{
    /**
     * Compute upgrade proration preview based on the current subscription.
     *
     * @return array<string, mixed>
     */
    public function preview(
        ?ShopOwnerSubscription $currentSubscription,
        ?PremiumPlan $currentPlan,
        PremiumPlan $newPlan,
        ?CarbonInterface $asOf = null
    ): array {
        $asOf = $asOf ?: now();

        $newPlanPrice = (float) $newPlan->price;
        $newDurationDays = max(1, (int) $newPlan->duration_days);

        if (!$currentSubscription || !$currentPlan) {
            return $this->buildResult(
                remainingDays: 0.0,
                dailyRate: 0.0,
                remainingValue: 0.0,
                newPlanPrice: $newPlanPrice,
                finalPrice: $newPlanPrice,
                newExpiry: $asOf->copy()->addDays($newDurationDays)
            );
        }

        $currentEndsAt = $currentSubscription->ends_at;
        if (!$currentEndsAt && $currentSubscription->starts_at) {
            $currentEndsAt = $currentSubscription->starts_at->copy()->addDays(max(1, (int) $currentPlan->duration_days));
        }

        if (!$currentEndsAt || $currentEndsAt->lessThanOrEqualTo($asOf)) {
            return $this->buildResult(
                remainingDays: 0.0,
                dailyRate: 0.0,
                remainingValue: 0.0,
                newPlanPrice: $newPlanPrice,
                finalPrice: $newPlanPrice,
                newExpiry: $asOf->copy()->addDays($newDurationDays)
            );
        }

        $totalDays = max(1, (int) $currentPlan->duration_days);
        // Compute remaining seconds from now until current expiry.
        $secondsRemaining = max(0, $asOf->diffInSeconds($currentEndsAt, false));
        $remainingDays = round($secondsRemaining / 86400, 6);

        $dailyRate = round(((float) $currentPlan->price) / $totalDays, 6);
        $remainingValue = round($dailyRate * $remainingDays, 2);
        $finalPrice = round(max(0, $newPlanPrice - $remainingValue), 2);

        return $this->buildResult(
            remainingDays: $remainingDays,
            dailyRate: $dailyRate,
            remainingValue: $remainingValue,
            newPlanPrice: $newPlanPrice,
            finalPrice: $finalPrice,
            newExpiry: $asOf->copy()->addDays($newDurationDays)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResult(
        float $remainingDays,
        float $dailyRate,
        float $remainingValue,
        float $newPlanPrice,
        float $finalPrice,
        CarbonInterface $newExpiry
    ): array {
        return [
            'remaining_days' => $remainingDays,
            'daily_rate' => round($dailyRate, 6),
            'remaining_value' => round($remainingValue, 2),
            'new_plan_price' => round($newPlanPrice, 2),
            'final_price' => round(max(0, $finalPrice), 2),
            'new_expiry' => $newExpiry->toISOString(),
            'payment_required' => round(max(0, $finalPrice), 2) > 0,
        ];
    }
}