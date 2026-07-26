<?php

namespace App\Services;

use App\Models\PosPaymentLine;
use App\Models\PosTransaction;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Support\Tax\VatInclusiveCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairPosPaymentService
{
    private const VAT_RATE_PERCENT = 12.0;

    public function __construct(
        private readonly PaymentSettlementService $paymentSettlementService,
    ) {}

    public function checkout(RepairRequest $repair, array $payload, int $actorId): PosTransaction
    {
        $dueType = (string) $payload['due_type'];
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');

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

        $phaseBreakdown = $this->paymentSettlementService->repairPaymentBreakdown($repair, $dueType);
        $serviceTax = VatInclusiveCalculator::extract((float) $phaseBreakdown['service_amount'], self::VAT_RATE_PERCENT);
        $dueSubtotal = round((float) $serviceTax['net'] + (float) $phaseBreakdown['delivery_amount'], 2);
        $vatAmount = (float) $serviceTax['vat'];
        $dueAmount = (float) $phaseBreakdown['total_amount'];

        $paidAmount = collect($payload['payment_lines'])->sum(fn ($line) => (float) $line['amount']);

        if (round($dueAmount, 2) <= 0 || round($paidAmount, 2) !== round($dueAmount, 2)) {
            throw ValidationException::withMessages([
                'payment_lines' => ['Paid amount must exactly match due amount.'],
            ]);
        }

        $alreadySettled = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->where('due_type', $dueType)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->exists()
            || RepairPaymentSession::query()
                ->where('repair_request_id', $repair->id)
                ->where('phase', $phaseBreakdown['phase'])
                ->whereIn('status', ['paid', 'reconciliation'])
                ->exists();

        if ($alreadySettled) {
            throw ValidationException::withMessages([
                'due_type' => ['PAYMENT_PHASE_ALREADY_SETTLED'],
            ]);
        }

        return DB::transaction(function () use ($repair, $payload, $actorId, $paidAmount, $dueType) {
            $lockedRepair = RepairRequest::query()->lockForUpdate()->findOrFail($repair->id);
            $phaseBreakdown = $this->paymentSettlementService->repairPaymentBreakdown($lockedRepair, $dueType);
            $serviceTax = VatInclusiveCalculator::extract((float) $phaseBreakdown['service_amount'], self::VAT_RATE_PERCENT);
            $dueSubtotal = round((float) $serviceTax['net'] + (float) $phaseBreakdown['delivery_amount'], 2);
            $vatAmount = (float) $serviceTax['vat'];
            $dueAmount = (float) $phaseBreakdown['total_amount'];

            if (round($dueAmount, 2) <= 0 || round($paidAmount, 2) !== round($dueAmount, 2)) {
                throw ValidationException::withMessages([
                    'payment_lines' => ['Paid amount must exactly match due amount.'],
                ]);
            }

            if (PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $lockedRepair->id)
                ->where('due_type', $dueType)
                ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                ->exists()
                || RepairPaymentSession::query()
                    ->where('repair_request_id', $lockedRepair->id)
                    ->where('phase', $phaseBreakdown['phase'])
                    ->whereIn('status', ['paid', 'reconciliation'])
                    ->exists()) {
                throw ValidationException::withMessages([
                    'due_type' => ['PAYMENT_PHASE_ALREADY_SETTLED'],
                ]);
            }

            $transaction = PosTransaction::create([
                'transaction_no' => 'POS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
                'phase_lock_key' => sprintf('repair:%d:%s', (int) $repair->id, strtolower((string) $dueType)),
                'shop_owner_id' => $lockedRepair->shop_owner_id,
                'module_type' => 'repair',
                'module_reference_id' => $lockedRepair->id,
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
                    'policy' => $phaseBreakdown['policy'],
                    'phase' => $phaseBreakdown['phase'],
                    'leg' => $phaseBreakdown['leg'],
                    'service_amount' => $phaseBreakdown['service_amount'],
                    'delivery_amount' => $phaseBreakdown['delivery_amount'],
                    'snapshot_version' => $phaseBreakdown['snapshot_version'],
                    'delivery_method' => $phaseBreakdown['delivery_method'],
                    'quote' => $phaseBreakdown['quote'],
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

            $this->paymentSettlementService->settleRepairPhasePaid($lockedRepair, $phaseBreakdown);
            RepairPaymentSession::query()
                ->where('repair_request_id', $lockedRepair->id)
                ->where('phase', $phaseBreakdown['phase'])
                ->where('status', 'pending')
                ->update([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                ]);
            $lockedRepair->update([
                'latest_pos_transaction_id' => $transaction->id,
                'payment_policy_snapshot' => $lockedRepair->payment_policy_snapshot ?: $lockedRepair->payment_policy,
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
                $storedPaidAmount = round((float) ($repair->total_paid_amount ?? 0), 2);

                $posPaidAmountBefore = (float) PosTransaction::query()
                    ->where('module_type', 'repair')
                    ->where('module_reference_id', $repair->id)
                    ->where('status', 'paid')
                    ->where('id', '!=', $transaction->id)
                    ->sum('paid_amount');

                $posPaidAmountAfter = (float) PosTransaction::query()
                    ->where('module_type', 'repair')
                    ->where('module_reference_id', $repair->id)
                    ->where('status', 'paid')
                    ->sum('paid_amount');

                $nonPosPaidCarry = max(0.0, round($storedPaidAmount - $posPaidAmountBefore, 2));
                $totalPaid = round($posPaidAmountAfter + $nonPosPaidCarry, 2);

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
