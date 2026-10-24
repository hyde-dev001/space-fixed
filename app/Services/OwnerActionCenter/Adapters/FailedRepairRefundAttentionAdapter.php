<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PosRefund;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class FailedRepairRefundAttentionAdapter implements OwnerAttentionAdapter
{
    /** @var array<int, string> */
    private const RECOVERY_STATUSES = [
        PosRefund::RECOVERY_STATUS_UNRESOLVED,
        PosRefund::RECOVERY_STATUS_IN_PROGRESS,
        PosRefund::RECOVERY_STATUS_RESOLVED,
        PosRefund::RECOVERY_STATUS_SUPERSEDED,
    ];

    public function adapterKey(): string
    {
        return 'failed_repair_refunds';
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

        $baseQuery = PosRefund::query()
            ->select([
                'id',
                'shop_owner_id',
                'module_type',
                'status',
                'shop_owner_status',
                'requested_amount',
                'failure_reason',
                'failed_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->where('module_type', 'repair')
            ->where('status', 'failed')
            ->whereIn('recovery_status', [
                PosRefund::RECOVERY_STATUS_UNRESOLVED,
                PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            ])
            ->where('recovery_responsible_party', PosRefund::RECOVERY_RESPONSIBLE_NONE)
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
            ->map(fn (PosRefund $refund): OwnerAttentionItem => $this->toItem($refund))
            ->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function toItem(PosRefund $refund): OwnerAttentionItem
    {
        $failedAt = $refund->failed_at?->toISOString() ?? now()->toISOString();

        return new OwnerAttentionItem(
            sourceType: 'repair_refund',
            sourceId: (int) $refund->getKey(),
            category: 'failed_refund_recovery',
            primaryBucket: $this->primaryBucket(),
            module: 'finance',
            title: 'Repair refund recovery failed',
            conciseSummary: 'A failed repair refund has no active Finance or payment-recovery owner.',
            priorityTier: 'high',
            materialityTier: 'high',
            comparableMonetaryExposure: round((float) $refund->requested_amount, 2),
            urgencyAt: $failedAt,
            actionableSince: $failedAt,
            waitingOn: 'none',
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.refund-approvals', [], false)
                .'?refund_type=repair&refund='.$refund->getKey(),
        );
    }

    private function assertRecoveryStateIsConsistent(ShopOwner $owner): void
    {
        $invalidExists = PosRefund::query()
            ->where('shop_owner_id', (int) $owner->getKey())
            ->whereNotNull('recovery_status')
            ->where(function (Builder $query): void {
                $query
                    ->whereNotIn('recovery_status', self::RECOVERY_STATUSES)
                    ->orWhere(function (Builder $responsibilityQuery): void {
                        $responsibilityQuery
                            ->whereNotNull('recovery_responsible_party')
                            ->whereNotIn('recovery_responsible_party', [
                                PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
                                PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
                                PosRefund::RECOVERY_RESPONSIBLE_NONE,
                            ]);
                    });
            })
            ->exists();

        if ($invalidExists) {
            throw new RuntimeException('repair_refund_recovery_state_inconsistent');
        }
    }
}
