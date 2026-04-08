<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailPosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_checkout_creates_paid_completed_order_and_deducts_stock(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Walk-in Runner',
            'slug' => 'walk-in-runner',
            'price' => 1299,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-pos-001-abc',
                'customer_name' => 'Walk In Buyer',
                'customer_phone' => '09171234567',
                'payment_method' => 'cash',
                'payment_reference' => null,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 1299,
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderId = (int) $response->json('order_id');
        $orderNumber = (string) $response->json('order_number');

        $this->assertTrue(str_starts_with($orderNumber, 'RPOS-'));

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shop_owner_id' => $shopOwner->id,
            'customer_name' => 'Walk In Buyer',
            'payment_status' => 'paid',
            'status' => 'completed',
            'payment_method' => 'cash',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1299,
            'subtotal' => 1299,
        ]);

        $this->assertSame(9, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function checkout_requires_reference_for_non_cash_payments(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Walk-in Sandal',
            'slug' => 'walk-in-sandal',
            'price' => 799,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-pos-002-def',
                'customer_name' => 'GCash Buyer',
                'payment_method' => 'gcash',
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 799,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_reference']);
    }

    #[Test]
    public function checkout_rejects_items_outside_actor_shop_scope(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $foreignProduct = Product::create([
            'shop_owner_id' => $otherShopOwner->id,
            'name' => 'Foreign Shop Shoe',
            'slug' => 'foreign-shop-shoe',
            'price' => 1599,
            'stock_quantity' => 12,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-pos-003-ghi',
                'customer_name' => 'Scoped Buyer',
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $foreignProduct->id,
                    'qty' => 1,
                    'unit_price' => 1599,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
    }

    #[Test]
    public function checkout_rejects_insufficient_stock(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Limited Pair',
            'slug' => 'limited-pair',
            'price' => 999,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-pos-004-jkl',
                'customer_name' => 'Bulk Buyer',
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 2,
                    'unit_price' => 999,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.qty']);

        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }
}
