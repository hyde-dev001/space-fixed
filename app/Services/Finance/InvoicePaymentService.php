<?php

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\InvoicePayment;
use App\Models\User;
use App\Support\Finance\FinanceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InvoicePaymentService
{
    public const PAYMENT_METHODS = [
        'cash', 'bank_transfer', 'check', 'gcash', 'maya', 'paypal', 'other',
    ];

    /**
     * Record one append-only payment and return its derived invoice state.
     *
     * @return array{payment: InvoicePayment, invoice: array, replayed: bool}
     */
    public function record(Invoice $invoice, User $actor, array $data): array
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        if ($shopId <= 0) {
            throw new FinanceDomainException('A Finance shop context is required.', 'TENANT_CONTEXT_REQUIRED', 403);
        }

        $amount = $this->normalizeAmount($data['amount'] ?? null);
        $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? '')));
        $reference = trim((string) ($data['reference'] ?? ''));
        $receivedAt = CarbonImmutable::parse($data['received_at'] ?? now())->toDateTimeString();
        $idempotencyKey = $this->resolveRequestKey($data['idempotency_key'] ?? null);

        return DB::transaction(function () use ($invoice, $actor, $shopId, $amount, $paymentMethod, $reference, $receivedAt, $idempotencyKey): array {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedInvoice) {
                throw (new ModelNotFoundException())->setModel(Invoice::class, [$invoice->getKey()]);
            }

            if ((int) $lockedInvoice->shop_id !== $shopId) {
                throw new FinanceDomainException('The invoice is not available in this shop.', 'FORBIDDEN', 403);
            }

            $existing = InvoicePayment::query()
                ->where('shop_owner_id', $shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $this->sameRequest($existing, $lockedInvoice, $amount, $paymentMethod, $reference, $receivedAt)) {
                    throw new FinanceDomainException(
                        'The payment request key was already used with different payment details.',
                        'DUPLICATE_SUBMISSION',
                        409,
                    );
                }

                return [
                    'payment' => $existing->fresh(),
                    'invoice' => $this->state($lockedInvoice, $shopId),
                    'replayed' => true,
                ];
            }

            if ($lockedInvoice->job_order_id !== null) {
                throw new FinanceDomainException(
                    'This invoice is linked to an operational payment source and is read-only in Finance.',
                    'INVALID_STATE',
                    422,
                );
            }

            if (! in_array((string) $lockedInvoice->status, ['sent', 'overdue', 'paid'], true)) {
                throw new FinanceDomainException(
                    'Only sent or overdue invoices can receive a Finance payment.',
                    'INVALID_STATE',
                    422,
                );
            }

            $totalCents = $this->toCents($lockedInvoice->total);
            $paidCents = $this->toCents(InvoicePayment::validPaidAmountForInvoice((int) $lockedInvoice->id));
            if ($paidCents > $totalCents) {
                throw new FinanceDomainException(
                    'This invoice has an overpayment integrity warning and requires reconciliation.',
                    'INTEGRITY_WARNING',
                    409,
                );
            }

            $amountCents = $this->toCents($amount);
            if ($amountCents <= 0) {
                throw new FinanceDomainException('Payment amount must be greater than zero.', 'INVALID_STATE', 422);
            }

            if ($paidCents + $amountCents > $totalCents) {
                throw new FinanceDomainException(
                    'Payment amount exceeds the invoice balance.',
                    'AMOUNT_EXCEEDS_BALANCE',
                    422,
                );
            }

            $payment = InvoicePayment::create([
                'shop_owner_id' => $shopId,
                'invoice_id' => $lockedInvoice->id,
                'entry_type' => InvoicePayment::ENTRY_PAYMENT,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference !== '' ? $reference : null,
                'received_at' => $receivedAt,
                'recorded_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source' => InvoicePayment::SOURCE_MANUAL,
            ]);

            if ($paidCents + $amountCents === $totalCents && (string) $lockedInvoice->status !== 'paid') {
                // Preserve the legacy status for existing consumers. Payment
                // date/method remain historical compatibility fields only.
                $lockedInvoice->update(['status' => 'paid']);
            }

            return [
                'payment' => $payment->fresh(),
                'invoice' => $this->state($lockedInvoice->fresh(), $shopId),
                'replayed' => false,
            ];
        }, 3);
    }

    public function reverse(InvoicePayment $payment, User $actor, string $reason): InvoicePayment
    {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceDomainException('A reversal reason is required.', 'INVALID_STATE', 422);
        }

        return DB::transaction(function () use ($payment, $actor, $shopId, $reason): InvoicePayment {
            $lockedPayment = InvoicePayment::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if (! $lockedPayment) {
                throw (new ModelNotFoundException())->setModel(InvoicePayment::class, [$payment->getKey()]);
            }

            $invoice = Invoice::query()->whereKey($lockedPayment->invoice_id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedPayment->shop_owner_id !== $shopId || (int) $invoice->shop_id !== $shopId) {
                throw new FinanceDomainException('The payment is not available in this shop.', 'FORBIDDEN', 403);
            }

            if ((string) $lockedPayment->entry_type !== InvoicePayment::ENTRY_PAYMENT) {
                throw new FinanceDomainException('Only an original payment can be reversed.', 'INVALID_STATE', 422);
            }

            if (InvoicePayment::query()->where('reverses_payment_id', $lockedPayment->id)->exists()) {
                throw new FinanceDomainException('This payment has already been reversed.', 'ALREADY_REVERSED', 409);
            }

            $reversal = InvoicePayment::create([
                'shop_owner_id' => $shopId,
                'invoice_id' => $invoice->id,
                'entry_type' => InvoicePayment::ENTRY_REVERSAL,
                'amount' => $lockedPayment->amount,
                'payment_method' => $lockedPayment->payment_method,
                'reference' => $lockedPayment->reference,
                'received_at' => now(),
                'recorded_by_user_id' => $actor->id,
                'reverses_payment_id' => $lockedPayment->id,
                'reversal_reason' => $reason,
                'source' => InvoicePayment::SOURCE_MANUAL,
            ]);

            $paidCents = $this->toCents(InvoicePayment::validPaidAmountForInvoice((int) $invoice->id));
            if ($paidCents < $this->toCents($invoice->total) && (string) $invoice->status === 'paid') {
                $invoice->update(['status' => 'sent']);
            }

            return $reversal;
        }, 3);
    }

    public function state(Invoice $invoice, ?int $shopId = null): array
    {
        $shopId ??= (int) $invoice->shop_id;
        $payments = InvoicePayment::query()
            ->where('shop_owner_id', $shopId)
            ->where('invoice_id', $invoice->id)
            ->with(['reversedPayment:id', 'reversal:id,reverses_payment_id'])
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $paidCents = 0;
        foreach ($payments as $payment) {
            $paidCents += (string) $payment->entry_type === InvoicePayment::ENTRY_PAYMENT
                ? $this->toCents($payment->amount)
                : -$this->toCents($payment->amount);
        }

        $totalCents = $this->toCents($invoice->total);
        $overpayment = $paidCents > $totalCents;
        $status = $paidCents <= 0
            ? 'unpaid'
            : ($paidCents < $totalCents ? 'partially_paid' : 'paid');

        return [
            'id' => (int) $invoice->id,
            'source_owner' => $invoice->job_order_id !== null ? 'operational' : 'finance',
            'paid_amount' => $this->fromCents(max(0, $paidCents)),
            'remaining_balance' => $this->fromCents(max(0, $totalCents - $paidCents)),
            'status' => $status,
            'integrity_warnings' => $overpayment ? ['overpayment_detected'] : [],
            'payments' => $payments->map(fn (InvoicePayment $entry): array => [
                'id' => (int) $entry->id,
                'entry_type' => (string) $entry->entry_type,
                'amount' => (string) $entry->amount,
                'payment_method' => (string) $entry->payment_method,
                'reference' => $entry->reference,
                'received_at' => optional($entry->received_at)->toISOString(),
                'source' => (string) $entry->source,
                'reverses_payment_id' => $entry->reverses_payment_id,
                'reversal_reason' => $entry->reversal_reason,
            ])->values()->all(),
        ];
    }

    private function sameRequest(InvoicePayment $existing, Invoice $invoice, string $amount, string $method, string $reference, string $receivedAt): bool
    {
        return (int) $existing->invoice_id === (int) $invoice->id
            && $this->toCents($existing->amount) === $this->toCents($amount)
            && strtolower((string) $existing->payment_method) === $method
            && (string) ($existing->reference ?? '') === ($reference !== '' ? $reference : '')
            && optional($existing->received_at)->toDateTimeString() === $receivedAt;
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
        if (! is_numeric($amount)) {
            throw new FinanceDomainException('Payment amount must be a valid decimal.', 'INVALID_STATE', 422);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function toCents(mixed $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
