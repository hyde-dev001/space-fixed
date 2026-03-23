<?php

namespace App\Services;

use App\Models\Finance\Invoice;
use App\Models\Order;
use App\Models\RepairRequest;

class PaymentSettlementService
{
    public function settleOrderPaid(Order $order, ?string $paymentId = null): array
    {
        if ($this->isOrderSettled($order)) {
            return [
                'result' => 'already_settled',
                'model' => $order,
            ];
        }

        if ($this->isOrderExpired($order)) {
            return [
                'result' => 'expired',
                'model' => $order,
            ];
        }

        $order->update([
            'payment_status' => 'paid',
            'paymongo_payment_id' => $paymentId,
            'paid_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
        ]);

        if ($order->invoice_id) {
            $invoice = Invoice::find($order->invoice_id);
            if ($invoice && $invoice->status !== 'paid') {
                $invoice->update([
                    'status' => 'paid',
                    'payment_date' => now(),
                    'payment_method' => 'paymongo',
                ]);
            }
        }

        return [
            'result' => 'settled',
            'model' => $order->fresh(),
        ];
    }

    public function settleRepairPaid(RepairRequest $repair, ?string $paymentId = null, bool $ignoreExpiry = false): array
    {
        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

        if ($this->isRepairSettled($repair, $policy)) {
            return [
                'result' => 'already_settled',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        if (!$this->isRepairPaymentDueNow($repair, $policy)) {
            return [
                'result' => 'not_due',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        if (!$ignoreExpiry && $this->isRepairExpired($repair, $policy)) {
            return [
                'result' => 'expired',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        $repair->update([
            'paymongo_payment_id' => $paymentId,
            'payment_completed_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
        ]);

        if ($policy === 'full_upfront') {
            $repair->update(['payment_status' => 'completed']);
            if ($repair->is_high_value && $repair->requires_owner_approval) {
                $repair->update(['status' => 'owner_approval_pending']);
            } else {
                $repair->update(['status' => 'pending']);
            }

            return [
                'result' => 'settled',
                'model' => $repair->fresh(),
                'policy' => $policy,
                'phase' => 'full_upfront',
            ];
        }

        $isDepositPhase = in_array($repair->payment_status ?? 'pending', ['pending', null], true);
        $repair->update(['payment_status' => $isDepositPhase ? 'paid' : 'completed']);

        if ($isDepositPhase) {
            if ($repair->is_high_value && $repair->requires_owner_approval) {
                $repair->update(['status' => 'owner_approval_pending']);
            } else {
                $repair->update(['status' => 'pending']);
            }
        }

        return [
            'result' => 'settled',
            'model' => $repair->fresh(),
            'policy' => $policy,
            'phase' => $isDepositPhase ? 'deposit_50' : 'remaining_balance',
        ];
    }

    public function settleRepairPaidInShop(RepairRequest $repair, ?string $reference = null): array
    {
        $paymentReference = $reference ?? ('in_shop_' . now()->format('YmdHis'));

        return $this->settleRepairPaid($repair, $paymentReference, true);
    }

    public function recordOrderPaymentFailure(Order $order, string $reason): array
    {
        if ($this->isOrderSettled($order)) {
            return [
                'result' => 'already_settled',
                'model' => $order,
            ];
        }

        $payload = [
            'payment_failed_at' => now(),
            'payment_failure_reason' => $reason,
        ];

        if ($reason === 'paymongo_session_expired') {
            $payload['payment_expired_at'] = now();
        }

        $order->update($payload);

        return [
            'result' => 'recorded',
            'model' => $order->fresh(),
        ];
    }

    public function recordRepairPaymentFailure(RepairRequest $repair, string $reason): array
    {
        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');
        if ($this->isRepairSettled($repair, $policy)) {
            return [
                'result' => 'already_settled',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        $payload = [
            'payment_failed_at' => now(),
            'payment_failure_reason' => $reason,
        ];

        if ($reason === 'paymongo_session_expired') {
            $payload['payment_expired_at'] = now();
        }

        if (in_array((string) ($repair->payment_status ?? 'pending'), ['pending', 'failed', 'expired', ''], true)) {
            $payload['payment_status'] = $reason === 'paymongo_session_expired' ? 'expired' : 'failed';
        }

        $repair->update($payload);

        return [
            'result' => 'recorded',
            'model' => $repair->fresh(),
            'policy' => $policy,
        ];
    }

    public function isOrderExpired(Order $order): bool
    {
        return $order->payment_expired_at !== null
            || ($order->payment_expires_at !== null
                && now()->greaterThan($order->payment_expires_at)
                && in_array((string) ($order->payment_status ?? 'pending'), ['pending', 'failed', 'expired', ''], true));
    }

    public function isRepairExpired(RepairRequest $repair, ?string $policy = null): bool
    {
        $resolvedPolicy = $policy ?? $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');
        if (!$this->isRepairPaymentDueNow($repair, $resolvedPolicy)) {
            return false;
        }

        return $repair->payment_expired_at !== null
            || ($repair->payment_expires_at !== null && now()->greaterThan($repair->payment_expires_at));
    }

    public function isRepairPaymentDueNow(RepairRequest $repair, ?string $policy = null): bool
    {
        $resolvedPolicy = $policy ?? $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

        if ($this->isRepairSettled($repair, $resolvedPolicy)) {
            return false;
        }

        if ((string) $repair->status === 'cancelled') {
            return false;
        }

        $paymentStatus = (string) ($repair->payment_status ?? 'pending');

        if ($resolvedPolicy === 'full_upfront') {
            return in_array($paymentStatus, ['pending', 'failed', 'expired', ''], true);
        }

        if (in_array($paymentStatus, ['pending', 'failed', 'expired', ''], true)) {
            return true;
        }

        if ($paymentStatus === 'paid') {
            return $this->isRepairRemainingBalancePhase($repair);
        }

        return false;
    }

    public function isRepairSettled(RepairRequest $repair, ?string $policy = null): bool
    {
        $resolvedPolicy = $policy ?? $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

        return (string) $repair->payment_status === 'completed'
            || ($resolvedPolicy === 'full_upfront' && (string) $repair->payment_status === 'paid');
    }

    public function normalizeRepairPaymentPolicy(?string $policy): string
    {
        $normalized = strtolower(trim((string) $policy));

        return $normalized === 'deposit_50' ? 'deposit_50' : 'full_upfront';
    }

    private function isOrderSettled(Order $order): bool
    {
        return in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed', 'refunded'], true);
    }

    private function isRepairRemainingBalancePhase(RepairRequest $repair): bool
    {
        return in_array((string) $repair->status, ['ready_for_pickup', 'ready-for-pickup'], true);
    }
}
