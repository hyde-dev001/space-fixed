<?php

namespace App\Services;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use Illuminate\Validation\ValidationException;

class RepairPosRefundService
{
    public function requestRefund(PosTransaction $source, array $payload, int $actorId): PosRefund
    {
        $alreadyRefunded = (float) PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->whereIn('status', ['approved', 'processing', 'succeeded'])
            ->sum('approved_amount');

        $requested = (float) $payload['requested_amount'];
        $maxRefundable = max(0, (float) $source->paid_amount - $alreadyRefunded);

        if ($requested > $maxRefundable) {
            throw ValidationException::withMessages([
                'requested_amount' => ['Requested amount exceeds refundable balance.'],
            ]);
        }

        return PosRefund::create([
            'refund_no' => 'RFD-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'shop_owner_id' => $source->shop_owner_id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $source->module_reference_id,
            'request_type' => $payload['request_type'],
            'requested_amount' => $requested,
            'reason_code' => $payload['reason_code'],
            'reason_notes' => $payload['reason_notes'] ?? null,
            'status' => 'requested',
            'requested_by' => $actorId,
            'requested_at' => now(),
        ]);
    }

    public function execute(PosRefund $refund, int $actorId): PosRefund
    {
        $approvedAmount = (float) ($refund->approved_amount ?? $refund->requested_amount);

        $refund->update([
            'status' => 'succeeded',
            'approved_amount' => $approvedAmount,
            'executed_by' => $actorId,
            'executed_at' => now(),
        ]);

        $source = $refund->sourceTransaction()->firstOrFail();

        $totalRefundedForTransaction = (float) PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->where('status', 'succeeded')
            ->sum('approved_amount');

        $sourceStatus = $totalRefundedForTransaction >= (float) $source->paid_amount
            ? 'refunded'
            : 'partially_refunded';

        $source->update(['status' => $sourceStatus]);

        $repair = RepairRequest::query()->find((int) $source->module_reference_id);
        if ($repair) {
            $totalRefundedForRepair = (float) PosRefund::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('status', 'succeeded')
                ->sum('approved_amount');

            $repair->update([
                'total_refunded_amount' => $totalRefundedForRepair,
                'payment_status_derived' => $sourceStatus === 'refunded' ? 'refunded' : 'partially_refunded',
            ]);
        }

        return $refund->fresh();
    }
}
