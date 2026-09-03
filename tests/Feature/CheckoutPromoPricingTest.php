<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\VoucherClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    private function createLogisticsShopOwner(): ShopOwner
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
            'paymongo_secret_key' => 'sk_test_checkout_shipping_voucher',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);

        ShopOwnerModule::create([
            'shop_owner_id' => $shopOwner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        return $shopOwner;
    }

    private function createCustomerAddress(User $customer): UserAddress
    {
        return UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Shipping Voucher Customer',
            'phone' => '09170000000',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Barangay 1',
            'postal_code' => '1000',
            'address_line' => '123 Shipping Voucher Street',
            'latitude' => 14.6000,
            'longitude' => 120.9845,
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
    public function promo_preview_describes_voucher_suggestion_status_and_order_eligibility(): void
    {
        $shopOwner = $this->createRetailShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Voucher Suggestion Sneaker',
            'slug' => 'voucher-suggestion-sneaker-' . random_int(1000, 9999),
            'description' => 'Voucher suggestion metadata test product',
            'price' => 1000,
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $claimable = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Claimable Ten',
            'code' => 'CLAIMME',
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

        $claimed = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Claimed Twenty',
            'code' => 'CLAIMED',
            'discount_mode' => 'percentage',
            'value' => 20,
            'min_spend' => 0,
            'usage_limit' => null,
            'used_count' => 0,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        $minimumSpend = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Spend More Voucher',
            'code' => 'MINSPEND',
            'discount_mode' => 'fixed',
            'value' => 100,
            'min_spend' => 1500,
            'usage_limit' => null,
            'used_count' => 0,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        $redeemed = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Already Used Voucher',
            'code' => 'USEDONCE',
            'discount_mode' => 'fixed',
            'value' => 50,
            'min_spend' => 0,
            'usage_limit' => null,
            'used_count' => 1,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);

        VoucherClaim::create([
            'promo_campaign_id' => $claimed->id,
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'claimed',
            'claimed_at' => now()->subMinutes(10),
        ]);
        VoucherClaim::create([
            'promo_campaign_id' => $redeemed->id,
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'redeemed',
            'claimed_at' => now()->subHour(),
            'redeemed_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/promo-preview', [
                'items' => [['pid' => $product->id, 'qty' => 1, 'price' => 1000]],
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $suggestions = collect($response->json('data.voucher_code_suggestions'))->keyBy('code');

        $this->assertSame('claimable', data_get($suggestions->get('CLAIMME'), 'claim_status'));
        $this->assertSame('eligible', data_get($suggestions->get('CLAIMME'), 'eligibility'));
        $this->assertTrue(data_get($suggestions->get('CLAIMME'), 'can_claim'));
        $this->assertSame($product->id, data_get($suggestions->get('CLAIMME'), 'claim_product_id'));

        $this->assertSame('claimed', data_get($suggestions->get('CLAIMED'), 'claim_status'));
        $this->assertFalse(data_get($suggestions->get('CLAIMED'), 'can_claim'));

        $this->assertSame('minimum_spend', data_get($suggestions->get('MINSPEND'), 'eligibility'));
        $this->assertEquals(500.0, data_get($suggestions->get('MINSPEND'), 'remaining_spend'));
        $this->assertTrue(data_get($suggestions->get('MINSPEND'), 'can_claim'));

        $this->assertSame('redeemed', data_get($suggestions->get('USEDONCE'), 'claim_status'));
        $this->assertFalse(data_get($suggestions->get('USEDONCE'), 'can_claim'));
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

    #[Test]
    public function promo_preview_applies_shipping_voucher_to_raw_shipping_fee_only(): void
    {
        $shopOwner = $this->createLogisticsShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();
        $address = $this->createCustomerAddress($customer);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Shipping Promo Sneaker',
            'slug' => 'shipping-promo-sneaker-' . random_int(1000, 9999),
            'description' => 'Shipping voucher preview product',
            'price' => 2000,
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $voucher = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'discount_target' => 'shipping',
            'scope' => 'shop_wide',
            'name' => 'Half Shipping',
            'code' => 'SHIP50',
            'discount_mode' => 'percentage',
            'value' => 50,
            'min_spend' => 1234,
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
                'items' => [['pid' => $product->id, 'qty' => 1, 'price' => 2000]],
                'shipping_fee' => 100,
                'address_id' => $address->id,
                'shipping_latitude' => 14.6000,
                'shipping_longitude' => 120.9845,
                'voucher_campaign_id' => $voucher->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.raw_shipping_fee', 100)
            ->assertJsonPath('data.shipping_voucher_discount', 50)
            ->assertJsonPath('data.discounted_shipping_fee', 50)
            ->assertJsonPath('data.applied_voucher.target', 'shipping')
            ->assertJsonPath('data.voucher_discount', 0)
            ->assertJsonPath('data.final_subtotal', 2000);

        $shippingSuggestion = collect($response->json('data.voucher_code_suggestions'))
            ->firstWhere('code', 'SHIP50');
        $this->assertSame('claimed', data_get($shippingSuggestion, 'claim_status'));
        $this->assertSame('eligible', data_get($shippingSuggestion, 'eligibility'));
        $this->assertSame('Eligible for this delivery.', data_get($shippingSuggestion, 'eligibility_message'));
    }

    #[Test]
    public function create_order_caps_fixed_shipping_voucher_and_persists_free_shipping(): void
    {
        $shopOwner = $this->createLogisticsShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();
        $address = $this->createCustomerAddress($customer);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Free Shipping Sneaker',
            'slug' => 'free-shipping-sneaker-' . random_int(1000, 9999),
            'description' => 'Free shipping order product',
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $voucher = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'discount_target' => 'shipping',
            'scope' => 'shop_wide',
            'name' => 'Free Shipping',
            'code' => 'FREESHIP',
            'discount_mode' => 'fixed',
            'value' => 500,
            'min_spend' => 999,
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

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                'items' => [[
                    'id' => 'free-shipping-item',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                    'size' => null,
                    'color' => null,
                    'image' => null,
                ]],
                'total_amount' => 1000,
                'shipping_fee' => 100,
                'customer_name' => 'Shipping Voucher Customer',
                'customer_email' => $customer->email,
                'customer_phone' => '09170000000',
                'shipping_address' => '123 Shipping Voucher Street, Manila',
                'address_id' => $address->id,
                'shipping_region' => 'NCR',
                'shipping_province' => 'Metro Manila',
                'shipping_city' => 'Manila',
                'shipping_barangay' => 'Barangay 1',
                'shipping_postal_code' => '1000',
                'shipping_address_line' => '123 Shipping Voucher Street',
                'payment_method' => 'cod',
                'voucher_campaign_id' => $voucher->id,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order = Order::query()->findOrFail((int) $response->json('order.id'));
        $this->assertSame('0.00', number_format((float) $order->shipping_fee, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $response->json('order.total') - (float) $order->total_amount - (float) $order->vat_amount, 2, '.', ''));
        $this->assertDatabaseHas('voucher_claims', [
            'promo_campaign_id' => $voucher->id,
            'user_id' => $customer->id,
            'status' => 'redeemed',
        ]);
    }

    #[Test]
    public function shipping_voucher_does_not_apply_without_shop_logistics(): void
    {
        $shopOwner = $this->createRetailShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();
        $address = $this->createCustomerAddress($customer);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Third Party Shipping Sneaker',
            'slug' => 'third-party-shipping-sneaker-' . random_int(1000, 9999),
            'description' => 'No own logistics product',
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);
        $voucher = PromoCampaign::create([
            'shop_owner_id' => $shopOwner->id,
            'kind' => 'voucher',
            'discount_target' => 'shipping',
            'scope' => 'shop_wide',
            'name' => 'Unavailable Shipping Voucher',
            'code' => 'NOLOGI',
            'discount_mode' => 'percentage',
            'value' => 50,
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
            'claimed_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/promo-preview', [
                'items' => [['pid' => $product->id, 'qty' => 1, 'price' => 1000]],
                'shipping_fee' => 100,
                'address_id' => $address->id,
                'shipping_latitude' => 14.6000,
                'shipping_longitude' => 120.9845,
                'voucher_campaign_id' => $voucher->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.shipping_voucher_discount', 0)
            ->assertJsonPath('data.discounted_shipping_fee', 100)
            ->assertJsonPath('data.applied_voucher', null)
            ->assertJsonPath('data.voucher_discount', 0)
            ->assertJsonPath('data.shipping_voucher_error', 'Shipping vouchers require accessible Shop-owned Logistics.');

        $shippingSuggestion = collect($response->json('data.voucher_code_suggestions'))
            ->firstWhere('code', 'NOLOGI');
        $this->assertSame('shipping_unavailable', data_get($shippingSuggestion, 'eligibility'));
        $this->assertSame('Shipping vouchers require accessible Shop-owned Logistics.', data_get($shippingSuggestion, 'eligibility_message'));
        $this->assertFalse(data_get($shippingSuggestion, 'can_claim'));
    }

    #[Test]
    public function retry_payment_keeps_persisted_free_shipping_fee(): void
    {
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_free_shipping_retry',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/free-shipping'],
                ],
            ], 200),
        ]);

        $shopOwner = $this->createLogisticsShopOwner();
        /** @var User $customer */
        $customer = User::factory()->createOne();
        $order = Order::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'total_amount' => 892.86,
            'vat_amount' => 107.14,
            'vat_rate' => 12,
            'shipping_fee' => 0,
            'payment_status' => 'pending',
            'status' => 'pending',
            'payment_method' => 'paymongo',
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/orders/' . $order->id . '/retry-payment-session', [
                'shipping_fee' => 100,
                'subtotal_amount' => 1000,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $order->refresh();
        $this->assertSame('0.00', number_format((float) $order->shipping_fee, 2, '.', ''));

        Http::assertSent(function ($request): bool {
            $lineItems = $request->data()['data']['attributes']['line_items'] ?? [];

            return collect($lineItems)->doesntContain(
                fn (array $lineItem): bool => ($lineItem['name'] ?? null) === 'Shipping Fee'
                    && (int) ($lineItem['amount'] ?? 0) > 0,
            );
        });
    }

    #[Test]
    public function customer_login_keeps_session_for_retry_payment(): void
    {
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_legacy_login_retry',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/legacy-login'],
                ],
            ], 200),
        ]);

        $shopOwner = $this->createRetailShopOwner();
        $customer = User::factory()->create([
            'email' => 'legacy-checkout@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'payment_status' => 'pending',
            'status' => 'pending',
            'payment_method' => 'paymongo',
        ]);

        $loginResponse = $this->postJson('/user/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/orders/'.$order->id.'/retry-payment-session')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
