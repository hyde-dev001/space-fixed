<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\HR\SalaryChange;
use App\Models\ShopOwner;
use App\Models\User;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;

final class SalaryChangeAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'salary_changes';
    }

    public function coverageSource(): string
    {
        return 'salary_changes';
    }

    public function primaryBucket(): string
    {
        return 'needs_my_decision';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $baseQuery = SalaryChange::query()
            ->select([
                'id',
                'shop_owner_id',
                'employee_id',
                'proposed_by',
                'previous_salary',
                'new_salary',
                'change_percent',
                'change_type',
                'effective_date',
                'reason',
                'status',
                'requires_owner_approval',
                'created_at',
                'updated_at',
            ])
            ->with(['employee:id,first_name,last_name'])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->whereNotIn('proposed_by', User::query()
                ->select('id')
                ->where('shop_owner_id', (int) $owner->getKey())
                ->where('email', (string) $owner->email)
            )
            ->where('status', SalaryChange::STATUS_PENDING)
            ->where(function (Builder $snapshotQuery): void {
                $snapshotQuery
                    ->whereNull('requires_owner_approval')
                    ->orWhere('requires_owner_approval', true);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $changes = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $changes->map(function (SalaryChange $change): OwnerAttentionItem {
            $employeeName = trim(implode(' ', array_filter([
                $change->employee?->first_name,
                $change->employee?->last_name,
            ]))) ?: 'Employee';
            $amount = round(abs((float) $change->new_salary - (float) $change->previous_salary), 2);

            return new OwnerAttentionItem(
                sourceType: 'salary_change',
                sourceId: (int) $change->getKey(),
                category: 'salary_change_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'hr',
                title: 'Salary adjustment approval',
                conciseSummary: "Review {$employeeName}'s salary adjustment.",
                priorityTier: 'high',
                materialityTier: $this->materialityTier($amount),
                comparableMonetaryExposure: $amount,
                urgencyAt: $change->effective_date?->startOfDay()->toISOString(),
                actionableSince: $change->created_at?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval=salary_change:'
                    .$change->getKey(),
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
