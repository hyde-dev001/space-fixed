<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductVatInclusiveCheckoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_order_extracts_vat_from_inclusive_product_total(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'paymongo_secret_key' => 'sk_test_product_vat_inclusive',
        ]);

        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'VAT Inclusive Product',
            'slug' => 'vat-inclusive-product-' . random_int(1000, 9999),
            'description' => 'VAT inclusion checkout coverage',
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '42',
            'color' => 'Black',
            'quantity' => 5,
            'is_active' => true,
        ]);

        $payload = [
            'items' => [
                [
                    'id' => 'cart-item-1',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                    'size' => '42',
                    'color' => 'Black',
                    'image' => null,
                ],
            ],
            'total_amount' => 1000,
            'shipping_fee' => 50,
            'customer_name' => 'VAT Inclusive Customer',
            'customer_email' => $customer->email,
            'customer_phone' => '09171230000',
            'shipping_address' => '123 VAT Street, Test City',
            'payment_method' => 'paymongo',
        ];

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $payload);

        $response->assertOk()->assertJsonPath('success', true);

        $orderId = (int) $response->json('order.id');
        $this->assertGreaterThan(0, $orderId);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame($variant->id, OrderItem::where('order_id', $orderId)->value('product_variant_id'));

        $this->assertSame('892.86', number_format((float) $order->total_amount, 2, '.', ''));
        $this->assertSame('107.14', number_format((float) $order->vat_amount, 2, '.', ''));
        $this->assertSame('12.00', number_format((float) $order->vat_rate, 2, '.', ''));
        $this->assertSame('50.00', number_format((float) $order->shipping_fee, 2, '.', ''));
        $this->assertSame('1050.00', number_format((float) $response->json('order.total'), 2, '.', ''));
    }
}
