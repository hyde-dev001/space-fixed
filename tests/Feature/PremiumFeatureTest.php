<?php

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PremiumFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function createPlan(array $overrides = []): PremiumPlan
    {
        return PremiumPlan::create(array_merge([
            'plan_code' => 'basic',
            'name' => 'Basic',
            'description' => 'Starter premium plan',
            'price' => 249,
            'duration_days' => 15,
            'showroom_slot_limit' => 48,
            'status' => 'active',
        ], $overrides));
    }

    protected function createShopOwner(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ], $overrides));
    }

    protected function createProduct(ShopOwner $shopOwner, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Premium Test Shoe ' . uniqid(),
            'description' => 'Premium feature test product',
            'price' => 1000,
            'brand' => 'SoleSpace',
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
        ], $overrides));
    }

    protected function createActiveSubscription(ShopOwner $shopOwner, PremiumPlan $plan, array $overrides = []): ShopOwnerSubscription
    {
        return ShopOwnerSubscription::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'paymongo_session_id' => 'cs_' . uniqid(),
            'paymongo_payment_id' => 'pay_' . uniqid(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays($plan->duration_days),
        ], $overrides));
    }

    protected function extractInertiaPageData(string $html): array
    {
        preg_match('/data-page="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'Unable to locate Inertia data-page payload.');

        $decoded = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $page = json_decode($decoded, true);

        $this->assertIsArray($page, 'Unable to decode Inertia page payload.');

        return $page;
    }

    /** @test */
    public function premium_checkout_creates_pending_subscription_and_sends_paymongo_metadata(): void
    {
        $shopOwner = $this->createShopOwner();
        $plan = $this->createPlan();

        $this->actingAs($shopOwner, 'shop_owner');

        config()->set('services.paymongo.secret_key', 'sk_test_premium_checkout');

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'attributes' => [
                        'checkout_url' => 'https://paymongo.test/checkout/cs_test_123',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/shop-owner/premium/checkout', [
            'plan_code' => $plan->plan_code,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'checkout_url' => 'https://paymongo.test/checkout/cs_test_123',
                'session_id' => 'cs_test_123',
            ]);

        $subscription = ShopOwnerSubscription::first();

        $this->assertNotNull($subscription);
        $this->assertSame('pending', $subscription->status);
        $this->assertSame('cs_test_123', $subscription->paymongo_session_id);
        $this->assertSame($plan->showroom_slot_limit, $subscription->showroom_slot_limit);

        $payment = ShopOwnerSubscriptionPayment::query()
            ->where('subscription_id', $subscription->id)
            ->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('new_subscription', $payment->payment_type);
        $this->assertSame('cs_test_123', $payment->paymongo_session_id);

        Http::assertSent(function ($request) use ($subscription, $shopOwner, $plan) {
            $payload = $request->data();

            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('sk_test_premium_checkout:'))
                && ($payload['data']['attributes']['metadata']['type'] ?? null) === 'premium_subscription'
                && ($payload['data']['attributes']['metadata']['subscription_id'] ?? null) === (string) $subscription->id
                && ($payload['data']['attributes']['metadata']['shop_owner_id'] ?? null) === (string) $shopOwner->id
                && ($payload['data']['attributes']['metadata']['plan_code'] ?? null) === $plan->plan_code;
        });
    }

    /** @test */
    public function paid_webhook_activates_subscription_once_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');

        $shopOwner = $this->createShopOwner();
        $plan = $this->createPlan([
            'plan_code' => 'pro',
            'name' => 'Pro',
            'duration_days' => 30,
            'showroom_slot_limit' => 60,
        ]);

        $subscription = ShopOwnerSubscription::create([
            'shop_owner_id' => $shopOwner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'pending',
            'paymongo_session_id' => 'cs_webhook_123',
        ]);

        $payment = ShopOwnerSubscriptionPayment::create([
            'shop_owner_id' => $shopOwner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_session_id' => 'cs_webhook_123',
            'plan_price' => $plan->price,
            'amount_due' => $plan->price,
            'status' => 'pending',
        ]);

        $payload = [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_webhook_123',
                        'attributes' => [
                            'metadata' => [
                                'subscription_id' => (string) $subscription->id,
                                'shop_owner_id' => (string) $shopOwner->id,
                                'plan_code' => $plan->plan_code,
                                'payment_record_id' => (string) $payment->id,
                            ],
                            'payments' => [
                                ['id' => 'pay_test_123', 'attributes' => [
                                    'amount' => 24900,
                                    'currency' => 'PHP',
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $firstResponse = $this->postJson('/api/webhooks/paymongo', $payload);

        $firstResponse->assertOk()->assertJson([
            'message' => 'Subscription activated',
        ]);

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertSame('pay_test_123', $subscription->paymongo_payment_id);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_test_123', $payment->fresh()->paymongo_payment_id);
        $this->assertTrue((bool) $subscription->auto_renew);
        $this->assertSame(ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED, $subscription->auto_renew_status);
        $this->assertEquals('2026-03-15 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-14 10:00:00', $subscription->ends_at?->format('Y-m-d H:i:s'));

        $secondResponse = $this->postJson('/api/webhooks/paymongo', $payload);

        $secondResponse->assertOk()->assertJson([
            'message' => 'Already processed',
        ]);

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertSame('pay_test_123', $subscription->paymongo_payment_id);
        $this->assertTrue((bool) $subscription->auto_renew);
        $this->assertSame(ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED, $subscription->auto_renew_status);
        $this->assertEquals('2026-03-15 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-14 10:00:00', $subscription->ends_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    /** @test */
    public function virtual_showroom_route_requires_active_premium_but_allows_eligible_shops_with_one(): void
    {
        $shopOwner = $this->createShopOwner();

        $blocked = $this->get('/shop-profile/' . $shopOwner->id . '/virtual-showroom');
        $blocked->assertStatus(403);

        $plan = $this->createPlan();
        $this->createActiveSubscription($shopOwner, $plan);
        $this->createProduct($shopOwner);

        $allowed = $this->get('/shop-profile/' . $shopOwner->id . '/virtual-showroom');
        $allowed->assertOk();
    }

    /** @test */
    public function settings_page_exposes_real_premium_status_data(): void
    {
        Carbon::setTestNow('2026-03-15 08:00:00');

        $shopOwner = $this->createShopOwner();
        $plan = $this->createPlan([
            'plan_code' => 'premium',
            'name' => 'Premium',
            'duration_days' => 30,
            'showroom_slot_limit' => 84,
        ]);

        $this->createActiveSubscription($shopOwner, $plan, [
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
        ]);

        $this->actingAs($shopOwner, 'shop_owner');

        $response = $this->get('/shop-owner/settings');

        $response->assertOk();

        $page = $this->extractInertiaPageData($response->getContent());

        $this->assertSame('ShopOwner/Settings/shopSetting', $page['component'] ?? null);
        $this->assertTrue($page['props']['shop_settings']['premium']['eligible'] ?? false);
        $this->assertTrue($page['props']['shop_settings']['premium']['has_active'] ?? false);
        $this->assertSame('active', $page['props']['shop_settings']['premium']['status'] ?? null);
        $this->assertSame('Premium', $page['props']['shop_settings']['premium']['plan_name'] ?? null);
        $this->assertSame('premium', $page['props']['shop_settings']['premium']['plan_code'] ?? null);
        $this->assertSame(84, $page['props']['shop_settings']['premium']['showroom_slot_limit'] ?? null);
        $this->assertTrue($page['props']['shop_settings']['premium']['auto_renew'] ?? false);
        $this->assertSame('enabled', $page['props']['shop_settings']['premium']['auto_renew_status'] ?? null);

        Carbon::setTestNow();
    }

    /** @test */
    public function active_subscription_auto_renew_can_be_toggled_on_and_off(): void
    {
        $shopOwner = $this->createShopOwner();
        $plan = $this->createPlan();

        $subscription = $this->createActiveSubscription($shopOwner, $plan, [
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
        ]);

        $this->actingAs($shopOwner, 'shop_owner');

        $disable = $this->patchJson('/api/shop-owner/premium/auto-renew', [
            'enabled' => false,
        ]);

        $disable->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $subscription->refresh();
        $this->assertFalse((bool) $subscription->auto_renew);
        $this->assertSame(ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED, $subscription->auto_renew_status);
        $this->assertSame('active', $subscription->status);

        $enable = $this->patchJson('/api/shop-owner/premium/auto-renew', [
            'enabled' => true,
        ]);

        $enable->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $subscription->refresh();
        $this->assertTrue((bool) $subscription->auto_renew);
        $this->assertSame(ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED, $subscription->auto_renew_status);
        $this->assertSame('active', $subscription->status);
    }

    /** @test */
    public function cancelled_entitled_subscription_cannot_reenable_auto_renew_while_refund_is_unresolved(): void
    {
        $shopOwner = $this->createShopOwner();
        $plan = $this->createPlan();
        $subscription = $this->createActiveSubscription($shopOwner, $plan, [
            'status' => 'cancelled',
            'auto_renew' => false,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED,
            'ends_at' => now()->addDays(10),
        ]);
        $payment = ShopOwnerSubscriptionPayment::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_payment_id' => 'pay-auto-renew-refund',
            'amount_due' => 249,
            'amount_paid' => 249,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);
        ShopOwnerSubscriptionRefund::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'local_reference' => (string) fake()->uuid(),
            'amount' => 249,
            'currency' => 'PHP',
            'business_reason' => 'Pending provider verification.',
            'provider_reason' => 'others',
            'status' => 'unknown',
            'initiated_at' => now()->subMinute(),
        ]);

        $this->actingAs($shopOwner, 'shop_owner');

        $this->patchJson('/api/shop-owner/premium/auto-renew', ['enabled' => true])
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertFalse((bool) $subscription->fresh()->auto_renew);
    }

    /** @test */
    public function showroom_slot_limit_blocks_featuring_more_products_than_allowed(): void
    {
        $shopOwner = $this->createShopOwner();
        $plan = $this->createPlan([
            'showroom_slot_limit' => 1,
        ]);

        $this->createActiveSubscription($shopOwner, $plan, [
            'showroom_slot_limit' => 1,
        ]);

        $this->createProduct($shopOwner, [
            'name' => 'Featured Product',
            'is_featured' => true,
        ]);

        $candidate = $this->createProduct($shopOwner, [
            'name' => 'Candidate Product',
            'is_featured' => false,
        ]);

        $this->actingAs($shopOwner, 'shop_owner');

        $response = $this->putJson('/api/shop-owner/products/' . $candidate->id, [
            'is_featured' => true,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'slot_limit' => 1,
                'used_slots' => 1,
            ]);

        $this->assertFalse((bool) $candidate->fresh()->is_featured);
    }

    /** @test */
    public function repair_only_shops_cannot_purchase_or_use_premium_entitlements(): void
    {
        $shopOwner = $this->createShopOwner([
            'business_type' => 'repair',
        ]);
        $plan = $this->createPlan();

        $this->actingAs($shopOwner, 'shop_owner');

        $webResponse = $this->get('/shop-owner/premium-benefits');
        $webResponse->assertRedirect('/shop-owner/settings');

        $apiPlansResponse = $this->getJson('/api/shop-owner/premium/plans');
        $apiPlansResponse->assertStatus(403);

        $apiCheckoutResponse = $this->postJson('/api/shop-owner/premium/checkout', [
            'plan_code' => $plan->plan_code,
        ]);
        $apiCheckoutResponse->assertStatus(403);
    }

    /** @test */
    public function plans_api_returns_active_plan_benefits_in_order(): void
    {
        $shopOwner = $this->createShopOwner();
        $this->createPlan([
            'benefits' => ['First benefit', 'Second benefit'],
        ]);
        $this->createPlan([
            'plan_code' => 'hidden',
            'name' => 'Hidden',
            'status' => 'inactive',
            'benefits' => ['Hidden benefit'],
        ]);

        $this->actingAs($shopOwner, 'shop_owner');

        $response = $this->getJson('/api/shop-owner/premium/plans');

        $response->assertOk()
            ->assertJsonPath('plans.0.benefits', ['First benefit', 'Second benefit'])
            ->assertJsonMissing(['plan_code' => 'hidden']);
    }
}
