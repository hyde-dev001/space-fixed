<?php

namespace App\Services;

use App\Models\PosReceipt;
use App\Models\PosTransaction;

class RepairPosReceiptService
{
    public function issue(PosTransaction $transaction): PosReceipt
    {
        $receiptNo = 'RCPT-' . now()->format('Ymd') . '-' . str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT);

        $payload = [
            'receipt_no' => $receiptNo,
            'transaction_no' => $transaction->transaction_no,
            'issued_at' => now()->toIso8601String(),
            'customer' => [
                'type' => $transaction->customer_type,
                'name' => $transaction->walk_in_name,
                'phone' => $transaction->walk_in_phone,
                'email' => $transaction->walk_in_email,
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
