<?php

namespace App\Services;

use App\Models\PosReceipt;
use App\Models\PosTransaction;
use App\Models\User;

class RepairPosReceiptService
{
    public function issue(PosTransaction $transaction): PosReceipt
    {
        $receiptNo = 'RCPT-' . now()->format('Ymd') . '-' . str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT);

        $registeredCustomer = null;
        if ((string) $transaction->customer_type === 'registered' && (int) ($transaction->customer_id ?? 0) > 0) {
            $registeredCustomer = User::query()->find((int) $transaction->customer_id);
        }

        $registeredName = trim((string) (($registeredCustomer->first_name ?? '') . ' ' . ($registeredCustomer->last_name ?? '')));
        $customerName = $registeredName !== ''
            ? $registeredName
            : (string) ($registeredCustomer?->name ?? $transaction->walk_in_name ?? 'Walk-in Customer');

        $customerEmail = (string) ($registeredCustomer?->email ?? $transaction->walk_in_email ?? '');
        $customerPhone = (string) ($transaction->walk_in_phone ?? '');

        $payload = [
            'receipt_no' => $receiptNo,
            'transaction_no' => $transaction->transaction_no,
            'issued_at' => now()->toIso8601String(),
            'customer' => [
                'type' => $transaction->customer_type,
                'name' => $customerName,
                'phone' => $customerPhone,
                'email' => $customerEmail,
                'customer_id' => (int) ($transaction->customer_id ?? 0),
            ],
            'totals' => [
                'subtotal' => (float) $transaction->subtotal,
                'tax' => (float) $transaction->tax_amount,
                'discount' => (float) $transaction->discount_amount,
                'total' => (float) $transaction->total_amount,
                'paid' => (float) $transaction->paid_amount,
            ],
            'payment_lines' => $transaction->paymentLines->map(fn ($line) => [
                'tender_type' => $line->tender_type,
                'amount' => (float) $line->amount,
                'provider_reference' => $line->provider_reference,
            ])->values()->all(),
        ];

        return PosReceipt::create([
            'pos_transaction_id' => $transaction->id,
            'receipt_no' => $receiptNo,
            'issued_at' => now(),
            'print_payload' => $payload,
            'digital_payload' => $payload,
        ]);
    }
}
