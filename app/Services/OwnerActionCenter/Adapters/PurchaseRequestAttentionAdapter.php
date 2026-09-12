<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

final class PurchaseRequestAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'purchase_requests';
    }

    public function coverageSource(): string
    {
        return 'purchase_requests';
    }

    public function primaryBucket(): string
    {
        return 'needs_my_decision';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        if (strtolower(trim((string) $owner->registration_type)) !== 'company') {
            return new OwnerAttentionAdapterResult([], 0);
        }

        $baseQuery = PurchaseRequest::query()
            ->select([
                'id',
                'shop_owner_id',
                'product_name',
                'quantity',
                'unit_cost',
                'total_cost',
                'priority',
                'status',
                'requires_owner_approval',
                'requested_date',
                'created_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->where('status', 'pending_shop_owner')
            ->where(function ($query): void {
                $query->whereNull('requires_owner_approval')
                    ->orWhere('requires_owner_approval', true);
            })
            ->orderByDesc('requested_date')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $requests = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $requests->map(function (PurchaseRequest $request): OwnerAttentionItem {
            $persistedTotal = (float) $request->total_cost;
            $totalCost = $persistedTotal > 0
                ? round($persistedTotal, 2)
                : round(max(0, (int) $request->quantity * (float) $request->unit_cost), 2);
            $actionableSince = $request->requested_date ?? $request->created_at;

            return new OwnerAttentionItem(
                sourceType: 'purchase_request',
                sourceId: (int) $request->getKey(),
                category: 'purchase_request_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'procurement',
                title: 'Purchase request approval',
                conciseSummary: 'Review the purchase request awaiting your decision.',
                priorityTier: $this->priorityTier((string) $request->priority),
                materialityTier: $this->materialityTier($totalCost),
                comparableMonetaryExposure: $totalCost,
                urgencyAt: null,
                actionableSince: $actionableSince?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval=purchase_request:'
                    .$request->getKey(),
            );
        })->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function priorityTier(string $priority): string
    {
        return match (strtolower(trim($priority))) {
            'high' => 'high',
            'low' => 'low',
            default => 'normal',
        };
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
