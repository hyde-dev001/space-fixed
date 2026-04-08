<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosPaymentLine;
use App\Models\PosTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tax\VatInclusiveCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailPosPaymentService
{
    private const VAT_RATE_PERCENT = 12.0;

    public function checkout(int $shopOwnerId, array $payload, int $actorId): PosTransaction
    {
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

        if ($idempotencyKey !== '') {
            $replay = PosTransaction::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('module_type', 'retail')
                ->where('idempotency_key', $idempotencyKey)
                ->where('due_type', 'full')
                ->first();

            if ($replay) {
                $replay->setAttribute('idempotency_replay', true);
                return $replay->fresh(['paymentLines', 'receipt']);
            }
        }

        return DB::transaction(function () use ($shopOwnerId, $payload, $actorId, $idempotencyKey) {
            $normalizedItems = [];
            $inclusiveSubtotal = 0.0;

            foreach (array_values((array) $payload['items']) as $index => $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? 0);
                $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => ['Quantity must be at least 1.'],
                    ]);
                }

                if ($unitPrice <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.unit_price" => ['Unit price must be greater than zero.'],
                    ]);
                }

                $product = Product::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->lockForUpdate()
                    ->find($productId);

                if (!$product) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => ['Selected product is not available in this shop.'],
                    ]);
                }

                if ((int) ($product->stock_quantity ?? 0) < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => ['Insufficient stock for selected item.'],
                    ]);
                }

                $requestedSize = isset($line['size']) ? trim((string) $line['size']) : '';
                $requestedColor = isset($line['color']) ? trim((string) $line['color']) : '';
                $resolvedVariant = null;

                if ($requestedSize !== '' && $requestedColor !== '') {
                    $resolvedVariant = ProductVariant::query()
                        ->where('product_id', (int) $product->id)
                        ->where('is_active', true)
                        ->where('size', $requestedSize)
                        ->where('color', $requestedColor)
                        ->lockForUpdate()
                        ->first();

                    if (!$resolvedVariant) {
                        throw ValidationException::withMessages([
                            "items.{$index}.size" => ["Variant not found for size {$requestedSize} and color {$requestedColor}."],
                        ]);
                    }

                    if ((int) ($resolvedVariant->quantity ?? 0) < $qty) {
                        throw ValidationException::withMessages([
                            "items.{$index}.qty" => ["Insufficient stock for size {$requestedSize} and color {$requestedColor}."],
                        ]);
                    }
                }

                $lineSubtotal = round($unitPrice * $qty, 2);
                $inclusiveSubtotal += $lineSubtotal;

                $normalizedItems[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'size' => $resolvedVariant?->size ?? ($requestedSize !== '' ? $requestedSize : null),
                    'color' => $resolvedVariant?->color ?? ($requestedColor !== '' ? $requestedColor : null),
                    'image' => $line['image'] ?? null,
                    'variant' => $resolvedVariant,
                ];
            }

            $inclusiveSubtotal = round($inclusiveSubtotal, 2);
            $breakdown = VatInclusiveCalculator::extract($inclusiveSubtotal, self::VAT_RATE_PERCENT);

            $expectedTotal = round((float) $breakdown['total'], 2);
            $paidAmount = round((float) collect((array) $payload['payment_lines'])
                ->sum(fn ($line) => (float) ($line['amount'] ?? 0)), 2);

            if ($paidAmount !== $expectedTotal) {
                throw ValidationException::withMessages([
                    'payment_lines' => ['Paid amount must exactly match retail due amount.'],
                ]);
            }

            $order = Order::create([
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => (string) ($payload['customer_type'] ?? 'walk_in') === 'registered'
                    ? (int) ($payload['customer_id'] ?? 0)
                    : null,
                'order_number' => $this->generateRetailPosOrderNumber(),
                'total_amount' => round((float) $breakdown['net'], 2),
                'shipping_fee' => 0,
                'vat_rate' => self::VAT_RATE_PERCENT,
                'vat_amount' => round((float) $breakdown['vat'], 2),
                'status' => 'completed',
                'customer_name' => (string) ($payload['walk_in_name'] ?? 'Walk-in Customer'),
                'customer_email' => $payload['walk_in_email'] ?? null,
                'customer_phone' => $payload['walk_in_phone'] ?? null,
                'customer_address' => 'Walk-in POS',
                'payment_method' => (string) ($payload['payment_lines'][0]['tender_type'] ?? 'cash'),
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            foreach ($normalizedItems as $item) {
                /** @var Product $product */
                $product = $item['product'];
                $variant = $item['variant'] ?? null;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => (string) $product->name,
                    'product_slug' => (string) $product->slug,
                    'price' => $item['unit_price'],
                    'quantity' => $item['qty'],
                    'subtotal' => $item['subtotal'],
                    'size' => $item['size'],
                    'color' => $item['color'],
                    'product_image' => $item['image'] ?? $variant?->image ?? $product->main_image,
                ]);

                $product->decrement('stock_quantity', $item['qty']);
                $product->increment('sales_count', $item['qty']);

                if ($variant instanceof ProductVariant) {
                    $variant->decrement('quantity', $item['qty']);
                }
            }

            $transaction = PosTransaction::create([
                'transaction_no' => $this->generateTransactionNo(),
                'idempotency_key' => $idempotencyKey,
                'phase_lock_key' => sprintf('retail:%d:full', (int) $order->id),
                'shop_owner_id' => $shopOwnerId,
                'module_type' => 'retail',
                'module_reference_id' => (int) $order->id,
                'customer_type' => (string) ($payload['customer_type'] ?? 'walk_in'),
                'customer_id' => (string) ($payload['customer_type'] ?? 'walk_in') === 'registered'
                    ? (int) ($payload['customer_id'] ?? 0)
                    : null,
                'walk_in_name' => $payload['walk_in_name'] ?? null,
                'walk_in_phone' => $payload['walk_in_phone'] ?? null,
                'walk_in_email' => $payload['walk_in_email'] ?? null,
                'due_type' => 'full',
                'subtotal' => round((float) $breakdown['net'], 2),
                'tax_amount' => round((float) $breakdown['vat'], 2),
                'discount_amount' => 0,
                'total_amount' => $expectedTotal,
                'paid_amount' => $paidAmount,
                'status' => 'paid',
                'paid_at' => now(),
                'created_by' => $actorId > 0 ? $actorId : null,
                'metadata' => [
                    'vat_rate' => self::VAT_RATE_PERCENT,
                    'tax_mode' => 'vat_inclusive',
                    'order_id' => (int) $order->id,
                ],
            ]);

            foreach ((array) $payload['payment_lines'] as $line) {
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

            $transaction->load('paymentLines');
            app(RepairPosReceiptService::class)->issue($transaction);

            return $transaction->fresh(['paymentLines', 'receipt']);
        });
    }

    private function generateRetailPosOrderNumber(): string
    {
        do {
            $orderNumber = 'RPOS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        } while (Order::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    private function generateTransactionNo(): string
    {
        do {
            $transactionNo = 'POS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (PosTransaction::query()->where('transaction_no', $transactionNo)->exists());

        return $transactionNo;
    }
}
