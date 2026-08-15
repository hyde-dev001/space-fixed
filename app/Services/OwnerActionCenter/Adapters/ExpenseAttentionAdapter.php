<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\Finance\Expense;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

final class ExpenseAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'expenses';
    }

    public function coverageSource(): string
    {
        return 'expenses';
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

        $baseQuery = Expense::query()
            ->select([
                'id',
                'shop_id',
                'approval_id',
                'reference',
                'category',
                'amount',
                'due_date',
                'status',
                'created_at',
            ])
            ->where('shop_id', (int) $owner->getKey())
            ->whereNull('procurement_receipt_id')
            ->where('status', 'submitted')
            ->whereNotNull('approval_id')
            ->whereHas('approval', static function ($approvalQuery) use ($owner): void {
                $approvalQuery
                    ->whereColumn('approvals.id', 'finance_expenses.approval_id')
                    ->where('approvals.approvable_type', Expense::class)
                    ->where('approvals.shop_owner_id', (int) $owner->getKey())
                    ->where('approvals.status', 'pending')
                    ->where('approvals.current_level', '>', 0)
                    ->where('approvals.current_approver_role', 'shop_owner');
            })
            ->orderByDesc('due_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $expenses = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $expenses->map(function (Expense $expense): OwnerAttentionItem {
            $amount = (float) $expense->amount;
            $dueDate = $expense->due_date;

            return new OwnerAttentionItem(
                sourceType: 'expense',
                sourceId: (int) $expense->getKey(),
                category: 'expense_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'finance',
                title: 'Expense approval',
                conciseSummary: 'Review the pending expense approval.',
                priorityTier: $dueDate?->isPast() ? 'critical' : 'high',
                materialityTier: $this->materialityTier($amount),
                comparableMonetaryExposure: round($amount, 2),
                urgencyAt: $dueDate?->copy()->startOfDay()->toISOString(),
                actionableSince: $expense->created_at?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: route('shop-owner.expense-approvals', [], false)
                    .'?expense='.$expense->getKey(),
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
