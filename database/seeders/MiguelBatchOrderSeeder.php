<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\SourceShipmentService;
use Illuminate\Database\Seeder;
use RuntimeException;

class MiguelBatchOrderSeeder extends Seeder
{
    public function run(SourceShipmentService $shipments): void
    {
        $customer = User::where('email', 'miguel.rosa@example.com')->first()
            ?? throw new RuntimeException('Customer miguel.rosa@example.com was not found.');
        $shop = ShopOwner::where('email', 'test2@example.com')->first()
            ?? throw new RuntimeException('Urban Kicks Store (test2@example.com) was not found.');
        $address = UserAddress::where('user_id', $customer->id)->where('is_default', true)->first()
            ?? UserAddress::where('user_id', $customer->id)->first()
            ?? throw new RuntimeException('Miguel needs a saved delivery address.');
        $product = Product::where('shop_owner_id', $shop->id)->first()
            ?? throw new RuntimeException('Urban Kicks Store needs at least one product.');

        foreach (range(1, 12) as $number) {
            $order = Order::firstOrNew(['order_number' => "BATCH-MIGUEL-{$number}"]);
            $order->forceFill([
                'shop_owner_id' => $shop->id,
                'customer_id' => $customer->id,
                'total_amount' => $product->price,
                'shipping_fee' => 0,
                'status' => 'shipped',
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $address->phone,
                'customer_address' => $address->address_line,
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
                'payment_status' => 'paid',
                'carrier_company' => 'Shop-owned logistics',
            ])->save();

            OrderItem::updateOrCreate(['order_id' => $order->id, 'product_id' => $product->id], [
                'product_name' => $product->name,
                'product_slug' => $product->slug,
                'price' => $product->price,
                'quantity' => 1,
                'subtotal' => $product->price,
                'product_image' => $product->main_image,
            ]);

            $shipment = $shipments->ensureRetailOrderShipment($order);
            $shipment->legs()->whereNull('delivery_batch_id')->where('status', 'pending')->update([
                'scheduled_delivery_date' => null,
                'delivery_window' => null,
                'schedule_status' => null,
                'estimated_at' => null,
            ]);
        }

        $this->command?->info('Created 12 unscheduled orders for miguel.rosa@example.com.');
    }
}
