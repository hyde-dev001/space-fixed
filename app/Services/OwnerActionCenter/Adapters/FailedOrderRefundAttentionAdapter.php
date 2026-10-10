<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class FailedOrderRefundAttentionAdapter implements OwnerAttentionAdapter
{
    /** @var array<int, string> */
    private const RECOVERY_STATUSES = [
        OrderRefund::RECOVERY_STATUS_UNRESOLVED,
        OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
        OrderRefund::RECOVERY_STATUS_RESOLVED,
        OrderRefund::RECOVERY_STATUS_SUPERSEDED,
    ];

    public function adapterKey(): string
    {
        return 'failed_order_refunds';
    }

    public function coverageSource(): string
    {
        return 'refunds';
    }

    public function primaryBucket(): string
    {
        return 'urgent_exceptions';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $this->assertRecoveryStateIsConsistent($owner);

        $baseQuery = OrderRefund::query()
            ->select([
                'id',
                'shop_owner_id',
                'order_id',
                'status',
                'shop_owner_status',
                'amount',
                'currency',
                'failure_reason',
                'failed_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->whereHas('order', static function (Builder $orderQuery) use ($owner): void {
                $orderQuery->where('shop_owner_id', (int) $owner->getKey());
            })
            ->where('status', 'failed')
            ->whereIn('recovery_status', [
                OrderRefund::RECOVERY_STATUS_UNRESOLVED,
                OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            ])
            ->where('recovery_responsible_party', OrderRefund::RECOVERY_RESPONSIBLE_NONE)
            ->where(function (Builder $statusQuery): void {
                $statusQuery
                    ->whereNull('shop_owner_status')
                    ->orWhere('shop_owner_status', '!=', 'pending');
            })
            ->whereNotNull('failed_at')
            ->orderBy('failed_at')
            ->orderBy('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $refunds = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $refunds
            ->map(fn (OrderRefund $refund): OwnerAttentionItem => $this->toItem($refund))
            ->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function toItem(OrderRefund $refund): OwnerAttentionItem
    {
        $failedAt = $refund->failed_at?->toISOString() ?? now()->toISOString();
        $amount = (float) $refund->amount;

        return new OwnerAttentionItem(
            sourceType: 'order_refund',
            sourceId: (int) $refund->getKey(),
            category: 'failed_refund_recovery',
            primaryBucket: $this->primaryBucket(),
            module: 'finance',
            title: 'Order refund recovery failed',
            conciseSummary: 'A failed refund has no active Finance or payment-recovery owner.',
            priorityTier: 'high',
            materialityTier: 'high',
            comparableMonetaryExposure: strtoupper((string) $refund->currency) === 'PHP'
                ? round($amount, 2)
                : null,
            urgencyAt: $failedAt,
            actionableSince: $failedAt,
            waitingOn: 'none',
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.refund-approvals', [], false)
                .'?refund_type=order&refund='.$refund->getKey(),
        );
    }

    private function assertRecoveryStateIsConsistent(ShopOwner $owner): void
    {
        $invalidExists = OrderRefund::query()
            ->where('shop_owner_id', (int) $owner->getKey())
            ->whereNotNull('recovery_status')
            ->where(function (Builder $query): void {
                $query
                    ->whereNotIn('recovery_status', self::RECOVERY_STATUSES)
                    ->orWhere(function (Builder $responsibilityQuery): void {
                        $responsibilityQuery
                            ->whereNotNull('recovery_responsible_party')
                            ->whereNotIn('recovery_responsible_party', [
                                OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
                                OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
                                OrderRefund::RECOVERY_RESPONSIBLE_NONE,
                            ]);
                    });
            })
            ->exists();

        if ($invalidExists) {
            throw new RuntimeException('order_refund_recovery_state_inconsistent');
        }
    }
}
