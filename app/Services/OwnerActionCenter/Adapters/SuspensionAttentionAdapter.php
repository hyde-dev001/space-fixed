<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Enums\SuspensionStatus;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

final class SuspensionAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'suspension_requests';
    }

    public function coverageSource(): string
    {
        return 'suspensions';
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

        $baseQuery = SuspensionRequest::query()
            ->select([
                'id',
                'employee_id',
                'requested_by',
                'reason',
                'evidence',
                'status',
                'manager_status',
                'manager_note',
                'created_at',
            ])
            ->with([
                'employee:id,shop_owner_id,first_name,last_name,email,position',
                'requester:id,name,email',
            ])
            ->where('status', SuspensionStatus::PENDING_OWNER->value)
            ->where('manager_status', 'approved')
            ->whereHas('employee', function ($employeeQuery) use ($owner): void {
                $employeeQuery->forShopOwner((int) $owner->getKey());
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $requests = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $requests->map(function (SuspensionRequest $request): OwnerAttentionItem {
            $employeeName = trim(implode(' ', array_filter([
                $request->employee?->first_name,
                $request->employee?->last_name,
            ]))) ?: 'Employee';

            return new OwnerAttentionItem(
                sourceType: 'suspension_request',
                sourceId: (int) $request->getKey(),
                category: 'suspension_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'hr',
                title: 'Employee suspension approval',
                conciseSummary: "Review {$employeeName}'s suspension request.",
                priorityTier: 'high',
                materialityTier: 'none',
                comparableMonetaryExposure: null,
                urgencyAt: null,
                actionableSince: $request->created_at?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval=suspension_request:'
                    .$request->getKey(),
            );
        })->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }
}
