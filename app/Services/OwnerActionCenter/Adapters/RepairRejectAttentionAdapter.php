<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;

final class RepairRejectAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'repair_rejections';
    }

    public function coverageSource(): string
    {
        return 'repair_rejections';
    }

    public function primaryBucket(): string
    {
        return 'needs_my_decision';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $baseQuery = RepairRequest::query()
            ->select([
                'id',
                'shop_owner_id',
                'customer_name',
                'total',
                'status',
                'requires_owner_approval',
                'repairer_rejection_reason',
                'repairer_rejected_at',
                'repairer_rejected_by',
                'created_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->where('status', 'owner_approval_pending')
            ->where(function (Builder $snapshotQuery): void {
                $snapshotQuery
                    ->whereNull('requires_owner_approval')
                    ->orWhere('requires_owner_approval', true);
            })
            ->whereNotNull('repairer_rejection_reason')
            ->whereNotNull('repairer_rejected_at')
            ->whereNotNull('repairer_rejected_by')
            ->orderByDesc('repairer_rejected_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $repairs = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $repairs->map(function (RepairRequest $repair): OwnerAttentionItem {
            $amount = round(max(0, (float) $repair->total), 2);

            return new OwnerAttentionItem(
                sourceType: 'repair_rejection',
                sourceId: (int) $repair->getKey(),
                category: 'repair_rejection_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'repair_operations',
                title: 'Repair rejection approval',
                conciseSummary: 'Review the repairer rejection before Manager final review.',
                priorityTier: 'high',
                materialityTier: $this->materialityTier($amount),
                comparableMonetaryExposure: $amount,
                urgencyAt: null,
                actionableSince: $repair->repairer_rejected_at?->toISOString()
                    ?? $repair->created_at?->toISOString()
                    ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval=repair_rejection:'
                    .$repair->getKey(),
            );
        })->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function materialityTier(float $amount): string
    {
        return match (true) {
            $amount >= 10000 => 'high',
            $amount >= 1000 => 'medium',
            default => 'low',
        };
    }
}
