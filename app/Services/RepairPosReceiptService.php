<?php

namespace App\Services;

use App\Models\PosReceipt;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RepairPosReceiptService
{
    public function issue(PosTransaction $transaction): PosReceipt
    {
        return DB::transaction(function () use ($transaction): PosReceipt {
            $lockedTransaction = PosTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existingReceipt = PosReceipt::query()
                ->where('pos_transaction_id', $lockedTransaction->getKey())
                ->first();

            if ($existingReceipt) {
                return $existingReceipt;
            }

            $lockedTransaction->loadMissing('paymentLines');
            $receiptNo = $this->nextReceiptNumber((int) $lockedTransaction->shop_owner_id);
            $registeredCustomer = null;
            if ((string) $lockedTransaction->customer_type === 'registered'
                && (int) ($lockedTransaction->customer_id ?? 0) > 0) {
                $registeredCustomer = User::query()->find((int) $lockedTransaction->customer_id);
            }

            $registeredName = trim((string) (($registeredCustomer->first_name ?? '') . ' ' . ($registeredCustomer->last_name ?? '')));
            $walkInName = trim((string) ($lockedTransaction->walk_in_name ?? ''));
            $customerName = $registeredName !== ''
                ? $registeredName
                : (string) ($registeredCustomer?->name ?? ($walkInName !== '' ? $walkInName : 'Walk-in Customer'));
            $customerEmail = (string) ($registeredCustomer?->email ?? $lockedTransaction->walk_in_email ?? '');
            $customerPhone = (string) ($registeredCustomer?->phone ?? $lockedTransaction->walk_in_phone ?? '');
            $issuedAt = now();
            $payload = [
                'receipt_no' => $receiptNo,
                'transaction_no' => $lockedTransaction->transaction_no,
                'issued_at' => $issuedAt->toIso8601String(),
                'customer' => [
                    'type' => $lockedTransaction->customer_type,
                    'name' => $customerName,
                    'phone' => $customerPhone,
                    'email' => $customerEmail,
                    'customer_id' => (int) ($lockedTransaction->customer_id ?? 0),
                ],
                'totals' => [
                    'subtotal' => (float) $lockedTransaction->subtotal,
                    'tax' => (float) $lockedTransaction->tax_amount,
                    'discount' => (float) $lockedTransaction->discount_amount,
                    'total' => (float) $lockedTransaction->total_amount,
                    'paid' => (float) $lockedTransaction->paid_amount,
                    'cash_received' => data_get($lockedTransaction->metadata, 'cash_received') !== null
                        ? (float) data_get($lockedTransaction->metadata, 'cash_received')
                        : null,
                    'change' => (float) data_get($lockedTransaction->metadata, 'change', 0),
                ],
                'payment_lines' => $lockedTransaction->paymentLines->map(fn ($line) => [
                    'tender_type' => $line->tender_type,
                    'amount' => (float) $line->amount,
                    'provider_reference' => $line->provider_reference,
                ])->values()->all(),
            ];

            return PosReceipt::create([
                'pos_transaction_id' => $lockedTransaction->getKey(),
                'shop_owner_id' => $lockedTransaction->shop_owner_id,
                'receipt_no' => $receiptNo,
                'issued_at' => $issuedAt,
                'print_payload' => $payload,
                'digital_payload' => $payload,
            ]);
        });
    }

    private function nextReceiptNumber(int $shopOwnerId): string
    {
        DB::table('shop_receipt_sequences')->insertOrIgnore([
            'shop_owner_id' => $shopOwnerId,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('shop_receipt_sequences')
            ->where('shop_owner_id', $shopOwnerId)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new \RuntimeException('Receipt sequence is not available for this shop.');
        }

        $number = (int) $sequence->next_number;
        do {
            $receiptNo = 'RCPT-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (PosReceipt::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('receipt_no', $receiptNo)
            ->exists());

        DB::table('shop_receipt_sequences')
            ->where('shop_owner_id', $shopOwnerId)
            ->update([
                'next_number' => $number,
                'updated_at' => now(),
            ]);

        return $receiptNo;
    }
}
