<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\PlatformFeeThresholdState;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PlatformFeeThresholdService
{
    public function __construct(
        private readonly PlatformBalanceService $balance,
        private readonly NotificationService $notifications,
    ) {}

    public function evaluate(int $shopId): void
    {
        $summary = $this->balance->summary($shopId);
        $utilization = BigDecimal::of((string) $summary['utilization_percentage']);
        $thresholds = [
            'warning' => BigDecimal::of((string) $summary['warning_threshold_percentage']),
            'critical' => BigDecimal::of((string) $summary['critical_threshold_percentage']),
            'limit' => BigDecimal::of('100'),
        ];

        foreach ($thresholds as $key => $threshold) {
            $crossed = $utilization->isGreaterThanOrEqualTo($threshold);
            $transition = DB::transaction(function () use ($shopId, $key, $crossed, $utilization): ?array {
                $state = PlatformFeeThresholdState::query()
                    ->where('shop_id', $shopId)
                    ->where('threshold_key', $key)
                    ->lockForUpdate()
                    ->first();

                if (! $state) {
                    $state = PlatformFeeThresholdState::query()->create([
                        'shop_id' => $shopId,
                        'threshold_key' => $key,
                        'crossed' => false,
                        'utilization_percentage' => $utilization->toScale(2)->__toString(),
                    ]);
                }

                $wasCrossed = (bool) $state->crossed;
                $state->update([
                    'crossed' => $crossed,
                    'crossing_count' => (int) $state->crossing_count + ($crossed && ! $wasCrossed ? 1 : 0),
                    'utilization_percentage' => $utilization->toScale(2)->__toString(),
                    'last_notified_at' => $crossed && ! $wasCrossed ? now() : $state->last_notified_at,
                ]);

                return $wasCrossed !== $crossed ? [$state->fresh(), $crossed] : null;
            });

            if ($transition) {
                [$state, $entered] = $transition;
                activity()
                    ->performedOn($state)
                    ->withProperties([
                        'shop_id' => $shopId,
                        'threshold' => $key,
                        'utilization_percentage' => $state->utilization_percentage,
                    ])
                    ->log($entered ? 'platform_balance_threshold_crossed' : 'platform_balance_threshold_cleared');
                if ($key === 'limit') {
                    activity()
                        ->performedOn($state)
                        ->withProperties([
                            'shop_id' => $shopId,
                            'utilization_percentage' => $state->utilization_percentage,
                        ])
                        ->log($entered ? 'platform_restriction_entered' : 'platform_restriction_restored');
                }
                if ($entered) {
                    $this->notifyCrossing($state, $summary);
                }
            }
        }
    }

    /** @param array<string, mixed> $summary */
    private function notifyCrossing(PlatformFeeThresholdState $state, array $summary): void
    {
        $label = ucfirst((string) $state->threshold_key);
        $message = match ($state->threshold_key) {
            'limit' => 'Platform Balance reached the effective limit. New marketplace transactions are restricted until the balance falls below the limit.',
            'critical' => 'Platform Balance crossed the critical threshold. Please arrange full payment soon.',
            default => 'Platform Balance crossed the warning threshold. Please review the fee ledger and payment options.',
        };

        try {
            $data = [
                'threshold' => $state->threshold_key,
                'utilization_percentage' => (string) $state->utilization_percentage,
                'net_payable' => $summary['net_payable'],
                'balance_limit' => $summary['balance_limit'],
            ];
            $this->notifications->sendToShopOwner(
                shopOwnerId: (int) $state->shop_id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: "Platform Balance {$label} Threshold",
                message: $message,
                data: $data,
                actionUrl: '/shop-owner/platform-balance',
                priority: $state->threshold_key === 'limit' ? 'high' : 'medium',
                groupKey: "platform-balance-threshold:{$state->shop_id}:{$state->threshold_key}:".now()->toDateString(),
            );
            $this->notifications->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $state->shop_id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: "Platform Balance {$label} Threshold",
                message: $message,
                data: $data,
                actionUrl: '/finance/platform-balance',
                priority: $state->threshold_key === 'limit' ? 'high' : 'medium',
                groupKey: "platform-balance-threshold:{$state->shop_id}:{$state->threshold_key}:".now()->toDateString().':finance',
                requiredPermission: 'access-finance-dashboard',
            );
        } catch (\Throwable $exception) {
            Log::warning('Platform Balance threshold notification failed', [
                'shop_id' => $state->shop_id,
                'threshold' => $state->threshold_key,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
