<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OrderRefund;
use App\Models\PosRefund;
use Illuminate\Console\Command;

final class ReportPhaseThreeCRefundAssignmentGaps extends Command
{
    private const CHUNK_SIZE = 100;

    protected $signature = 'shop-owner:report-phase-3c-refund-assignment-gaps';

    protected $description = 'Report failed refund recoveries without authoritative assignment timestamps.';

    public function handle(): int
    {
        /** @var array<int, array{order: int, repair: int}> $shopCounts */
        $shopCounts = [];
        $orderIds = [];
        $repairIds = [];

        OrderRefund::query()
            ->select(['id', 'shop_owner_id'])
            ->where('status', 'failed')
            ->where('recovery_status', OrderRefund::RECOVERY_STATUS_IN_PROGRESS)
            ->whereIn('recovery_responsible_party', [
                OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
                OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            ])
            ->whereNull('recovery_assigned_at')
            ->chunkById(self::CHUNK_SIZE, function ($refunds) use (&$shopCounts, &$orderIds): void {
                foreach ($refunds as $refund) {
                    $shopId = (int) $refund->shop_owner_id;
                    $shopCounts[$shopId]['order'] = ($shopCounts[$shopId]['order'] ?? 0) + 1;
                    $shopCounts[$shopId]['repair'] ??= 0;
                    $orderIds[] = (int) $refund->getKey();
                }
            });

        PosRefund::query()
            ->select(['id', 'shop_owner_id'])
            ->where('status', 'failed')
            ->where('recovery_status', PosRefund::RECOVERY_STATUS_IN_PROGRESS)
            ->whereIn('recovery_responsible_party', [
                PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
                PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            ])
            ->whereNull('recovery_assigned_at')
            ->chunkById(self::CHUNK_SIZE, function ($refunds) use (&$shopCounts, &$repairIds): void {
                foreach ($refunds as $refund) {
                    $shopId = (int) $refund->shop_owner_id;
                    $shopCounts[$shopId]['order'] ??= 0;
                    $shopCounts[$shopId]['repair'] = ($shopCounts[$shopId]['repair'] ?? 0) + 1;
                    $repairIds[] = (int) $refund->getKey();
                }
            });

        ksort($shopCounts, SORT_NUMERIC);
        sort($orderIds, SORT_NUMERIC);
        sort($repairIds, SORT_NUMERIC);

        $this->info('Phase 3C refund assignment evidence gaps (report-only).');
        foreach ($shopCounts as $shopId => $counts) {
            $this->line(sprintf(
                'Shop owner %d: order=%d, repair=%d',
                $shopId,
                $counts['order'],
                $counts['repair'],
            ));
        }

        $this->line('Order refund gap IDs: '.($orderIds === [] ? 'none' : implode(',', $orderIds)));
        $this->line('Repair refund gap IDs: '.($repairIds === [] ? 'none' : implode(',', $repairIds)));
        $this->info(sprintf(
            'Totals: shops=%d, order_refunds=%d, repair_refunds=%d',
            count($shopCounts),
            count($orderIds),
            count($repairIds),
        ));

        return self::SUCCESS;
    }
}
