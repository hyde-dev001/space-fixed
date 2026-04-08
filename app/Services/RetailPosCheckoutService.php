<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailPosCheckoutService
{
    public function checkout(int $shopOwnerId, array $payload, int $actorId): Order
    {
        return DB::transaction(function () use ($shopOwnerId, $payload, $actorId) {
            $inclusiveSubtotal = round((float) collect($payload['items'])->sum(function (array $item) {
                return ((float) $item['unit_price']) * ((int) $item['qty']);
            }), 2);

            $vatRate = 12.0;
            $vatAmount = round($inclusiveSubtotal * ($vatRate / (100 + $vatRate)), 2);
            $netSubtotal = round($inclusiveSubtotal - $vatAmount, 2);

            $order = Order::create([
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => null,
                'order_number' => $this->generateRetailPosOrderNumber(),
                'total_amount' => $netSubtotal,
                'shipping_fee' => 0,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'status' => 'completed',
                'customer_name' => (string) $payload['customer_name'],
                'customer_email' => $payload['customer_email'] ?? null,
                'customer_phone' => $payload['customer_phone'] ?? null,
                'customer_address' => 'Walk-in POS',
                'payment_method' => (string) $payload['payment_method'],
                'payment_status' => 'paid',
                'paid_at' => now(),
                'notes' => trim((string) ($payload['payment_reference'] ?? '')),
                'assigned_by' => $actorId > 0 ? $actorId : null,
            ]);

            foreach (array_values($payload['items']) as $index => $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? 0);
                $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

                $product = Product::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->lockForUpdate()
                    ->find($productId);

                if (!$product) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => ['Selected product is not available in this shop.'],
                    ]);
                }

                if ((int) $product->stock_quantity < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => ['Insufficient stock for selected item.'],
                    ]);
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => (string) $product->name,
                    'product_slug' => (string) $product->slug,
                    'price' => $unitPrice,
                    'quantity' => $qty,
                    'subtotal' => round($unitPrice * $qty, 2),
                    'size' => $line['size'] ?? null,
                    'color' => $line['color'] ?? null,
                    'product_image' => $line['image'] ?? $product->main_image,
                ]);

                $product->decrement('stock_quantity', $qty);
                $product->increment('sales_count', $qty);
            }

            return $order->fresh(['items']);
        });
    }

    private function generateRetailPosOrderNumber(): string
    {
        do {
            $orderNumber = 'RPOS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        } while (Order::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
