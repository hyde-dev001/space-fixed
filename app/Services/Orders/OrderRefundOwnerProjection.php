<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\OrderRefund;
use App\Models\PosRefund;

final class OrderRefundOwnerProjection
{
    /**
     * @return array{
     *     case_state: string,
     *     return_state: string,
     *     payout_state: string,
     *     waiting_on: string,
     *     owner_action_required: bool,
     *     next_action: ?string,
     *     material_failure_reason: ?string,
     * }
     */
    public function project(OrderRefund|PosRefund $refund): array
    {
        $caseState = $this->caseState($refund);
        $returnState = $this->returnState($refund);
        $payoutState = $this->payoutState($caseState);
        $waitingOn = $this->waitingOn($refund, $caseState, $returnState);
        $ownerActionRequired = $waitingOn === 'owner';

        return [
            'case_state' => $caseState,
            'return_state' => $returnState,
            'payout_state' => $payoutState,
            'waiting_on' => $waitingOn,
            'owner_action_required' => $ownerActionRequired,
            'next_action' => $ownerActionRequired ? 'review_refund' : null,
            'material_failure_reason' => $this->failureReason($refund, $caseState),
        ];
    }

    private function caseState(OrderRefund|PosRefund $refund): string
    {
        return match ($this->value($refund->getAttribute('status'))) {
            'requested' => 'requested',
            'pending', 'pending_approval', 'under_review' => 'under_review',
            'approved' => 'approved',
            'processing', 'in_progress' => 'processing',
            'succeeded', 'successful', 'completed', 'refunded' => 'succeeded',
            'failed' => 'failed',
            'rejected' => 'rejected',
            'cancelled', 'canceled' => 'cancelled',
            default => 'under_review',
        };
    }

    private function returnState(OrderRefund|PosRefund $refund): string
    {
        if (! $refund instanceof OrderRefund) {
            return 'not_required';
        }

        return match ($this->value($refund->getAttribute('return_status'))) {
            '', 'not_required', 'none', 'cancelled', 'canceled' => 'not_required',
            'awaiting_approval', 'pending_customer_shipment', 'pending_staff_pickup', 'awaiting_return' => 'awaiting_return',
            'in_transit', 'shipped' => 'in_transit',
            'received', 'returned' => 'received',
            default => 'exception',
        };
    }

    private function payoutState(string $caseState): string
    {
        return match ($caseState) {
            'requested' => 'not_started',
            'under_review', 'approved' => 'pending',
            'processing' => 'processing',
            'succeeded' => 'succeeded',
            'failed' => 'failed',
            default => 'not_started',
        };
    }

    private function waitingOn(OrderRefund|PosRefund $refund, string $caseState, string $returnState): string
    {
        if (in_array($caseState, ['succeeded', 'failed', 'rejected', 'cancelled'], true)) {
            return 'none';
        }

        $shopOwnerStatus = $this->value($refund->getAttribute('shop_owner_status'));

        if ($caseState === 'requested' || in_array($shopOwnerStatus, ['', 'pending', 'requested'], true)) {
            return 'owner';
        }

        if ($returnState === 'awaiting_return') {
            return $this->value($refund->getAttribute('return_status')) === 'pending_staff_pickup'
                ? 'staff'
                : 'customer';
        }

        if ($returnState === 'in_transit') {
            return 'logistics';
        }

        return 'finance';
    }

    private function failureReason(OrderRefund|PosRefund $refund, string $caseState): ?string
    {
        if (! in_array($caseState, ['failed', 'rejected'], true)) {
            return null;
        }

        $reason = $refund->getAttribute('failure_reason') ?? $refund->getAttribute('rejection_reason');

        return $reason === null ? null : (string) $reason;
    }

    private function value(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
