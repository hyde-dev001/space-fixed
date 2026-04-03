<?php

namespace App\Services;

use App\Models\PosPaymentLine;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairPosPaymentService
{
    private const VAT_RATE_PERCENT = 12.0;

    public function checkout(RepairRequest $repair, array $payload, int $actorId): PosTransaction
    {
        $dueType = (string) $payload['due_type'];
        $policy = (string) ($repair->payment_policy_snapshot ?: $repair->payment_policy ?: 'deposit_50');
        $normalizedPolicy = $policy === 'full_upfront' ? 'full_upfront' : 'deposit_50';

        $allowedDueTypes = $normalizedPolicy === 'full_upfront'
            ? ['full']
            : ['deposit', 'balance'];

        if (!in_array($dueType, $allowedDueTypes, true)) {
            throw ValidationException::withMessages([
                'due_type' => ['Selected due type is not allowed for the current payment policy.'],
            ]);
        }

        $total = (float) ($repair->final_total ?? $repair->total ?? 0);

        $dueSubtotal = $normalizedPolicy === 'full_upfront'
            ? $total
            : round($total * 0.5, 2);

        $vatAmount = round($dueSubtotal * (self::VAT_RATE_PERCENT / 100), 2);
        $dueAmount = round($dueSubtotal + $vatAmount, 2);

        $paidAmount = collect($payload['payment_lines'])->sum(fn ($line) => (float) $line['amount']);

        if (round($paidAmount, 2) !== round($dueAmount, 2)) {
            throw ValidationException::withMessages([
                'payment_lines' => ['Paid amount must exactly match due amount.'],
            ]);
        }

        return DB::transaction(function () use ($repair, $payload, $actorId, $paidAmount, $dueAmount, $dueType, $dueSubtotal, $vatAmount) {
            $transaction = PosTransaction::create([
                'transaction_no' => 'POS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'shop_owner_id' => $repair->shop_owner_id,
                'module_type' => 'repair',
                'module_reference_id' => $repair->id,
                'customer_type' => (string) $payload['customer_type'],
                'customer_id' => $payload['customer_id'] ?? null,
                'walk_in_name' => $payload['walk_in_name'] ?? null,
                'walk_in_phone' => $payload['walk_in_phone'] ?? null,
                'walk_in_email' => $payload['walk_in_email'] ?? null,
                'due_type' => $dueType,
                'subtotal' => $dueSubtotal,
                'tax_amount' => $vatAmount,
                'discount_amount' => 0,
                'total_amount' => $dueAmount,
                'paid_amount' => $paidAmount,
                'status' => 'paid',
                'paid_at' => now(),
                'created_by' => $actorId,
                'metadata' => [
                    'vat_rate' => self::VAT_RATE_PERCENT,
                ],
            ]);

            foreach ($payload['payment_lines'] as $line) {
                PosPaymentLine::create([
                    'pos_transaction_id' => $transaction->id,
                    'tender_type' => $line['tender_type'],
                    'provider_reference' => $line['provider_reference'] ?? null,
                    'amount' => $line['amount'],
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
            }

            $totalPaid = (float) PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('status', 'paid')
                ->sum('paid_amount');

            $overallTotal = (float) ($repair->final_total ?? $repair->total ?? 0);
            $derivedStatus = $totalPaid <= 0
                ? 'unpaid'
                : ($totalPaid < $overallTotal ? 'partially_paid' : 'paid');

            $repair->update([
                'total_paid_amount' => $totalPaid,
                'payment_status_derived' => $derivedStatus,
                'latest_pos_transaction_id' => $transaction->id,
                'payment_policy_snapshot' => $repair->payment_policy_snapshot ?: $repair->payment_policy,
            ]);

            $transaction->load('paymentLines');
            app(RepairPosReceiptService::class)->issue($transaction);

            return $transaction->fresh(['paymentLines']);
        });
    }
}
