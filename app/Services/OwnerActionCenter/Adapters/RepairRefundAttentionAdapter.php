<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PosRefund;
use App\Models\ShopOwner;
use App\Services\ShopOwnerApprovalPolicyService;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

final class RepairRefundAttentionAdapter implements OwnerAttentionAdapter
{
    public function __construct(
        private readonly ShopOwnerApprovalPolicyService $approvalPolicy,
    ) {}

    public function adapterKey(): string
    {
        return 'repair_refunds';
    }

    public function coverageSource(): string
    {
        return 'refunds';
    }

    public function primaryBucket(): string
    {
        return 'needs_my_decision';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $rule = $this->approvalPolicy->refundApprovalRuleForRead((int) $owner->getKey());
        if (! $rule['enabled']) {
            return new OwnerAttentionAdapterResult([], 0);
        }

        $registrationType = strtolower(trim((string) $owner->registration_type));
        $financeStatuses = $registrationType === 'individual'
            ? ['pending', 'approved_initial', 'approved']
            : ['approved_initial'];

        $baseQuery = PosRefund::query()
            ->select([
                'id',
                'shop_owner_id',
                'module_type',
                'status',
                'finance_status',
                'shop_owner_status',
                'requested_amount',
                'requested_at',
                'created_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->where('module_type', 'repair')
            ->where('status', 'requested')
            ->where('shop_owner_status', 'pending')
            ->whereIn('finance_status', $financeStatuses)
            ->when($rule['limit'] !== null, static function ($builder) use ($rule): void {
                $builder->where('requested_amount', '>=', $rule['limit']);
            })
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $refunds = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = [];
        foreach ($refunds as $refund) {
            $amount = (float) $refund->requested_amount;
            if (! $this->approvalPolicy->requiresOwnerApprovalForRefundRule($rule, $amount)) {
                continue;
            }

            $actionableSince = $refund->requested_at ?? $refund->created_at;
            $items[] = new OwnerAttentionItem(
                sourceType: 'repair_refund',
                sourceId: (int) $refund->getKey(),
                category: 'refund_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'finance',
                title: 'Repair refund approval',
                conciseSummary: 'Review the pending repair refund request.',
                priorityTier: 'high',
                materialityTier: $this->materialityTier($amount),
                comparableMonetaryExposure: round($amount, 2),
                urgencyAt: null,
                actionableSince: $actionableSince?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: route('shop-owner.refund-approvals', [], false)
                    .'?refund_type=repair&refund='.$refund->getKey(),
            );
        }

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
