<?php

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
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
                            ],
                            'payments' => [
                                ['id' => 'pay_test_123'],
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
        $this->assertEquals('2026-03-15 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-14 10:00:00', $subscription->ends_at?->format('Y-m-d H:i:s'));

        $secondResponse = $this->postJson('/api/webhooks/paymongo', $payload);

        $secondResponse->assertOk()->assertJson([
            'message' => 'Already processed',
        ]);

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertSame('pay_test_123', $subscription->paymongo_payment_id);
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

        Carbon::setTestNow();
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
}
