<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PosRefund;
use App\Models\PosRefundLine;
use App\Models\PosTransaction;
use Illuminate\Validation\ValidationException;

class RetailPosRefundService
{
    private const FINAL_STATUSES = ['succeeded', 'failed', 'rejected', 'cancelled'];

    public function requestRefund(PosTransaction $source, array $payload, int $actorId): PosRefund
    {
        if ((string) $source->module_type !== 'retail') {
            throw ValidationException::withMessages([
                'source_transaction_id' => ['Only retail POS transactions can be refunded from this endpoint.'],
            ]);
        }

        $activeRequest = PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->latest('id')
            ->first();

        if ($activeRequest) {
            throw ValidationException::withMessages([
                'source_transaction_id' => ['A refund request is already in progress for this transaction.'],
            ]);
        }

        $requestedAmount = round((float) ($payload['requested_amount'] ?? 0), 2);
        if ($requestedAmount <= 0) {
            throw ValidationException::withMessages([
                'requested_amount' => ['Requested amount must be greater than zero.'],
            ]);
        }

        $alreadyRefunded = round((float) PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->whereIn('status', ['approved', 'processing', 'succeeded'])
            ->sum('approved_amount'), 2);

        $maxRefundable = max(0.0, round((float) $source->paid_amount - $alreadyRefunded, 2));
        if ($requestedAmount > $maxRefundable) {
            throw ValidationException::withMessages([
                'requested_amount' => ['Requested amount exceeds refundable balance.'],
            ]);
        }

        return PosRefund::create([
            'refund_no' => 'RFD-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'shop_owner_id' => (int) $source->shop_owner_id,
            'source_transaction_id' => (int) $source->id,
            'module_type' => 'retail',
            'module_reference_id' => (int) $source->module_reference_id,
            'workflow_source' => 'retail_pos',
            'request_type' => (string) ($payload['request_type'] ?? 'full'),
            'requested_amount' => $requestedAmount,
            'reason_code' => (string) ($payload['reason_code'] ?? 'retail_refund'),
            'reason_notes' => $payload['reason_notes'] ?? null,
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'requested_by' => $actorId > 0 ? $actorId : null,
            'requested_at' => now(),
        ]);
    }

    public function approve(PosRefund $refund, int $actorId, ?float $approvedAmount = null, ?string $approvalNote = null): PosRefund
    {
        if ((string) $refund->module_type !== 'retail') {
            throw ValidationException::withMessages([
                'module_type' => ['Only retail refunds can be approved from this service.'],
            ]);
        }

        if (!in_array((string) $refund->status, ['requested', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only requested or approved refunds can be approved.'],
            ]);
        }

        $source = $refund->sourceTransaction()->firstOrFail();
        $amountToApprove = round((float) ($approvedAmount ?? $refund->requested_amount), 2);

        $alreadyCommitted = round((float) PosRefund::query()
            ->where('id', '!=', $refund->id)
            ->where('source_transaction_id', $source->id)
            ->whereIn('status', ['approved', 'processing', 'succeeded'])
            ->sum('approved_amount'), 2);

        $maxRefundable = max(0.0, round((float) $source->paid_amount - $alreadyCommitted, 2));
        if ($amountToApprove <= 0 || $amountToApprove > $maxRefundable) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount exceeds refundable balance.'],
            ]);
        }

        $notes = trim((string) ($refund->reason_notes ?? ''));
        if ($approvalNote) {
            $notes = trim($notes . "\n\nApproval note: " . trim($approvalNote));
        }

        $refund->update([
            'status' => 'approved',
            'approved_amount' => $amountToApprove,
            'approved_by' => $actorId > 0 ? $actorId : null,
            'approved_at' => now(),
            'finance_status' => 'approved',
            'shop_owner_status' => 'skipped',
            'reason_notes' => $notes !== '' ? $notes : null,
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        return $refund->fresh();
    }

    public function execute(PosRefund $refund, int $actorId, string $executionMode = 'manual', ?string $executionNote = null): PosRefund
    {
        if ((string) $refund->module_type !== 'retail') {
            throw ValidationException::withMessages([
                'module_type' => ['Only retail refunds can be executed from this service.'],
            ]);
        }

        if ((string) $refund->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => ['Only approved refunds can be executed.'],
            ]);
        }

        $source = $refund->sourceTransaction()->with('paymentLines')->firstOrFail();
        $approvedAmount = round((float) ($refund->approved_amount ?? $refund->requested_amount), 2);

        if ($approvedAmount <= 0) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount must be greater than zero before execution.'],
            ]);
        }

        $firstPaidLine = $source->paymentLines->first();
        if ($firstPaidLine) {
            PosRefundLine::create([
                'pos_refund_id' => $refund->id,
                'source_payment_line_id' => $firstPaidLine->id,
                'refunded_amount' => $approvedAmount,
            ]);
        }

        $refund->update([
            'status' => 'succeeded',
            'executed_by' => $actorId > 0 ? $actorId : null,
            'executed_at' => now(),
            'execution_mode' => $executionMode,
            'execution_channel' => $executionMode === 'gateway' ? 'gateway' : 'manual',
            'execution_amount' => $approvedAmount,
            'execution_notes' => $executionNote,
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        $totalRefunded = round((float) PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->where('status', 'succeeded')
            ->sum('approved_amount'), 2);

        $source->update([
            'status' => $totalRefunded >= round((float) $source->paid_amount, 2)
                ? 'refunded'
                : 'partially_refunded',
        ]);

        if ((string) $source->status === 'refunded') {
            $order = Order::query()->with('items.product')->find((int) $source->module_reference_id);
            if ($order) {
                foreach ($order->items as $item) {
                    if ($item->product) {
                        $item->product->increment('stock_quantity', (int) ($item->quantity ?? 0));
                    }
                }

                $order->update([
                    'status' => 'cancelled',
                    'payment_status' => 'refunded',
                    'refunded_at' => now(),
                    'paymongo_refund_id' => (string) $refund->refund_no,
                ]);
            }
        }

        return $refund->fresh();
    }
}
