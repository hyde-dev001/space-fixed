<?php

namespace App\Services\Finance;

use App\Mail\SupplierPaymentConfirmationMail;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierPaymentAttempt;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use App\Support\Finance\FinanceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class SupplierPaymentService
{
    public function __construct(private readonly ExpenseSettlementService $settlementService) {}

    /** @return array{attempt: SupplierPaymentAttempt, replayed: bool} */
    public function initiate(Expense $expense, User $actor, string $paymentMethod, string $idempotencyKey): array
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $paymentMethod = trim($paymentMethod);
        $idempotencyKey = trim($idempotencyKey);

        if ($shopId < 1) {
            throw new FinanceDomainException('A Finance shop context is required.', 'TENANT_CONTEXT_REQUIRED', 403);
        }
        if (! in_array($paymentMethod, SupplierPaymentAttempt::PAYMENT_METHODS, true)) {
            throw new FinanceDomainException('Payment method is not supported.', 'INVALID_STATE', 422);
        }
        if ($idempotencyKey === '') {
            throw new FinanceDomainException('An idempotency key is required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($expense, $actor, $shopId, $paymentMethod, $idempotencyKey): array {
            $lockedExpense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();
            $this->assertExpenseBelongsToShop($lockedExpense, $shopId);

            $receipt = $this->lockedReceiptForExpense($lockedExpense, $shopId);
            $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
            $supplier = Supplier::query()->whereKey($purchaseOrder->supplier_id)->lockForUpdate()->firstOrFail();
            $profile = SupplierPaymentProfile::query()
                ->where('shop_owner_id', $shopId)
                ->where('supplier_id', $supplier->id)
                ->lockForUpdate()
                ->first();

            $amount = $this->outstandingAmount($lockedExpense);
            $existing = SupplierPaymentAttempt::query()
                ->where('shop_owner_id', $shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->expense_id !== (int) $lockedExpense->id
                    || (string) $existing->payment_method !== $paymentMethod
                    || $this->toCents($existing->amount) !== $this->toCents($amount)
                    || (int) $existing->supplier_payment_profile_id !== (int) ($profile?->id ?? 0)) {
                    throw new FinanceDomainException(
                        'The payment request key was already used with different payment details.',
                        'DUPLICATE_SUBMISSION',
                        409,
                    );
                }

                return ['attempt' => $existing->fresh(), 'replayed' => true];
            }

            $this->assertPaymentContext($lockedExpense, $receipt, $purchaseOrder, $supplier, $profile, $shopId);

            $active = SupplierPaymentAttempt::query()
                ->where('shop_owner_id', $shopId)
                ->where('expense_id', $lockedExpense->id)
                ->whereIn('status', [
                    SupplierPaymentAttempt::STATUS_INITIATING,
                    SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION,
                ])
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw new FinanceDomainException(
                    'This expense already has a supplier payment awaiting completion.',
                    'INVALID_STATE',
                    422,
                );
            }

            $attempt = SupplierPaymentAttempt::create([
                'shop_owner_id' => $shopId,
                'expense_id' => $lockedExpense->id,
                'supplier_id' => $supplier->id,
                'supplier_payment_profile_id' => $profile->id,
                'amount' => $amount,
                'currency' => 'PHP',
                'provider' => 'manual',
                'payment_method' => $paymentMethod,
                'internal_reference' => 'SPM-' . Str::upper(Str::random(20)),
                'idempotency_key' => $idempotencyKey,
                'destination_snapshot' => [
                    'destination_type' => $profile->destination_type,
                    'bank_name' => $profile->bank_name,
                    'bank_code' => $profile->bank_code,
                    'account_name' => $profile->account_name,
                    'account_number' => $profile->account_number,
                ],
                'status' => SupplierPaymentAttempt::STATUS_INITIATING,
                'initiated_by_user_id' => $actor->id,
                'initiated_at' => now(),
                'supplier_email_to' => trim((string) $supplier->email),
                'supplier_email_status' => 'pending',
            ]);

            return ['attempt' => $attempt->fresh(), 'replayed' => false];
        }, 3);
    }

    public function submitForVerification(
        SupplierPaymentAttempt $attempt,
        User $actor,
        array $data,
        UploadedFile $proof,
    ): SupplierPaymentAttempt {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $media = null;

        try {
            $updated = DB::transaction(function () use ($attempt, $actor, $shopId, $data, $proof, &$media): SupplierPaymentAttempt {
                $candidate = SupplierPaymentAttempt::query()->whereKey($attempt->getKey())->firstOrFail();
                $expense = Expense::query()->whereKey($candidate->expense_id)->lockForUpdate()->firstOrFail();
                $receipt = $this->lockedReceiptForExpense($expense, $shopId);
                $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
                $supplier = Supplier::query()->whereKey($purchaseOrder->supplier_id)->lockForUpdate()->firstOrFail();
                $lockedAttempt = SupplierPaymentAttempt::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
                $this->assertAttemptBelongsToShop($lockedAttempt, $shopId);
                $this->assertAttemptLinks($lockedAttempt, $expense, $supplier);

                if ((string) $lockedAttempt->status !== SupplierPaymentAttempt::STATUS_INITIATING) {
                    throw new FinanceDomainException('Only an initiating payment can be submitted for verification.', 'INVALID_STATE', 422);
                }
                if ((int) $lockedAttempt->initiated_by_user_id !== (int) $actor->id) {
                    throw new FinanceDomainException('Only the Finance user who initiated the payment can submit proof.', 'FORBIDDEN', 403);
                }
                if ($lockedAttempt->getMedia('payment_proof')->isNotEmpty()) {
                    throw new FinanceDomainException('Payment proof has already been submitted.', 'INVALID_STATE', 422);
                }

                $this->assertReceiptContext($expense, $receipt, $purchaseOrder, $supplier, $shopId);
                if (! filter_var(trim((string) $lockedAttempt->supplier_email_to), FILTER_VALIDATE_EMAIL)) {
                    throw new FinanceDomainException('The payment has no valid supplier email snapshot.', 'INVALID_STATE', 422);
                }

                $amount = $this->normalizeAmount($data['amount'] ?? null);
                if ($this->toCents($amount) !== $this->toCents($lockedAttempt->amount)
                    || $this->toCents($amount) !== $this->toCents($this->outstandingAmount($expense))) {
                    throw new FinanceDomainException('The transferred amount must equal the full outstanding balance.', 'AMOUNT_MISMATCH', 422);
                }

                $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
                if ($paymentMethod !== (string) $lockedAttempt->payment_method) {
                    throw new FinanceDomainException('The payment method cannot change after initiation.', 'INVALID_STATE', 422);
                }

                $reference = trim((string) ($data['external_transaction_reference'] ?? ''));
                if ($reference === '') {
                    throw new FinanceDomainException('An external transaction reference is required.', 'INVALID_STATE', 422);
                }
                if (SupplierPaymentAttempt::query()
                    ->where('provider', 'manual')
                    ->where('provider_reference', $reference)
                    ->whereKeyNot($lockedAttempt->id)
                    ->exists()) {
                    throw new FinanceDomainException('That external transaction reference is already in use.', 'DUPLICATE_SUBMISSION', 409);
                }

                $media = $lockedAttempt->addMedia($proof)->toMediaCollection('payment_proof');
                $lockedAttempt->update([
                    'provider_reference' => $reference,
                    'externally_paid_at' => CarbonImmutable::parse($data['externally_paid_at'])->toDateTimeString(),
                    'submitted_for_verification_at' => now(),
                    'finance_note' => isset($data['finance_note']) ? trim((string) $data['finance_note']) : null,
                    'status' => SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION,
                ]);

                return $lockedAttempt->fresh();
            }, 3);

            $purchaseOrder = $updated->load('expense.procurementReceipt.purchaseOrder')
                ->expense?->procurementReceipt?->purchaseOrder;
            try {
                app(\App\Services\NotificationService::class)->notifySupplierPaymentAwaitingVerification($shopId, [
                    'attempt_id' => $updated->id,
                    'expense_id' => $updated->expense_id,
                    'po_number' => $purchaseOrder?->po_number ?? 'unknown',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $updated;
        } catch (Throwable $exception) {
            if ($media) {
                try {
                    $media->delete();
                } catch (Throwable) {
                    // Preserve the original domain failure; no proof is exposed.
                }
            }

            throw $exception;
        }
    }

    public function cancel(SupplierPaymentAttempt $attempt, User $actor, string $reason): SupplierPaymentAttempt
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceDomainException('A cancellation reason is required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($attempt, $actor, $shopId, $reason): SupplierPaymentAttempt {
            $candidate = SupplierPaymentAttempt::query()->whereKey($attempt->getKey())->firstOrFail();
            $expense = Expense::query()->whereKey($candidate->expense_id)->lockForUpdate()->firstOrFail();
            $lockedAttempt = SupplierPaymentAttempt::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            $this->assertAttemptBelongsToShop($lockedAttempt, $shopId);

            if ((string) $lockedAttempt->status !== SupplierPaymentAttempt::STATUS_INITIATING) {
                throw new FinanceDomainException('Only an initiating payment can be cancelled.', 'INVALID_STATE', 422);
            }
            if ($lockedAttempt->provider_reference
                || $lockedAttempt->externally_paid_at
                || $lockedAttempt->settlement_id
                || $lockedAttempt->getMedia('payment_proof')->isNotEmpty()) {
                throw new FinanceDomainException('This payment cannot be cancelled after transfer evidence exists.', 'INVALID_STATE', 422);
            }

            $lockedAttempt->update([
                'status' => SupplierPaymentAttempt::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $actor->id,
                'cancelled_at' => now(),
            ]);

            return $lockedAttempt->fresh();
        }, 3);
    }

    public function confirm(SupplierPaymentAttempt $attempt, ShopOwner $shopOwner): SupplierPaymentAttempt
    {
        $shopId = (int) $shopOwner->getKey();
        $settledNow = false;

        $confirmed = DB::transaction(function () use ($attempt, $shopOwner, $shopId, &$settledNow): SupplierPaymentAttempt {
            $candidate = SupplierPaymentAttempt::query()->whereKey($attempt->getKey())->firstOrFail();
            $expense = Expense::query()->whereKey($candidate->expense_id)->lockForUpdate()->firstOrFail();
            $receipt = $this->lockedReceiptForExpense($expense, $shopId);
            $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
            $supplier = Supplier::query()->whereKey($purchaseOrder->supplier_id)->lockForUpdate()->firstOrFail();
            $lockedAttempt = SupplierPaymentAttempt::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            $this->assertAttemptBelongsToShop($lockedAttempt, $shopId);
            $this->assertAttemptLinks($lockedAttempt, $expense, $supplier);

            if ((string) $lockedAttempt->status === SupplierPaymentAttempt::STATUS_SUCCEEDED) {
                return $lockedAttempt->fresh();
            }
            if ((string) $lockedAttempt->status !== SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION) {
                throw new FinanceDomainException('Only a payment awaiting verification can be confirmed.', 'INVALID_STATE', 422);
            }
            if ($lockedAttempt->getMedia('payment_proof')->isEmpty()
                || ! $lockedAttempt->provider_reference
                || ! $lockedAttempt->externally_paid_at) {
                throw new FinanceDomainException('Payment proof and transfer details are required before confirmation.', 'INVALID_STATE', 422);
            }
            if ($this->toCents($lockedAttempt->amount) !== $this->toCents($this->outstandingAmount($expense))) {
                throw new FinanceDomainException('The payment no longer equals the full outstanding balance.', 'AMOUNT_MISMATCH', 422);
            }
            if (SupplierPaymentAttempt::query()
                ->where('shop_owner_id', $shopId)
                ->where('expense_id', $lockedAttempt->expense_id)
                ->where('status', SupplierPaymentAttempt::STATUS_SUCCEEDED)
                ->whereKeyNot($lockedAttempt->id)
                ->exists()) {
                throw new FinanceDomainException('Another supplier payment has already settled this expense.', 'INVALID_STATE', 409);
            }

            $this->assertReceiptContext($expense, $receipt, $purchaseOrder, $supplier, $shopId);
            if (! filter_var(trim((string) $lockedAttempt->supplier_email_to), FILTER_VALIDATE_EMAIL)) {
                throw new FinanceDomainException('The payment has no valid supplier email snapshot.', 'INVALID_STATE', 422);
            }

            $settlement = $this->settlementService->record($expense, $shopOwner, [
                'amount' => (string) $lockedAttempt->amount,
                'payment_method' => (string) $lockedAttempt->payment_method,
                'reference' => (string) $lockedAttempt->provider_reference,
                'paid_at' => $lockedAttempt->externally_paid_at->toDateTimeString(),
                'idempotency_key' => 'supplier-manual-settlement:' . $lockedAttempt->id,
                'source' => ExpenseSettlement::SOURCE_SUPPLIER_MANUAL_PAYMENT,
                'source_reference' => 'supplier-manual-payment:' . $lockedAttempt->id,
            ]);

            $lockedAttempt->update([
                'status' => SupplierPaymentAttempt::STATUS_SUCCEEDED,
                'settlement_id' => $settlement['settlement']->id,
                'verified_by_shop_owner_id' => $shopOwner->id,
                'verified_at' => now(),
                'succeeded_at' => now(),
                'settled_at' => now(),
                'supplier_email_status' => 'pending',
            ]);
            $settledNow = true;

            return $lockedAttempt->fresh();
        }, 3);

        if ($settledNow) {
            $purchaseOrder = $confirmed->load('expense.procurementReceipt.purchaseOrder')
                ->expense?->procurementReceipt?->purchaseOrder;
            try {
                app(\App\Services\NotificationService::class)->notifySupplierPaymentVerified($shopId, [
                    'attempt_id' => $confirmed->id,
                    'expense_id' => $confirmed->expense_id,
                    'po_number' => $purchaseOrder?->po_number ?? 'unknown',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->deliverSupplierConfirmation($confirmed);
    }

    public function reject(SupplierPaymentAttempt $attempt, ShopOwner $shopOwner, string $reason): SupplierPaymentAttempt
    {
        $shopId = (int) $shopOwner->getKey();
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceDomainException('A rejection reason is required.', 'INVALID_STATE', 422);
        }

        $rejected = DB::transaction(function () use ($attempt, $shopOwner, $shopId, $reason): SupplierPaymentAttempt {
            $lockedAttempt = SupplierPaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            $this->assertAttemptBelongsToShop($lockedAttempt, $shopId);

            if ((string) $lockedAttempt->status !== SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION) {
                throw new FinanceDomainException('Only a payment awaiting verification can be rejected.', 'INVALID_STATE', 422);
            }

            $lockedAttempt->update([
                'status' => SupplierPaymentAttempt::STATUS_REJECTED,
                'rejected_by_shop_owner_id' => $shopOwner->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $lockedAttempt->fresh();
        }, 3);

        $purchaseOrder = $rejected->load('expense.procurementReceipt.purchaseOrder')
            ->expense?->procurementReceipt?->purchaseOrder;
        try {
            app(\App\Services\NotificationService::class)->notifySupplierPaymentRejected($shopId, [
                'attempt_id' => $rejected->id,
                'expense_id' => $rejected->expense_id,
                'po_number' => $purchaseOrder?->po_number ?? 'unknown',
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $rejected;
    }

    public function resendConfirmation(SupplierPaymentAttempt $attempt, User $actor): SupplierPaymentAttempt
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);

        $pending = DB::transaction(function () use ($attempt, $shopId): SupplierPaymentAttempt {
            $lockedAttempt = SupplierPaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            $this->assertAttemptBelongsToShop($lockedAttempt, $shopId);
            if ((string) $lockedAttempt->status !== SupplierPaymentAttempt::STATUS_SUCCEEDED
                || (string) $lockedAttempt->supplier_email_status !== 'failed') {
                throw new FinanceDomainException('Only a failed supplier payment confirmation can be resent.', 'INVALID_STATE', 422);
            }

            $lockedAttempt->update([
                'supplier_email_status' => 'pending',
                'supplier_email_failure_message' => null,
                'supplier_email_failed_at' => null,
            ]);

            return $lockedAttempt->fresh();
        }, 3);

        return $this->deliverSupplierConfirmation($pending);
    }

    /** @return array<string, mixed> */
    public function present(SupplierPaymentAttempt $attempt): array
    {
        $attempt->loadMissing([
            'supplier',
            'initiatedBy:id,name',
            'expense.procurementReceipt.purchaseOrder',
        ]);
        $receipt = $attempt->expense?->procurementReceipt;
        $purchaseOrder = $receipt?->purchaseOrder;

        return [
            'id' => (int) $attempt->id,
            'status' => (string) $attempt->status,
            'payment_status' => match ((string) $attempt->status) {
                SupplierPaymentAttempt::STATUS_SUCCEEDED => 'paid',
                SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION => 'awaiting_verification',
                SupplierPaymentAttempt::STATUS_REJECTED => 'rejected',
                SupplierPaymentAttempt::STATUS_CANCELLED => 'cancelled',
                default => 'initiating',
            },
            'amount' => (string) $attempt->amount,
            'currency' => (string) $attempt->currency,
            'payment_method' => (string) $attempt->payment_method,
            'internal_reference' => (string) $attempt->internal_reference,
            'external_transaction_reference' => $attempt->externalTransactionReference(),
            'supplier_email_to' => $attempt->supplier_email_to,
            'supplier_email_status' => $attempt->supplier_email_status,
            'supplier_email_sent_at' => $attempt->supplier_email_sent_at?->toISOString(),
            'supplier_email_failed_at' => $attempt->supplier_email_failed_at?->toISOString(),
            'supplier_email_failure_message' => $attempt->supplier_email_failure_message,
            'finance_note' => $attempt->finance_note,
            'initiated_by' => $attempt->initiatedBy ? [
                'id' => (int) $attempt->initiatedBy->id,
                'name' => (string) $attempt->initiatedBy->name,
            ] : null,
            'rejection_reason' => $attempt->rejection_reason,
            'cancellation_reason' => $attempt->cancellation_reason,
            'cancelled_at' => $attempt->cancelled_at?->toISOString(),
            'initiated_at' => $attempt->initiated_at?->toISOString(),
            'externally_paid_at' => $attempt->externally_paid_at?->toISOString(),
            'submitted_for_verification_at' => $attempt->submitted_for_verification_at?->toISOString(),
            'verified_at' => $attempt->verified_at?->toISOString(),
            'supplier' => [
                'id' => $attempt->supplier?->id,
                'name' => $attempt->supplier?->name,
                'email' => $attempt->supplier?->email,
            ],
            'purchase_order' => [
                'id' => $purchaseOrder?->id,
                'number' => $purchaseOrder?->po_number,
            ],
            'receipt' => [
                'id' => $receipt?->id,
                'status' => $receipt?->status,
            ],
            'masked_destination' => $attempt->maskedDestination(),
            'proof_media' => $attempt->getMedia('payment_proof')->map(fn ($media): array => [
                'id' => (int) $media->id,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => (int) $media->size,
            ])->values()->all(),
            'verified_by_shop_owner_id' => $attempt->verified_by_shop_owner_id,
            'rejected_by_shop_owner_id' => $attempt->rejected_by_shop_owner_id,
        ];
    }

    private function deliverSupplierConfirmation(SupplierPaymentAttempt $attempt): SupplierPaymentAttempt
    {
        if ((string) $attempt->supplier_email_status !== 'pending') {
            return $attempt->fresh();
        }

        $attempt->loadMissing(['supplier', 'expense.procurementReceipt.purchaseOrder']);

        try {
            Mail::to($attempt->supplier_email_to)->send(new SupplierPaymentConfirmationMail(
                supplierName: (string) $attempt->supplier?->name,
                poNumber: (string) $attempt->expense?->procurementReceipt?->purchaseOrder?->po_number,
                amount: (string) $attempt->amount,
                paymentMethod: (string) $attempt->payment_method,
                externalTransactionReference: (string) $attempt->provider_reference,
                externallyPaidAt: $attempt->externally_paid_at,
                maskedDestination: $attempt->maskedDestination(),
            ));

            $attempt->forceFill([
                'supplier_email_status' => 'sent',
                'supplier_email_sent_at' => now(),
                'supplier_email_failed_at' => null,
                'supplier_email_failure_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Supplier payment confirmation email failed.', [
                'attempt_id' => $attempt->id,
                'shop_id' => $attempt->shop_owner_id,
            ]);
            $attempt->forceFill([
                'supplier_email_status' => 'failed',
                'supplier_email_failed_at' => now(),
                'supplier_email_failure_message' => 'Supplier email delivery failed.',
            ])->save();
        }

        return $attempt->fresh();
    }

    private function lockedReceiptForExpense(Expense $expense, int $shopId): PurchaseOrderReceipt
    {
        if (! $expense->procurement_receipt_id) {
            throw new FinanceDomainException('This expense is not linked to a procurement receipt.', 'INVALID_STATE', 422);
        }

        $receipt = PurchaseOrderReceipt::query()->whereKey($expense->procurement_receipt_id)->lockForUpdate()->firstOrFail();
        if ((int) $receipt->shop_owner_id !== $shopId) {
            throw new FinanceDomainException('The procurement receipt is not available in this shop.', 'FORBIDDEN', 403);
        }

        return $receipt;
    }

    private function assertPaymentContext(
        Expense $expense,
        PurchaseOrderReceipt $receipt,
        $purchaseOrder,
        Supplier $supplier,
        ?SupplierPaymentProfile $profile,
        int $shopId,
    ): void {
        $this->assertReceiptContext($expense, $receipt, $purchaseOrder, $supplier, $shopId);

        if (! $profile || (int) $profile->shop_owner_id !== $shopId || $profile->status !== SupplierPaymentProfile::STATUS_VERIFIED) {
            throw new FinanceDomainException('A verified supplier payment profile is required.', 'INVALID_STATE', 422);
        }
        if (! filter_var(trim((string) $supplier->email), FILTER_VALIDATE_EMAIL)) {
            throw new FinanceDomainException('The supplier must have a valid email address before payment.', 'INVALID_STATE', 422);
        }
    }

    private function assertReceiptContext(
        Expense $expense,
        PurchaseOrderReceipt $receipt,
        $purchaseOrder,
        Supplier $supplier,
        int $shopId,
    ): void {
        if ((int) $expense->shop_id !== $shopId
            || (string) $expense->status !== 'posted'
            || (string) $receipt->status !== 'posted'
            || $receipt->voided_at
            || (int) $purchaseOrder->shop_owner_id !== $shopId
            || (int) $supplier->shop_owner_id !== $shopId) {
            throw new FinanceDomainException('The procurement payment is not available in this shop or state.', 'INVALID_STATE', 422);
        }
    }

    private function assertExpenseBelongsToShop(Expense $expense, int $shopId): void
    {
        if ((int) $expense->shop_id !== $shopId) {
            throw new FinanceDomainException('The expense is not available in this shop.', 'FORBIDDEN', 403);
        }
    }

    private function assertAttemptBelongsToShop(SupplierPaymentAttempt $attempt, int $shopId): void
    {
        if ((int) $attempt->shop_owner_id !== $shopId) {
            throw new FinanceDomainException('The supplier payment is not available in this shop.', 'FORBIDDEN', 403);
        }
    }

    private function assertAttemptLinks(SupplierPaymentAttempt $attempt, Expense $expense, Supplier $supplier): void
    {
        if ((int) $attempt->expense_id !== (int) $expense->id
            || (int) $attempt->supplier_id !== (int) $supplier->id
            || strtoupper((string) $attempt->currency) !== 'PHP') {
            throw new FinanceDomainException('The supplier payment no longer matches its procurement records.', 'INVALID_STATE', 409);
        }
    }

    private function outstandingAmount(Expense $expense): string
    {
        $totalCents = $this->toCents($expense->amount);
        $settledCents = $this->toCents(ExpenseSettlement::validSettledAmountForExpense((int) $expense->id));

        if ($settledCents >= $totalCents) {
            throw new FinanceDomainException('This expense has no outstanding supplier balance.', 'INVALID_STATE', 422);
        }

        return $this->fromCents($totalCents - $settledCents);
    }

    private function normalizeAmount(mixed $amount): string
    {
        $text = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw new FinanceDomainException('Payment amount must be a valid decimal.', 'INVALID_STATE', 422);
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole) . '.' . str_pad($fraction, 2, '0');
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
