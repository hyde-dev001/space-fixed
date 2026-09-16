<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\SupplierAdjustment;
use App\Models\SupplierPaymentAttempt;
use App\Models\User;
use App\Support\Finance\FinanceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExpenseSettlementService
{
    public const PAYMENT_METHODS = [
        'cash', 'bank_transfer', 'check', 'gcash', 'maya', 'paypal', 'other',
    ];

    /**
     * Append one settlement and return the derived expense state.
     *
     * Settlements are cash facts, independent from approval state. The
     * allowPending flag is used only for a paid-now expense created in the
     * same transaction as its initial settlement.
     *
     * @return array{settlement: ExpenseSettlement, expense: array, replayed: bool}
     */
    public function record(Expense $expense, User|ShopOwner $actor, array $data, bool $allowPending = false): array
    {
        $shopId = $actor instanceof ShopOwner
            ? (int) $actor->getKey()
            : (int) ($actor->shop_owner_id ?? 0);
        if ($shopId <= 0) {
            throw new FinanceDomainException('A Finance shop context is required.', 'TENANT_CONTEXT_REQUIRED', 403);
        }

        $amount = $this->normalizeAmount($data['amount'] ?? null);
        $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? '')));
        $reference = trim((string) ($data['reference'] ?? ''));
        $source = strtolower(trim((string) ($data['source'] ?? ExpenseSettlement::SOURCE_MANUAL)));
        $sourceReference = trim((string) ($data['source_reference'] ?? ''));
        $paidAtInput = $data['paid_at'] ?? null;
        $paidAt = $paidAtInput !== null && $paidAtInput !== ''
            ? CarbonImmutable::parse($paidAtInput)->toDateTimeString()
            : null;
        $idempotencyKey = $this->resolveRequestKey($data['idempotency_key'] ?? null);

        if (! in_array($source, [
            ExpenseSettlement::SOURCE_MANUAL,
            ExpenseSettlement::SOURCE_PROCUREMENT,
            ExpenseSettlement::SOURCE_PAYROLL,
            ExpenseSettlement::SOURCE_LEGACY_MIGRATION,
            ExpenseSettlement::SOURCE_SUPPLIER_MANUAL_PAYMENT,
            ExpenseSettlement::SOURCE_SUPPLIER_XENDIT_PAYOUT,
        ], true)) {
            throw new FinanceDomainException('Settlement source is not supported.', 'INVALID_STATE', 422);
        }
        $allowedPaymentMethods = match ($source) {
            ExpenseSettlement::SOURCE_SUPPLIER_MANUAL_PAYMENT => SupplierPaymentAttempt::MANUAL_PAYMENT_METHODS,
            ExpenseSettlement::SOURCE_SUPPLIER_XENDIT_PAYOUT => [SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT],
            default => self::PAYMENT_METHODS,
        };
        if (! in_array($paymentMethod, $allowedPaymentMethods, true)) {
            throw new FinanceDomainException('Payment method is not supported.', 'INVALID_STATE', 422);
        }
        if ($source !== ExpenseSettlement::SOURCE_MANUAL && $sourceReference === '') {
            throw new FinanceDomainException('An integration settlement requires a source reference.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($expense, $actor, $shopId, $amount, $paymentMethod, $reference, $source, $sourceReference, $paidAt, $idempotencyKey, $allowPending): array {
            $lockedExpense = Expense::query()
                ->whereKey($expense->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedExpense) {
                throw (new ModelNotFoundException())->setModel(Expense::class, [$expense->getKey()]);
            }

            if ((int) $lockedExpense->shop_id !== $shopId) {
                throw new FinanceDomainException('The expense is not available in this shop.', 'FORBIDDEN', 403);
            }

            $existing = ExpenseSettlement::query()
                ->where('shop_owner_id', $shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->where('entry_type', ExpenseSettlement::ENTRY_SETTLEMENT)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $this->sameRequest($existing, $lockedExpense, $amount, $paymentMethod, $reference, $source, $sourceReference, $paidAt)) {
                    throw new FinanceDomainException(
                        'The settlement request key was already used with different payment details.',
                        'DUPLICATE_SUBMISSION',
                        409,
                    );
                }

                return [
                    'settlement' => $existing->fresh(),
                    'expense' => $this->state($lockedExpense, $shopId),
                    'replayed' => true,
                ];
            }

            if (! $allowPending && ! in_array((string) $lockedExpense->status, ['approved', 'posted'], true)) {
                throw new FinanceDomainException(
                    'Only approved or posted expenses can receive a settlement.',
                    'INVALID_STATE',
                    422,
                );
            }

            $totalCents = $this->toCents($lockedExpense->amount);
            $settledCents = $this->toCents(ExpenseSettlement::validSettledAmountForExpense((int) $lockedExpense->id));
            if ($settledCents > $totalCents) {
                throw new FinanceDomainException(
                    'This expense has an overpayment integrity warning and requires reconciliation.',
                    'INTEGRITY_WARNING',
                    409,
                );
            }

            $amountCents = $this->toCents($amount);
            if ($amountCents <= 0) {
                throw new FinanceDomainException('Settlement amount must be greater than zero.', 'INVALID_STATE', 422);
            }

            if ($settledCents + $amountCents > $totalCents) {
                throw new FinanceDomainException(
                    'Settlement amount exceeds the expense balance.',
                    'AMOUNT_EXCEEDS_BALANCE',
                    422,
                );
            }

            $settlement = ExpenseSettlement::create([
                'shop_owner_id' => $shopId,
                'expense_id' => $lockedExpense->id,
                'entry_type' => ExpenseSettlement::ENTRY_SETTLEMENT,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference !== '' ? $reference : null,
                'paid_at' => $paidAt ?? now()->toDateTimeString(),
                'recorded_by_user_id' => $actor instanceof User ? $actor->id : null,
                'idempotency_key' => $idempotencyKey,
                'source' => $source,
                'source_reference' => $sourceReference !== '' ? $sourceReference : null,
            ]);

            return [
                'settlement' => $settlement->fresh(),
                'expense' => $this->state($lockedExpense, $shopId),
                'replayed' => false,
            ];
        }, 3);
    }

    /**
     * Append one confirmed supplier refund without changing the original
     * supplier-payment balance.
     *
     * @return array{settlement: ExpenseSettlement, expense: array, replayed: bool}
     */
    public function recordSupplierRefund(SupplierAdjustment $adjustment, User $actor, array $data): array
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        if ($shopId <= 0) {
            throw new FinanceDomainException('A Finance shop context is required.', 'TENANT_CONTEXT_REQUIRED', 403);
        }

        $amount = $this->normalizeAmount($data['amount'] ?? null);
        $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? '')));
        $reference = trim((string) ($data['external_transaction_reference'] ?? ''));
        $paidAtInput = $data['received_at'] ?? null;
        $paidAt = $paidAtInput !== null && $paidAtInput !== ''
            ? CarbonImmutable::parse($paidAtInput)->toDateTimeString()
            : null;
        $idempotencyKey = $this->resolveRequestKey($data['idempotency_key'] ?? null);

        if (! in_array($paymentMethod, SupplierPaymentAttempt::MANUAL_PAYMENT_METHODS, true)) {
            throw new FinanceDomainException('Payment method is not supported.', 'INVALID_STATE', 422);
        }
        if ($reference === '' || $paidAt === null) {
            throw new FinanceDomainException('Supplier refund reference and received date are required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($adjustment, $actor, $shopId, $amount, $paymentMethod, $reference, $paidAt, $idempotencyKey): array {
            $candidate = SupplierAdjustment::query()->whereKey($adjustment->getKey())->firstOrFail();
            if ((int) $candidate->shop_owner_id !== $shopId) {
                throw new FinanceDomainException('The supplier adjustment is not available in this shop.', 'FORBIDDEN', 403);
            }

            $receiptItem = $candidate->receiptItem()->firstOrFail();
            $receipt = PurchaseOrderReceipt::query()->whereKey($receiptItem->purchase_order_receipt_id)->lockForUpdate()->firstOrFail();
            $expense = Expense::query()
                ->where('shop_id', $shopId)
                ->where('procurement_receipt_id', $receipt->id)
                ->lockForUpdate()
                ->first();
            if (! $expense) {
                throw new FinanceDomainException('The supplier adjustment has no procurement expense.', 'INVALID_STATE', 422);
            }

            $lockedAdjustment = SupplierAdjustment::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            $sourceReference = 'supplier-refund:' . $shopId . ':' . $reference;
            $existing = ExpenseSettlement::query()
                ->where('shop_owner_id', $shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->where('entry_type', ExpenseSettlement::ENTRY_SUPPLIER_REFUND)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $this->sameRefundRequest($existing, $lockedAdjustment, $amount, $paymentMethod, $reference, $paidAt)) {
                    throw new FinanceDomainException(
                        'The supplier refund request key was already used with different refund details.',
                        'DUPLICATE_SUBMISSION',
                        409,
                    );
                }

                return [
                    'settlement' => $existing->fresh(),
                    'expense' => $this->state($expense, $shopId),
                    'replayed' => true,
                ];
            }

            if ((string) $lockedAdjustment->issue_stage !== SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT
                || (string) $lockedAdjustment->resolution !== SupplierAdjustment::RESOLUTION_REFUND
                || (string) $lockedAdjustment->status === SupplierAdjustment::STATUS_RESOLVED) {
                throw new FinanceDomainException('Only an unresolved post-payment refund adjustment can receive a refund.', 'INVALID_STATE', 422);
            }
            if ((string) $receipt->status !== 'posted' || $receipt->voided_at !== null
                || (string) $expense->status !== 'posted') {
                throw new FinanceDomainException('The original procurement payment is not available for refund confirmation.', 'INVALID_STATE', 422);
            }
            if ($lockedAdjustment->getMedia('supplier_refund_proof')->isEmpty()
                || $lockedAdjustment->getMedia('finance_confirmation_proof')->isEmpty()) {
                throw new FinanceDomainException('Supplier proof and Finance confirmation proof are required.', 'INVALID_STATE', 422);
            }

            $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
            if ((int) $purchaseOrder->shop_owner_id !== $shopId
                || (int) $receipt->shop_owner_id !== $shopId) {
                throw new FinanceDomainException('The supplier adjustment is not available in this shop.', 'FORBIDDEN', 403);
            }

            $paidCents = $this->toCents(ExpenseSettlement::validSettledAmountForExpense((int) $expense->id));
            $expenseCents = $this->toCents($expense->amount);
            if ($paidCents < $expenseCents) {
                throw new FinanceDomainException('A supplier refund requires a confirmed paid procurement expense.', 'INVALID_STATE', 422);
            }

            $successfulAttempt = SupplierPaymentAttempt::query()
                ->where('shop_owner_id', $shopId)
                ->where('expense_id', $expense->id)
                ->where('status', SupplierPaymentAttempt::STATUS_SUCCEEDED)
                ->whereNotNull('settlement_id')
                ->lockForUpdate()
                ->first();
            if (! $successfulAttempt) {
                throw new FinanceDomainException('A confirmed supplier payment is required before a refund.', 'INVALID_STATE', 422);
            }

            $expectedCents = $this->toCents($lockedAdjustment->expected_refund_amount);
            $maximumCents = $this->toCents($lockedAdjustment->unit_cost_snapshot) * (int) $lockedAdjustment->reported_quantity;
            $amountCents = $this->toCents($amount);
            if ($expectedCents <= 0 || $expectedCents > $maximumCents || $expectedCents > $paidCents) {
                throw new FinanceDomainException('The expected refund amount is outside the paid adjustment value.', 'INVALID_STATE', 422);
            }
            if ($amountCents <= 0) {
                throw new FinanceDomainException('Refund amount must be greater than zero.', 'INVALID_STATE', 422);
            }

            $refundedCents = $this->toCents(ExpenseSettlement::validRefundedAmountForAdjustment((int) $lockedAdjustment->id));
            if ($refundedCents + $amountCents > $expectedCents) {
                throw new FinanceDomainException('The supplier refund exceeds the expected refund amount.', 'AMOUNT_EXCEEDS_BALANCE', 422);
            }
            if (ExpenseSettlement::query()
                ->where('shop_owner_id', $shopId)
                ->where('source', ExpenseSettlement::SOURCE_SUPPLIER_REFUND)
                ->where('source_reference', $sourceReference)
                ->exists()) {
                throw new FinanceDomainException('That supplier refund reference is already in use.', 'DUPLICATE_SUBMISSION', 409);
            }

            $settlement = ExpenseSettlement::create([
                'shop_owner_id' => $shopId,
                'expense_id' => $expense->id,
                'entry_type' => ExpenseSettlement::ENTRY_SUPPLIER_REFUND,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'paid_at' => $paidAt,
                'recorded_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source' => ExpenseSettlement::SOURCE_SUPPLIER_REFUND,
                'source_reference' => $sourceReference,
                'supplier_adjustment_id' => $lockedAdjustment->id,
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
            ]);

            return [
                'settlement' => $settlement->fresh(),
                'expense' => $this->state($expense, $shopId),
                'replayed' => false,
            ];
        }, 3);
    }

    public function reverse(ExpenseSettlement $settlement, User $actor, string $reason): ExpenseSettlement
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceDomainException('A reversal reason is required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($settlement, $actor, $shopId, $reason): ExpenseSettlement {
            $lockedSettlement = ExpenseSettlement::query()
                ->whereKey($settlement->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedSettlement) {
                throw (new ModelNotFoundException())->setModel(ExpenseSettlement::class, [$settlement->getKey()]);
            }

            $expense = Expense::query()->whereKey($lockedSettlement->expense_id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedSettlement->shop_owner_id !== $shopId || (int) $expense->shop_id !== $shopId) {
                throw new FinanceDomainException('The settlement is not available in this shop.', 'FORBIDDEN', 403);
            }

            if ((string) $lockedSettlement->entry_type !== ExpenseSettlement::ENTRY_SETTLEMENT) {
                throw new FinanceDomainException('Only an original settlement can be reversed.', 'INVALID_STATE', 422);
            }
            if ((string) $lockedSettlement->source === ExpenseSettlement::SOURCE_SUPPLIER_MANUAL_PAYMENT) {
                throw new FinanceDomainException(
                    'Supplier payments must be handled through the supplier payment workflow.',
                    'INVALID_STATE',
                    422,
                );
            }

            if (ExpenseSettlement::query()->where('reverses_settlement_id', $lockedSettlement->id)->exists()) {
                throw new FinanceDomainException('This settlement has already been reversed.', 'ALREADY_REVERSED', 409);
            }

            return ExpenseSettlement::create([
                'shop_owner_id' => $shopId,
                'expense_id' => $expense->id,
                'entry_type' => ExpenseSettlement::ENTRY_REVERSAL,
                'amount' => $lockedSettlement->amount,
                'payment_method' => $lockedSettlement->payment_method,
                'reference' => $lockedSettlement->reference,
                'paid_at' => now(),
                'recorded_by_user_id' => $actor->id,
                'reverses_settlement_id' => $lockedSettlement->id,
                'reversal_reason' => $reason,
                'source' => ExpenseSettlement::SOURCE_MANUAL,
            ]);
        }, 3);
    }

    public function reverseXenditPayout(ExpenseSettlement $settlement, ShopOwner $actor, string $reason): ExpenseSettlement
    {
        $shopId = (int) $actor->getKey();
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceDomainException('A reversal reason is required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($settlement, $actor, $shopId, $reason): ExpenseSettlement {
            $lockedSettlement = ExpenseSettlement::query()
                ->whereKey($settlement->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $expense = Expense::query()->whereKey($lockedSettlement->expense_id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedSettlement->shop_owner_id !== $shopId
                || (int) $expense->shop_id !== $shopId) {
                throw new FinanceDomainException('The settlement is not available in this shop.', 'FORBIDDEN', 403);
            }
            if ((string) $lockedSettlement->entry_type !== ExpenseSettlement::ENTRY_SETTLEMENT
                || (string) $lockedSettlement->source !== ExpenseSettlement::SOURCE_SUPPLIER_XENDIT_PAYOUT) {
                throw new FinanceDomainException('Only a Xendit supplier payout can be reversed here.', 'INVALID_STATE', 422);
            }

            $existing = ExpenseSettlement::query()
                ->where('reverses_settlement_id', $lockedSettlement->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing->fresh();
            }

            return ExpenseSettlement::create([
                'shop_owner_id' => $shopId,
                'expense_id' => $expense->id,
                'entry_type' => ExpenseSettlement::ENTRY_REVERSAL,
                'amount' => $lockedSettlement->amount,
                'payment_method' => $lockedSettlement->payment_method,
                'reference' => $lockedSettlement->reference,
                'paid_at' => now(),
                'recorded_by_user_id' => null,
                'idempotency_key' => 'supplier-xendit-reversal:' . $lockedSettlement->id,
                'reverses_settlement_id' => $lockedSettlement->id,
                'reversal_reason' => $reason,
                'source' => ExpenseSettlement::SOURCE_SUPPLIER_XENDIT_REVERSAL,
                'source_reference' => 'supplier-xendit-reversal:' . $lockedSettlement->id,
            ])->fresh();
        }, 3);
    }

    public function state(Expense $expense, ?int $shopId = null): array
    {
        $shopId ??= (int) $expense->shop_id;
        $settlements = ExpenseSettlement::query()
            ->where('shop_owner_id', $shopId)
            ->where('expense_id', $expense->id)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $settledCents = 0;
        $refundedCents = 0;
        foreach ($settlements as $settlement) {
            if ((string) $settlement->entry_type === ExpenseSettlement::ENTRY_SETTLEMENT) {
                $settledCents += $this->toCents($settlement->amount);
            } elseif ((string) $settlement->entry_type === ExpenseSettlement::ENTRY_REVERSAL
                && $settlement->reverses_settlement_id) {
                $settledCents -= $this->toCents($settlement->amount);
            } elseif ((string) $settlement->entry_type === ExpenseSettlement::ENTRY_SUPPLIER_REFUND) {
                $refundedCents += $this->toCents($settlement->amount);
            }
        }

        $totalCents = $this->toCents($expense->amount);
        $warnings = [];
        if ($settledCents > $totalCents) {
            $warnings[] = 'overpayment_detected';
        }
        if ((string) $expense->status === 'rejected' && $settledCents > 0) {
            $warnings[] = 'paid_rejected_expense';
        }

        $status = $settledCents <= 0
            ? 'unpaid'
            : ($settledCents < $totalCents ? 'partially_paid' : 'paid');

        return [
            'id' => (int) $expense->id,
            'approval_status' => (string) $expense->status,
            'paid_amount' => $this->fromCents(max(0, $settledCents)),
            'outstanding_balance' => $this->fromCents(max(0, $totalCents - $settledCents)),
            'refunded_amount' => $this->fromCents(max(0, $refundedCents)),
            'status' => $status,
            'integrity_warnings' => array_values(array_unique($warnings)),
            'settlements' => $settlements->map(fn (ExpenseSettlement $entry): array => [
                'id' => (int) $entry->id,
                'entry_type' => (string) $entry->entry_type,
                'amount' => (string) $entry->amount,
                'payment_method' => (string) $entry->payment_method,
                'reference' => $entry->reference,
                'paid_at' => optional($entry->paid_at)->toISOString(),
                'source' => (string) $entry->source,
                'source_reference' => $entry->source_reference,
                'reverses_settlement_id' => $entry->reverses_settlement_id,
                'reversal_reason' => $entry->reversal_reason,
                'supplier_adjustment_id' => $entry->supplier_adjustment_id,
                'notes' => $entry->notes,
            ])->values()->all(),
        ];
    }

    private function sameRequest(ExpenseSettlement $existing, Expense $expense, string $amount, string $method, string $reference, string $source, string $sourceReference, ?string $paidAt): bool
    {
        return (int) $existing->expense_id === (int) $expense->id
            && $this->toCents($existing->amount) === $this->toCents($amount)
            && strtolower((string) $existing->payment_method) === $method
            && (string) ($existing->reference ?? '') === ($reference !== '' ? $reference : '')
            && (string) $existing->source === $source
            && (string) ($existing->source_reference ?? '') === $sourceReference
            && ($paidAt === null || optional($existing->paid_at)->toDateTimeString() === $paidAt);
    }

    private function sameRefundRequest(ExpenseSettlement $existing, SupplierAdjustment $adjustment, string $amount, string $method, string $reference, string $paidAt): bool
    {
        return (int) $existing->supplier_adjustment_id === (int) $adjustment->id
            && $this->toCents($existing->amount) === $this->toCents($amount)
            && strtolower((string) $existing->payment_method) === $method
            && (string) $existing->reference === $reference
            && optional($existing->paid_at)->toDateTimeString() === $paidAt;
    }

    private function resolveRequestKey(mixed $key): string
    {
        $key = trim((string) $key);
        if ($key !== '') {
            return $key;
        }

        $requestKey = function_exists('request') ? trim((string) request()->header('X-Request-ID')) : '';

        return $requestKey !== '' ? $requestKey : Str::uuid()->toString();
    }

    private function normalizeAmount(mixed $amount): string
    {
        $text = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw new FinanceDomainException('Settlement amount must be a valid decimal.', 'INVALID_STATE', 422);
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole).'.'.str_pad($fraction, 2, '0');
    }

    private function toCents(mixed $amount): int
    {
        $text = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $text)) {
            return 0;
        }
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
