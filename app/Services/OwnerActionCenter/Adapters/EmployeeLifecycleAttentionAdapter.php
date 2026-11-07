<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\EmployeeLifecycleRequest;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

abstract class EmployeeLifecycleAttentionAdapter implements OwnerAttentionAdapter
{
    abstract protected function requestType(): string;

    abstract protected function sourceType(): string;

    abstract protected function adapterKeyValue(): string;

    abstract protected function coverageSourceValue(): string;

    public function adapterKey(): string
    {
        return $this->adapterKeyValue();
    }

    public function coverageSource(): string
    {
        return $this->coverageSourceValue();
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

        $baseQuery = EmployeeLifecycleRequest::query()
            ->select([
                'id',
                'employee_id',
                'requested_by',
                'reason',
                'status',
                'manager_status',
                'created_at',
            ])
            ->with(['employee:id,shop_owner_id,first_name,last_name,name,email,position'])
            ->where('request_type', $this->requestType())
            ->where('status', 'pending_owner')
            ->where('manager_status', 'approved')
            ->whereHas('employee', function ($employeeQuery) use ($owner): void {
                $employeeQuery->where('shop_owner_id', $owner->getKey());
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $requests = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $requests->map(function (EmployeeLifecycleRequest $request): OwnerAttentionItem {
            $employeeName = trim((string) ($request->employee?->name ?: implode(' ', array_filter([
                $request->employee?->first_name,
                $request->employee?->last_name,
            ])))) ?: 'Employee';

            return new OwnerAttentionItem(
                sourceType: $this->sourceType(),
                sourceId: (int) $request->getKey(),
                category: $this->requestType().'_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'hr',
                title: 'Employee '.ucfirst($this->requestType()).' approval',
                conciseSummary: 'Review '.$employeeName.'\'s '.$this->requestType().' request.',
                priorityTier: 'high',
                materialityTier: 'none',
                comparableMonetaryExposure: null,
                urgencyAt: null,
                actionableSince: $request->created_at?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval='
                    .$this->sourceType().':'.$request->getKey(),
            );
        })->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }
}
