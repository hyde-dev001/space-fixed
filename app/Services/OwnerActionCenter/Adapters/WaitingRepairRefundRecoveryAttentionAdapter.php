<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PosRefund;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class WaitingRepairRefundRecoveryAttentionAdapter implements OwnerAttentionAdapter
{
    /** @var array<int, string> */
    private const RECOVERY_STATUSES = [
        PosRefund::RECOVERY_STATUS_UNRESOLVED,
        PosRefund::RECOVERY_STATUS_IN_PROGRESS,
        PosRefund::RECOVERY_STATUS_RESOLVED,
        PosRefund::RECOVERY_STATUS_SUPERSEDED,
    ];

    /** @var array<int, string> */
    private const RESPONSIBLE_PARTIES = [
        PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
        PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
        PosRefund::RECOVERY_RESPONSIBLE_NONE,
    ];

    public function adapterKey(): string
    {
        return 'waiting_repair_refund_recovery';
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

        $baseQuery = PosRefund::query()
            ->select([
                'id',
                'shop_owner_id',
                'module_type',
                'status',
                'shop_owner_status',
                'requested_amount',
                'failed_at',
                'recovery_status',
                'recovery_responsible_party',
                'recovery_assigned_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->where('module_type', 'repair')
            ->where('status', 'failed')
            ->where('recovery_status', PosRefund::RECOVERY_STATUS_IN_PROGRESS)
            ->whereIn('recovery_responsible_party', [
                PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
                PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
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
            ->map(fn (PosRefund $refund): OwnerAttentionItem => $this->toItem($refund))
            ->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function toItem(PosRefund $refund): OwnerAttentionItem
    {
        if (! $refund->failed_at || ! $refund->recovery_assigned_at) {
            throw new RuntimeException('repair_refund_recovery_assignment_inconsistent');
        }

        return new OwnerAttentionItem(
            sourceType: 'repair_refund',
            sourceId: (int) $refund->getKey(),
            category: 'refund_recovery_waiting',
            primaryBucket: $this->primaryBucket(),
            module: 'finance',
            title: 'Repair refund recovery',
            conciseSummary: 'A failed repair refund is awaiting Finance or Payment Recovery.',
            priorityTier: 'high',
            materialityTier: 'high',
            comparableMonetaryExposure: round((float) $refund->requested_amount, 2),
            urgencyAt: $refund->failed_at->toISOString(),
            actionableSince: $refund->recovery_assigned_at->toISOString(),
            waitingOn: (string) $refund->recovery_responsible_party,
            ownerActionRequired: false,
            coverageSource: $this->coverageSource(),
            destinationUrl: route('shop-owner.refund-approvals', [], false)
                .'?refund_type=repair&refund='.$refund->getKey(),
        );
    }

    private function assertRecoveryStateIsConsistent(ShopOwner $owner, CarbonImmutable $now): void
    {
        $ownerId = (int) $owner->getKey();
        $invalidStateExists = PosRefund::query()
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
            throw new RuntimeException('repair_refund_recovery_state_inconsistent');
        }

        $invalidAssignmentExists = PosRefund::query()
            ->where('shop_owner_id', $ownerId)
            ->where('module_type', 'repair')
            ->where('status', 'failed')
            ->where('recovery_status', PosRefund::RECOVERY_STATUS_IN_PROGRESS)
            ->whereIn('recovery_responsible_party', [
                PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
                PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
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
            throw new RuntimeException('repair_refund_recovery_assignment_inconsistent');
        }
    }
}
