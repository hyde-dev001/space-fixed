<?php

namespace App\Services;

use App\Models\PosPaymentLine;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Support\Tax\VatInclusiveCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairPosPaymentService
{
    private const VAT_RATE_PERCENT = 12.0;

    public function checkout(RepairRequest $repair, array $payload, int $actorId): PosTransaction
    {
        $dueType = (string) $payload['due_type'];
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');
        $phaseLockKey = sprintf('repair:%d:%s', (int) $repair->id, strtolower($dueType));
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

        $totalInclusive = (float) ($repair->final_total ?? $repair->total ?? 0);

        $dueTotal = $normalizedPolicy === 'full_upfront'
            ? round($totalInclusive, 2)
            : round($totalInclusive * 0.5, 2);

        $breakdown = VatInclusiveCalculator::extract($dueTotal, self::VAT_RATE_PERCENT);
        $dueSubtotal = (float) $breakdown['net'];
        $vatAmount = (float) $breakdown['vat'];
        $dueAmount = (float) $breakdown['total'];

        $paidAmount = collect($payload['payment_lines'])->sum(fn ($line) => (float) $line['amount']);

        if (round($paidAmount, 2) !== round($dueAmount, 2)) {
            throw ValidationException::withMessages([
                'payment_lines' => ['Paid amount must exactly match due amount.'],
            ]);
        }

        if ($idempotencyKey !== '') {
            $replay = PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('due_type', $dueType)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($replay) {
                $replay->setAttribute('idempotency_replay', true);

                return $replay;
            }
        }

        $alreadySettled = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->where('due_type', $dueType)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->exists();

        if ($alreadySettled) {
            throw ValidationException::withMessages([
                'due_type' => ['PAYMENT_PHASE_ALREADY_SETTLED'],
            ]);
        }

        return DB::transaction(function () use ($repair, $payload, $actorId, $paidAmount, $dueAmount, $dueType, $dueSubtotal, $vatAmount, $normalizedPolicy) {
            $transaction = PosTransaction::create([
                'transaction_no' => 'POS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
                'phase_lock_key' => sprintf('repair:%d:%s', (int) $repair->id, strtolower((string) $dueType)),
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
                'created_by' => $actorId > 0 ? $actorId : null,
                'metadata' => [
                    'vat_rate' => self::VAT_RATE_PERCENT,
                    'tax_mode' => 'vat_inclusive',
                ],
            ]);

            foreach ($payload['payment_lines'] as $line) {
                PosPaymentLine::create([
                    'pos_transaction_id' => $transaction->id,
                    'tender_type' => $line['tender_type'],
                    'provider_reference' => $line['provider_reference'] ?? null,
                    'amount' => $line['amount'],
                    'status' => 'paid',
                    'verification_status' => 'verified',
                    'paid_at' => now(),
                ]);
            }

            $totalPaid = (float) PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('status', 'paid')
                ->sum('paid_amount');

            $paidDueTypes = PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('status', 'paid')
                ->pluck('due_type')
                ->map(fn ($value) => strtolower((string) $value))
                ->all();

            $hasDepositPayment = in_array('deposit', $paidDueTypes, true);
            $hasBalancePayment = in_array('balance', $paidDueTypes, true);
            $hasFullPayment = in_array('full', $paidDueTypes, true);

            $canonicalStatus = 'unpaid';
            if ($normalizedPolicy === 'full_upfront') {
                if ($hasFullPayment || $totalPaid > 0) {
                    $canonicalStatus = 'completed';
                }
            } else {
                if ($hasBalancePayment) {
                    $canonicalStatus = 'completed';
                } elseif ($hasDepositPayment || $totalPaid > 0) {
                    $canonicalStatus = 'paid';
                }
            }

            $repair->update([
                'payment_status' => $canonicalStatus,
                'total_paid_amount' => $totalPaid,
                'payment_status_derived' => $canonicalStatus,
                'latest_pos_transaction_id' => $transaction->id,
                'payment_policy_snapshot' => $repair->payment_policy_snapshot ?: $repair->payment_policy,
            ]);

            $transaction->load('paymentLines');
            app(RepairPosReceiptService::class)->issue($transaction);

            return $transaction->fresh(['paymentLines']);
        });
    }

    public function verifyPaymentLine(PosPaymentLine $line, array $payload, int $actorId): array
    {
        return DB::transaction(function () use ($line, $payload, $actorId) {
            $decision = (string) $payload['decision'];
            $mode = (string) $payload['mode'];

            $line->update([
                'status' => $decision === 'approve' ? 'paid' : 'failed',
                'verification_status' => $decision === 'approve' ? 'verified' : 'rejected',
                'paid_at' => $decision === 'approve' ? now() : null,
                'verified_at' => now(),
                'verified_by' => $actorId > 0 ? $actorId : null,
                'manual_fallback_used' => $mode === 'manual_fallback',
                'verification_mode' => $mode,
                'verification_note' => $payload['note'] ?? null,
            ]);

            $transaction = $line->transaction()->firstOrFail();
            $lineStatuses = $transaction->paymentLines()->pluck('status')->all();

            $nextStatus = in_array('failed', $lineStatuses, true)
                ? 'failed'
                : (count(array_unique($lineStatuses)) === 1 && in_array('paid', $lineStatuses, true) ? 'paid' : 'pending');

            $transaction->update([
                'status' => $nextStatus,
                'paid_at' => $nextStatus === 'paid' ? now() : null,
            ]);

            $repair = RepairRequest::query()->find((int) $transaction->module_reference_id);
            if ($repair) {
                $totalPaid = (float) PosTransaction::query()
                    ->where('module_type', 'repair')
                    ->where('module_reference_id', $repair->id)
                    ->where('status', 'paid')
                    ->sum('paid_amount');

                $paidDueTypes = PosTransaction::query()
                    ->where('module_type', 'repair')
                    ->where('module_reference_id', $repair->id)
                    ->where('status', 'paid')
                    ->pluck('due_type')
                    ->map(fn ($value) => strtolower((string) $value))
                    ->all();

                $policy = (string) ($repair->payment_policy_snapshot ?: $repair->payment_policy ?: 'deposit_50');
                $normalizedPolicy = $policy === 'full_upfront' ? 'full_upfront' : 'deposit_50';
                $hasDepositPayment = in_array('deposit', $paidDueTypes, true);
                $hasBalancePayment = in_array('balance', $paidDueTypes, true);
                $hasFullPayment = in_array('full', $paidDueTypes, true);

                $canonical = 'unpaid';
                if ($normalizedPolicy === 'full_upfront') {
                    if ($hasFullPayment || $totalPaid > 0) {
                        $canonical = 'completed';
                    }
                } else {
                    if ($hasBalancePayment) {
                        $canonical = 'completed';
                    } elseif ($hasDepositPayment || $totalPaid > 0) {
                        $canonical = 'paid';
                    }
                }

                $repair->update([
                    'total_paid_amount' => $totalPaid,
                    'payment_status' => $canonical,
                    'payment_status_derived' => $canonical,
                    'latest_pos_transaction_id' => $transaction->id,
                ]);
            }

            return [
                'payment_line' => $line->fresh(),
                'transaction' => $transaction->fresh(['paymentLines']),
            ];
        });
    }
}
