<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\HR\Payroll;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;

final class PayslipAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'payslips';
    }

    public function coverageSource(): string
    {
        return 'payslips';
    }

    public function primaryBucket(): string
    {
        return 'needs_my_decision';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $baseQuery = Payroll::query()
            ->select([
                'id',
                'shop_owner_id',
                'employee_id',
                'payroll_period',
                'gross_salary',
                'net_salary',
                'status',
                'approval_status',
                'approval_id',
                'approval_workflow_version',
                'created_at',
                'updated_at',
            ])
            ->with(['employee:id,first_name,last_name'])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->where('status', 'pending')
            ->where('approval_status', 'pending')
            ->where('approval_workflow_version', 'v4_multi_level')
            ->whereNotNull('approval_id')
            ->whereHas('approval', static function (Builder $approvalQuery) use ($owner): void {
                $approvalQuery
                    ->where('approvals.approvable_type', Payroll::class)
                    ->where('approvals.shop_owner_id', (int) $owner->getKey())
                    ->where('approvals.status', 'pending')
                    ->where('approvals.current_level', '>', 0)
                    ->where('approvals.current_approver_role', 'shop_owner');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $payslips = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = $payslips->map(function (Payroll $payslip): OwnerAttentionItem {
            $employeeName = trim(implode(' ', array_filter([
                $payslip->employee?->first_name,
                $payslip->employee?->last_name,
            ]))) ?: 'Employee';
            $amount = round((float) ($payslip->gross_salary ?: $payslip->net_salary), 2);

            return new OwnerAttentionItem(
                sourceType: 'payslip',
                sourceId: (int) $payslip->getKey(),
                category: 'payslip_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'payroll',
                title: 'Payslip approval',
                conciseSummary: "Review {$employeeName}'s payslip for {$payslip->payroll_period}.",
                priorityTier: 'high',
                materialityTier: $this->materialityTier($amount),
                comparableMonetaryExposure: $amount,
                urgencyAt: null,
                actionableSince: $payslip->updated_at?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval=payslip:'
                    .$payslip->getKey(),
            );
        })->all();

        return new OwnerAttentionAdapterResult($items, $qualifyingCount);
    }

    private function materialityTier(float $amount): string
    {
        return match (true) {
            $amount >= 100000 => 'high',
            $amount >= 10000 => 'medium',
            default => 'low',
        };
    }
}
