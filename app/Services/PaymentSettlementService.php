<?php

namespace App\Services;

use App\Models\Finance\Invoice;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\Log;

class PaymentSettlementService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

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
            $repair->update([
                'payment_status' => 'completed',
                'status' => 'pending',  // Always proceed to pending after payment
            ]);

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
            // After deposit payment, always proceed to pending status
            // High-value approval is for rejection decisions only, not payment workflow
            $repair->update(['status' => 'pending']);
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

    public function syncRepairDerivedPaymentStatusFromPos(RepairRequest $repair): RepairRequest
    {
        $total = (float) ($repair->final_total ?? $repair->total ?? 0);
        $paid = (float) ($repair->total_paid_amount ?? 0);
        $refunded = (float) ($repair->total_refunded_amount ?? 0);

        $derived = 'unpaid';
        if ($refunded > 0) {
            $derived = $refunded >= max($paid, 0.0) ? 'refunded' : 'partially_refunded';
        } elseif ($paid > 0) {
            $derived = $paid >= $total ? 'paid' : 'partially_paid';
        }

        $repair->update([
            'payment_status_derived' => $derived,
        ]);

        return $repair->fresh();
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

    public function settleOrderRefunded(Order $order, ?string $refundId = null, ?string $reason = null, ?string $note = null): array
    {
        if ((string) ($order->payment_status ?? 'pending') === 'refunded') {
            if ($order->invoice_id) {
                $invoice = Invoice::find($order->invoice_id);
                if ($invoice && (string) $invoice->status !== 'cancelled') {
                    $invoice->update([
                        'status' => 'cancelled',
                        'payment_method' => $invoice->payment_method ?? 'paymongo_refund',
                    ]);
                }
            }

            return [
                'result' => 'already_refunded',
                'model' => $order,
            ];
        }

        $payload = [
            'payment_status' => 'refunded',
            'refunded_at' => now(),
            'payment_released_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
        ];

        if ($refundId) {
            $payload['paymongo_refund_id'] = $refundId;
        }

        if ($reason !== null) {
            $payload['refund_reason'] = $reason;
        }

        if ($note !== null) {
            $payload['refund_note'] = $note;
        }

        $order->update($payload);

        if ($order->invoice_id) {
            $invoice = Invoice::find($order->invoice_id);
            if ($invoice) {
                $invoice->update([
                    'status' => 'cancelled',
                    'payment_method' => $invoice->payment_method ?? 'paymongo_refund',
                ]);
            }
        }

        try {
            if ((int) ($order->customer_id ?? 0) > 0) {
                $this->notificationService->sendToUser(
                    userId: (int) $order->customer_id,
                    type: NotificationType::ORDER_STATUS_UPDATE,
                    title: 'Refund Completed',
                    message: "Your refund for order #{$order->order_number} has been completed and returned to your original payment method.",
                    data: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'refund_id' => $payload['paymongo_refund_id'] ?? null,
                        'refunded_at' => now()->toDateTimeString(),
                    ],
                    actionUrl: '/my-orders?tab=cancelled&highlightOrder=' . $order->id,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send refund completed notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'result' => 'refunded',
            'model' => $order->fresh(),
        ];
    }

    public function recordOrderRefundFailure(Order $order, string $reason): array
    {
        $order->update([
            'payment_failed_at' => now(),
            'payment_failure_reason' => $reason,
        ]);

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

        if (in_array($paymentStatus, ['paid', 'partially_paid'], true)) {
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
