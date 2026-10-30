<?php

namespace App\Services;

use App\Models\DeliveryDispute;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeeThresholdState;
use App\Models\PlatformReliabilityScore;
use App\Models\PosRefund;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class PlatformReliabilityService
{
    public function __construct(
        private readonly PlatformFeeSettingsResolver $settings,
    ) {}

    public function recalculate(ShopOwner|int $shop, ?CarbonInterface $scoreDate = null): PlatformReliabilityScore
    {
        $owner = $shop instanceof ShopOwner ? $shop : ShopOwner::query()->findOrFail($shop);
        $reliability = $this->settings->reliabilityFor($owner);
        $date = ($scoreDate ?: now())->toDateString();
        $cutoff = ($scoreDate ?: now())->copy()->subDays($reliability['window_days']);
        $weights = $this->weights($reliability['weights']);

        $orders = Order::query()
            ->where('shop_owner_id', $owner->id)
            ->where('origin_channel', 'marketplace')
            ->where('created_at', '>=', $cutoff)
            ->get(['id', 'status', 'created_at']);
        $repairs = RepairRequest::query()
            ->where('shop_owner_id', $owner->id)
            ->where('origin_channel', 'marketplace')
            ->where('created_at', '>=', $cutoff)
            ->get(['id', 'status', 'created_at']);
        $charges = PlatformFeeCharge::query()
            ->where('shop_id', $owner->id)
            ->where('source_origin', 'marketplace')
            ->where('created_at', '>=', $cutoff)
            ->get(['id']);
        $payments = PlatformFeePayment::query()
            ->where('shop_id', $owner->id)
            ->where('created_at', '>=', $cutoff)
            ->get(['status', 'created_at', 'paid_at']);
        $completedTransactions = $orders->filter(fn (Order $order): bool => in_array($this->statusValue($order->status), ['delivered', 'completed'], true))->count()
            + $repairs->filter(fn (RepairRequest $repair): bool => in_array($this->statusValue($repair->status), ['completed', 'ready-for-pickup', 'ready_for_pickup'], true))->count();
        $marketplaceTransactions = $orders->count() + $repairs->count();

        $paidChargeIds = DB::table('platform_fee_payment_allocations as allocations')
            ->join('platform_fee_payments as payments', 'payments.id', '=', 'allocations.platform_fee_payment_id')
            ->whereIn('allocations.platform_fee_charge_id', $charges->modelKeys())
            ->where('allocations.allocation_type', 'payment')
            ->where('payments.status', 'paid')
            ->distinct()
            ->pluck('allocations.platform_fee_charge_id');
        $paidCharges = $paidChargeIds->count();

        $paidPayments = $payments->where('status', 'paid');
        $timelyPayments = $paidPayments->filter(fn (PlatformFeePayment $payment): bool => $payment->paid_at !== null
            && $payment->paid_at->lessThanOrEqualTo($payment->created_at->copy()->addDays(7)))->count();
        $limitBreaches = (int) (PlatformFeeThresholdState::query()
            ->where('shop_id', $owner->id)
            ->where('threshold_key', 'limit')
            ->value('crossing_count') ?? 0);

        $refunds = OrderRefund::query()
            ->where('shop_owner_id', $owner->id)
            ->whereIn('status', ['succeeded', 'successful', 'completed', 'paid', 'refunded'])
            ->where('created_at', '>=', $cutoff)
            ->whereHas('order', fn ($query) => $query->where('origin_channel', 'marketplace'))
            ->count()
            + PosRefund::query()
                ->where('shop_owner_id', $owner->id)
                ->where('module_type', 'repair')
                ->whereIn('status', ['succeeded', 'successful', 'completed', 'paid', 'refunded'])
                ->where('created_at', '>=', $cutoff)
                ->whereHas('repairRequest', fn ($query) => $query->where('origin_channel', 'marketplace'))
                ->count();
        $disputes = DeliveryDispute::query()
            ->where('shop_owner_id', $owner->id)
            ->where('created_at', '>=', $cutoff)
            ->whereHas('order', fn ($query) => $query->where('origin_channel', 'marketplace'))
            ->count();

        $settlementScore = $this->ratioScore($timelyPayments, $paidPayments->count(), $payments->count() === 0);
        $settlementScore = max(0.0, $settlementScore - min(50.0, $limitBreaches * 10.0));

        $factorScores = [
            'payment_history' => $this->ratioScore($paidCharges, $charges->count(), $charges->count() === 0),
            'settlement_timeliness' => $settlementScore,
            'marketplace_history' => $this->capScore($marketplaceTransactions * 10),
            'refund_performance' => $this->rateScore($refunds, $completedTransactions),
            'dispute_rate' => $this->rateScore($disputes, $completedTransactions),
            'account_activity' => $this->capScore($owner->created_at?->diffInDays($scoreDate ?: now()) ?? 0, 180),
        ];

        $score = BigDecimal::zero();
        $breakdown = [];
        foreach ($weights as $key => $weight) {
            $factor = BigDecimal::of((string) $factorScores[$key]);
            $contribution = $factor->multipliedBy((string) $weight)->dividedBy('100', 8, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
            $score = $score->plus($contribution);
            $breakdown[$key] = [
                'score' => $factor->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'weight' => $weight,
                'contribution' => $contribution->__toString(),
            ];
        }

        $payload = [
            'score' => $this->clamp($score)->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'factor_breakdown' => $breakdown,
            'metrics' => [
                'marketplace_orders' => $orders->count(),
                'marketplace_repairs' => $repairs->count(),
                'completed_transactions' => $completedTransactions,
                'platform_fee_charges' => $charges->count(),
                'paid_platform_fee_charges' => $paidCharges,
                'refunds' => $refunds,
                'disputes' => $disputes,
                'limit_breaches' => $limitBreaches,
                'window_days' => $reliability['window_days'],
            ],
            'version' => $reliability['version'],
            'calculated_at' => now(),
        ];

        $score = DB::transaction(function () use ($owner, $date, $payload): PlatformReliabilityScore {
            $existing = PlatformReliabilityScore::query()
                ->where('shop_owner_id', $owner->id)
                ->whereDate('score_date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update($payload);

                return $existing->fresh();
            }

            return PlatformReliabilityScore::query()->create([
                'shop_owner_id' => $owner->id,
                'score_date' => $date,
                ...$payload,
            ]);
        });

        try {
            activity()
                ->performedOn($score)
                ->withProperties([
                    'shop_owner_id' => $owner->id,
                    'score' => $score->score,
                    'version' => $score->version,
                ])
                ->log('platform_reliability_recalculated');
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $score;
    }

    public function latest(int $shopId): ?PlatformReliabilityScore
    {
        return PlatformReliabilityScore::query()
            ->where('shop_owner_id', $shopId)
            ->latest('score_date')
            ->latest('id')
            ->first();
    }

    /** @return array{key: string, minimum_score: string, recommended_limit: string|null} */
    public function tierFor(BigDecimal|string|float $score, string $shopType = 'individual'): array
    {
        $value = $score instanceof BigDecimal ? $score : BigDecimal::of((string) $score);
        $tiers = collect($this->settings->reliabilityFor($shopType)['tiers'])
            ->sortByDesc(fn (array $tier): float => (float) ($tier['minimum_score'] ?? 0));

        $tier = $tiers->first(fn (array $candidate): bool => $value->isGreaterThanOrEqualTo((string) ($candidate['minimum_score'] ?? 0)));

        return [
            'key' => (string) ($tier['key'] ?? 'base'),
            'minimum_score' => (string) ($tier['minimum_score'] ?? '0'),
            'recommended_limit' => isset($tier['recommended_limit']) && $tier['recommended_limit'] !== null
                ? (string) $tier['recommended_limit']
                : null,
        ];
    }

    /** @return array<string, int> */
    private function weights(array $configured): array
    {
        $weights = collect($configured)
            ->map(fn (mixed $weight): int => (int) $weight)
            ->all();
        if (array_sum($weights) !== 100) {
            throw new \InvalidArgumentException('Platform Reliability weights must total 100%.');
        }

        return $weights;
    }

    private function ratioScore(int|float $numerator, int|float $denominator, bool $emptyIsPerfect = false): float
    {
        if ((float) $denominator <= 0) {
            return $emptyIsPerfect ? 100.0 : 0.0;
        }

        return min(100.0, max(0.0, ((float) $numerator / (float) $denominator) * 100));
    }

    private function rateScore(int|float $numerator, int|float $denominator): float
    {
        if ((float) $denominator <= 0) {
            return 100.0;
        }

        // ponytail: five neutral observations prevent one legitimate refund from
        // collapsing a new shop's score; replace with a calibrated prior later.
        $rate = min(1.0, max(0.0, (float) $numerator / ((float) $denominator + 5)));
        $penalty = max(0.0, ($rate - 0.10) / 0.90) * 100;

        return max(0.0, 100.0 - $penalty);
    }

    private function capScore(int|float $value, int|float $ceiling = 100): float
    {
        return min(100.0, max(0.0, ((float) $value / max(1, (float) $ceiling)) * 100));
    }

    private function clamp(BigDecimal $value): BigDecimal
    {
        if ($value->isLessThan(0)) {
            return BigDecimal::zero();
        }
        if ($value->isGreaterThan(100)) {
            return BigDecimal::of('100');
        }

        return $value;
    }

    private function statusValue(mixed $status): string
    {
        return strtolower($status instanceof \BackedEnum ? (string) $status->value : (string) $status);
    }
}
