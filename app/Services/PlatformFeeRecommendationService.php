<?php

namespace App\Services;

use App\Models\PlatformFeeRecommendation;
use App\Models\ShopOwner;
use App\Models\ShopPlatformFeeSetting;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PlatformFeeRecommendationService
{
    public function __construct(
        private readonly PlatformReliabilityService $reliability,
        private readonly PlatformFeeSettingsResolver $settings,
    ) {}

    public function recommend(ShopOwner|int $shop): ?PlatformFeeRecommendation
    {
        $owner = $shop instanceof ShopOwner ? $shop : ShopOwner::query()->findOrFail($shop);
        $score = $this->reliability->latest((int) $owner->id) ?: $this->reliability->recalculate($owner);
        $shopType = $this->settings->forShop($owner)['shop_type'];
        $tier = $this->reliability->tierFor((string) $score->score, $shopType);
        if ($tier['recommended_limit'] === null) {
            return null;
        }

        $currentLimit = BigDecimal::of((string) $this->settings->forShop($owner)['balance_limit']);
        $recommendedLimit = BigDecimal::of($tier['recommended_limit']);
        if ($recommendedLimit->isLessThanOrEqualTo($currentLimit)) {
            return null;
        }

        $existing = PlatformFeeRecommendation::query()
            ->where('shop_owner_id', $owner->id)
            ->where('reliability_score_id', $score->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $recommendation = PlatformFeeRecommendation::query()->create([
            'shop_owner_id' => $owner->id,
            'reliability_score_id' => $score->id,
            'tier' => $tier['key'],
            'current_limit' => $currentLimit->toScale(2)->__toString(),
            'recommended_limit' => $recommendedLimit->toScale(2)->__toString(),
            'status' => 'pending',
            'rationale' => [
                'score' => (string) $score->score,
                'minimum_score' => $tier['minimum_score'],
                'factors' => $score->factor_breakdown,
            ],
            'idempotency_key' => "platform-limit-recommendation:{$owner->id}:{$score->id}",
        ]);

        try {
            activity()
                ->performedOn($recommendation)
                ->withProperties([
                    'shop_owner_id' => $owner->id,
                    'score' => (string) $score->score,
                    'old_limit' => $currentLimit->__toString(),
                    'recommended_limit' => $recommendedLimit->__toString(),
                    'tier' => $tier['key'],
                ])
                ->log('platform_fee_limit_recommendation_created');
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $recommendation;
    }

    public function approve(PlatformFeeRecommendation $recommendation, int $adminId, ?string $note = null): PlatformFeeRecommendation
    {
        return DB::transaction(function () use ($recommendation, $adminId, $note): PlatformFeeRecommendation {
            $locked = PlatformFeeRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('This limit recommendation has already been reviewed.');
            }

            $override = ShopPlatformFeeSetting::query()->firstOrNew([
                'shop_owner_id' => $locked->shop_owner_id,
            ]);
            $override->fill([
                'balance_limit' => $locked->recommended_limit,
                'status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now(),
            ]);
            $override->save();

            $locked->update([
                'status' => 'approved',
                'reviewed_by_super_admin_id' => $adminId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $locked->fresh();
        });
    }

    public function reject(PlatformFeeRecommendation $recommendation, int $adminId, ?string $note = null): PlatformFeeRecommendation
    {
        $recommendation->update([
            'status' => 'rejected',
            'reviewed_by_super_admin_id' => $adminId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $recommendation->fresh();
    }
}
