<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefundLineCalculatorService
{
    private const ONLINE_COMMITTED_STATUSES = ['requested', 'pending_approval', 'approved', 'processing', 'succeeded'];
    private const RETAIL_COMMITTED_STATUSES = ['requested', 'approved', 'processing', 'succeeded'];

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

    public function resolveRemainingQty(int $orderItemId, int $purchasedQty, string $channel = 'online'): int
    {
        if ($orderItemId <= 0 || $purchasedQty <= 0) {
            return 0;
        }

        $refundedQty = $channel === 'retail'
            ? $this->resolveRetailCommittedQty($orderItemId)
            : $this->resolveOnlineCommittedQty($orderItemId);

        return max(0, $purchasedQty - $refundedQty);
    }

    private function resolveOnlineCommittedQty(int $orderItemId): int
    {
        if (!Schema::hasTable('order_refund_items') || !Schema::hasTable('order_refunds')) {
            return 0;
        }

        $sum = DB::table('order_refund_items as items')
            ->join('order_refunds as refunds', 'refunds.id', '=', 'items.order_refund_id')
            ->where('items.order_item_id', $orderItemId)
            ->whereIn('refunds.status', self::ONLINE_COMMITTED_STATUSES)
            ->sum(DB::raw('COALESCE(items.approved_qty, items.requested_qty, 0)'));

        return (int) $sum;
    }

    private function resolveRetailCommittedQty(int $orderItemId): int
    {
        if (!Schema::hasTable('pos_refund_items') || !Schema::hasTable('pos_refunds')) {
            return 0;
        }

        $sum = DB::table('pos_refund_items as items')
            ->join('pos_refunds as refunds', 'refunds.id', '=', 'items.pos_refund_id')
            ->where('items.order_item_id', $orderItemId)
            ->whereIn('refunds.status', self::RETAIL_COMMITTED_STATUSES)
            ->sum(DB::raw('COALESCE(items.approved_qty, items.requested_qty, 0)'));

        return (int) $sum;
    }
}
