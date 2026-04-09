<?php

namespace App\Services;

use App\Models\PosRefund;

class RetailPosRefundSummaryService
{
    /**
     * @param array<int, int|string> $orderIds
     * @return array<int, array<string, mixed>>
     */
    public function buildForOrders(int $shopOwnerId, array $orderIds): array
    {
        $normalizedOrderIds = array_values(array_unique(array_filter(array_map(
            static fn ($value) => (int) $value,
            $orderIds,
        ), static fn (int $id) => $id > 0)));

        if ($shopOwnerId <= 0 || $normalizedOrderIds === []) {
            return [];
        }

        $finalStatuses = ['succeeded', 'failed', 'rejected', 'cancelled'];
        $committedStatuses = ['requested', 'approved', 'processing', 'succeeded'];

        $refundsByOrder = PosRefund::query()
            ->where('module_type', 'retail')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('module_reference_id', $normalizedOrderIds)
            ->with(['items:id,pos_refund_id,order_item_id,requested_qty,approved_qty'])
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (PosRefund $refund) => (int) $refund->module_reference_id);

        $summaries = [];

        foreach ($refundsByOrder as $orderId => $refunds) {
            if ($refunds->isEmpty()) {
                continue;
            }

            $latest = $refunds->first();
            $hasOpenRequest = $refunds->contains(
                fn (PosRefund $refund) => !in_array(strtolower((string) ($refund->status ?? '')), $finalStatuses, true)
            );
            $hasSucceeded = $refunds->contains(
                fn (PosRefund $refund) => strtolower((string) ($refund->status ?? '')) === 'succeeded'
            );

            $totalRequestedAmount = round((float) $refunds->sum(
                fn (PosRefund $refund) => (float) ($refund->requested_amount ?? 0)
            ), 2);

            $totalSucceededAmount = round((float) $refunds
                ->filter(fn (PosRefund $refund) => strtolower((string) ($refund->status ?? '')) === 'succeeded')
                ->sum(fn (PosRefund $refund) => (float) ($refund->approved_amount ?? $refund->requested_amount ?? 0)), 2);

            $committedQtyByOrderItem = [];
            $succeededQtyByOrderItem = [];

            foreach ($refunds as $refund) {
                $status = strtolower((string) ($refund->status ?? ''));
                if (!in_array($status, $committedStatuses, true)) {
                    continue;
                }

                foreach ($refund->items as $item) {
                    $orderItemId = (int) ($item->order_item_id ?? 0);
                    $qty = (int) ($item->approved_qty ?? $item->requested_qty ?? 0);

                    if ($orderItemId <= 0 || $qty <= 0) {
                        continue;
                    }

                    $committedQtyByOrderItem[$orderItemId] = (int) (($committedQtyByOrderItem[$orderItemId] ?? 0) + $qty);

                    if ($status === 'succeeded') {
                        $succeededQtyByOrderItem[$orderItemId] = (int) (($succeededQtyByOrderItem[$orderItemId] ?? 0) + $qty);
                    }
                }
            }

            ksort($committedQtyByOrderItem);
            ksort($succeededQtyByOrderItem);

            $committedItemQty = array_map(
                static fn (int $itemId, int $qty) => [
                    'order_item_id' => $itemId,
                    'qty' => $qty,
                ],
                array_keys($committedQtyByOrderItem),
                array_values($committedQtyByOrderItem),
            );

            $succeededItemQty = array_map(
                static fn (int $itemId, int $qty) => [
                    'order_item_id' => $itemId,
                    'qty' => $qty,
                ],
                array_keys($succeededQtyByOrderItem),
                array_values($succeededQtyByOrderItem),
            );

            $summaries[(int) $orderId] = [
                'latest_refund_id' => (int) ($latest->id ?? 0),
                'latest_status' => strtolower((string) ($latest->status ?? '')) ?: null,
                'has_activity' => true,
                'has_open_request' => $hasOpenRequest,
                'has_succeeded' => $hasSucceeded,
                'total_requested_amount' => $totalRequestedAmount,
                'total_succeeded_amount' => $totalSucceededAmount,
                'committed_item_qty' => $committedItemQty,
                'succeeded_item_qty' => $succeededItemQty,
            ];
        }

        return $summaries;
    }
}