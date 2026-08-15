<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\ShopOwnerApprovalPolicyService;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

final class OrderRefundAttentionAdapter implements OwnerAttentionAdapter
{
    public function __construct(
        private readonly ShopOwnerApprovalPolicyService $approvalPolicy,
    ) {}

    public function adapterKey(): string
    {
        return 'order_refunds';
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
            ? ['pending', 'approved_initial']
            : ['approved_initial'];

        $baseQuery = OrderRefund::query()
            ->select([
                'id',
                'shop_owner_id',
                'order_id',
                'status',
                'flow_type',
                'shop_owner_status',
                'finance_status',
                'amount',
                'currency',
                'requested_at',
                'created_at',
            ])
            ->where('shop_owner_id', (int) $owner->getKey())
            ->whereHas('order', static function ($orderQuery) use ($owner): void {
                $orderQuery->where('shop_owner_id', (int) $owner->getKey());
            })
            ->where('flow_type', 'request_approval')
            ->whereIn('status', ['requested', 'pending_approval'])
            ->where('shop_owner_status', 'pending')
            ->whereIn('finance_status', $financeStatuses)
            ->when($rule['limit'] !== null, static function ($builder) use ($rule): void {
                $builder->where('amount', '>=', $rule['limit']);
            })
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $baseQuery)->count();
        $refunds = (clone $baseQuery)
            ->limit($query->candidateLimit)
            ->get();

        $items = [];
        foreach ($refunds as $refund) {
            $amount = (float) $refund->amount;
            if (! $this->approvalPolicy->requiresOwnerApprovalForRefundRule($rule, $amount)) {
                continue;
            }

            $actionableSince = $refund->requested_at ?? $refund->created_at;
            $items[] = new OwnerAttentionItem(
                sourceType: 'order_refund',
                sourceId: (int) $refund->getKey(),
                category: 'refund_approval',
                primaryBucket: $this->primaryBucket(),
                module: 'finance',
                title: 'Order refund approval',
                conciseSummary: 'Review the pending order refund request.',
                priorityTier: 'high',
                materialityTier: $this->materialityTier($amount),
                comparableMonetaryExposure: strtoupper((string) $refund->currency) === 'PHP'
                    ? round($amount, 2)
                    : null,
                urgencyAt: null,
                actionableSince: $actionableSince?->toISOString() ?? now()->toISOString(),
                waitingOn: 'shop_owner',
                ownerActionRequired: true,
                coverageSource: $this->coverageSource(),
                destinationUrl: route('shop-owner.refund-approvals', [], false)
                    .'?refund_type=order&refund='.$refund->getKey(),
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
