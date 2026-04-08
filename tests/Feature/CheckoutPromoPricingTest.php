<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\VoucherClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckoutPromoPricingTest extends TestCase
{
    use RefreshDatabase;

    private function createRetailShopOwner(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'paymongo_secret_key' => 'sk_test_checkout_promo_pricing',
        ]);
    }

    #[Test]
    public function promo_preview_returns_sale_then_voucher_totals(): void
    {
        $shopOwner = $this->createRetailShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Preview Promo Sneaker',
            'slug' => 'preview-promo-sneaker-' . random_int(1000, 9999),
            'description' => 'Promo preview test product',
            'price' => 1000,
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
        ]);

        PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'sale',
            'scope' => 'shop_wide',
            'name' => 'Ten Percent Sale',
            'code' => null,
            'discount_mode' => 'percentage',
            'value' => 10,
            'min_spend' => 0,
            'usage_limit' => null,
            'used_count' => 0,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        $voucher = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'PHP100 Voucher',
            'code' => 'LESS100',
            'discount_mode' => 'fixed',
            'value' => 100,
            'min_spend' => 0,
            'usage_limit' => null,
            'used_count' => 0,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        VoucherClaim::create([
            'promo_campaign_id' => $voucher->id,
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'claimed',
            'claimed_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/promo-preview', [
                'items' => [
                    [
                        'pid' => $product->id,
                        'qty' => 1,
                        'price' => 1000,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sale_adjusted_subtotal', 900)
            ->assertJsonPath('data.voucher_discount', 100)
            ->assertJsonPath('data.final_subtotal', 800)
            ->assertJsonPath('data.applied_voucher.code', 'LESS100');
    }

    #[Test]
    public function create_order_auto_applies_claimed_voucher_and_redeems_it(): void
    {
        $shopOwner = $this->createRetailShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Checkout Promo Sneaker',
            'slug' => 'checkout-promo-sneaker-' . random_int(1000, 9999),
            'description' => 'Checkout promo test product',
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $voucher = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Ten Percent Voucher',
            'code' => 'LESS10',
            'discount_mode' => 'percentage',
            'value' => 10,
            'min_spend' => 0,
            'usage_limit' => null,
            'used_count' => 0,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        VoucherClaim::create([
            'promo_campaign_id' => $voucher->id,
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'claimed',
            'claimed_at' => now()->subMinutes(5),
        ]);

        $payload = [
            'items' => [
                [
                    'id' => 'cart-item-1',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                    'size' => null,
                    'color' => null,
                    'image' => null,
                ],
            ],
            'total_amount' => 1000,
            'shipping_fee' => 50,
            'customer_name' => 'Promo Checkout Customer',
            'customer_email' => $customer->email,
            'customer_phone' => '09170000000',
            'shipping_address' => '123 Promo Street, Test City',
            'payment_method' => 'paymongo',
        ];

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $payload);

        $response->assertOk()->assertJsonPath('success', true);

        $orderId = (int) $response->json('order.id');
        $this->assertGreaterThan(0, $orderId);

        $order = Order::query()->findOrFail($orderId);

        $this->assertSame('803.57', number_format((float) $order->total_amount, 2, '.', ''));
        $this->assertSame('96.43', number_format((float) $order->vat_amount, 2, '.', ''));
        $this->assertSame('950.00', number_format((float) $response->json('order.total'), 2, '.', ''));

        $this->assertDatabaseHas('voucher_claims', [
            'promo_campaign_id' => $voucher->id,
            'user_id' => $customer->id,
            'status' => 'redeemed',
        ]);

        $voucher->refresh();
        $this->assertSame(1, (int) $voucher->used_count);
    }
}
