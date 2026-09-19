<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class WaitingOrderRefundRecoveryAttentionAdapter implements OwnerAttentionAdapter
{
    /** @var array<int, string> */
    private const RECOVERY_STATUSES = [
        OrderRefund::RECOVERY_STATUS_UNRESOLVED,
        OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
        OrderRefund::RECOVERY_STATUS_RESOLVED,
        OrderRefund::RECOVERY_STATUS_SUPERSEDED,
    ];

    /** @var array<int, string> */
    private const RESPONSIBLE_PARTIES = [
        OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
        OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
        OrderRefund::RECOVERY_RESPONSIBLE_NONE,
    ];

    public function adapterKey(): string
    {
        return 'waiting_order_refund_recovery';
    }

    public function coverageSource(): string
    {
        return 'refunds';
    }

    public function primaryBucket(): string
    {
        return 'waiting_on_others';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $timezone = (string) config('app.shop_timezone', 'Asia/Manila');
        $now = CarbonImmutable::now($timezone);
        $this->assertRecoveryStateIsConsistent($owner, $now);

        $baseQuery = OrderRefund::query()
            ->select([
                'id',
                'shop_owner_id',
                'order_id',
                'status',
                'shop_owner_status',
                'amount',
                'currency',
                'failed_at',
                'recovery_status',
                'recovery_responsible_party',
                'recovery_assigned_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->whereHas('order', static function (Builder $orderQuery) use ($owner): void {
                $orderQuery->where('shop_owner_id', (int) $owner->getKey());
            })
            ->where('status', 'failed')
            ->where('recovery_status', OrderRefund::RECOVERY_STATUS_IN_PROGRESS)
            ->whereIn('recovery_responsible_party', [
                OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
                OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            ])
            ->where(function (Builder $statusQuery): void {
                $statusQuery
                    ->whereNull('shop_owner_status')
                    ->orWhere('shop_owner_status', '!=', 'pending');
            })
            ->whereNotNull('failed_at')
            ->whereNotNull('recovery_assigned_at')
            ->orderBy('recovery_assigned_at')
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
        if (! $refund->failed_at || ! $refund->recovery_assigned_at) {
            throw new RuntimeException('order_refund_recovery_assignment_inconsistent');
        }

        $amount = strtoupper((string) $refund->currency) === 'PHP'
            ? round((float) $refund->amount, 2)
            : null;

        return new OwnerAttentionItem(
            sourceType: 'order_refund',
            sourceId: (int) $refund->getKey(),
            category: 'refund_recovery_waiting',
            primaryBucket: $this->primaryBucket(),
            module: 'finance',
            title: 'Order refund recovery',
            conciseSummary: 'A failed order refund is awaiting Finance or Payment Recovery.',
            priorityTier: 'high',
            materialityTier: 'high',
            comparableMonetaryExposure: $amount,
            urgencyAt: $refund->failed_at->toISOString(),
            actionableSince: $refund->recovery_assigned_at->toISOString(),
            waitingOn: (string) $refund->recovery_responsible_party,
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.refund-approvals', [], false)
                .'?refund_type=order&refund='.$refund->getKey(),
        );
    }

    private function assertRecoveryStateIsConsistent(ShopOwner $owner, CarbonImmutable $now): void
    {
        $ownerId = (int) $owner->getKey();
        $invalidStateExists = OrderRefund::query()
            ->where('shop_owner_id', $ownerId)
            ->whereNotNull('recovery_status')
            ->where(function (Builder $query): void {
                $query
                    ->whereNotIn('recovery_status', self::RECOVERY_STATUSES)
                    ->orWhere(function (Builder $responsibilityQuery): void {
                        $responsibilityQuery
                            ->whereNotNull('recovery_responsible_party')
                            ->whereNotIn('recovery_responsible_party', self::RESPONSIBLE_PARTIES);
                    });
            })
            ->exists();

        if ($invalidStateExists) {
            throw new RuntimeException('order_refund_recovery_state_inconsistent');
        }

        $invalidAssignmentExists = OrderRefund::query()
            ->where('shop_owner_id', $ownerId)
            ->where('status', 'failed')
            ->where('recovery_status', OrderRefund::RECOVERY_STATUS_IN_PROGRESS)
            ->whereIn('recovery_responsible_party', [
                OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
                OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            ])
            ->where(function (Builder $statusQuery): void {
                $statusQuery
                    ->whereNull('shop_owner_status')
                    ->orWhere('shop_owner_status', '!=', 'pending');
            })
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull('failed_at')
                    ->orWhereNull('recovery_assigned_at')
                    ->orWhereColumn('recovery_assigned_at', '<', 'failed_at')
                    ->orWhere('recovery_assigned_at', '>', $now);
            })
            ->exists();

        if ($invalidAssignmentExists) {
            throw new RuntimeException('order_refund_recovery_assignment_inconsistent');
        }
    }
}
