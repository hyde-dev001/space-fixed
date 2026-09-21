<?php

namespace Tests\Feature\PlatformFee;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\SuperAdmin;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlatformFeePaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-dashboard', 'user');
        config()->set('services.paymongo.secret_key', 'sk_test_platform');
    }

    #[Test]
    public function business_payment_requires_owner_approval_and_settles_the_exact_snapshot(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $this->clockInEmployee($finance);
        $order = $this->createMarketplaceOrder($shop, 1000);

        $request = $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests')
            ->assertCreated()
            ->json('payment_request');

        $this->assertSame('56.00', $request['net_payable_snapshot']);

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/shop-owner/finance/platform-balance/payment-requests/'.$request['id'].'/approve')
            ->assertOk()
            ->assertJsonPath('payment_request.status', 'owner_approved');

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_platform_1',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/cs_platform_1'],
                ],
            ], 200),
        ]);

        $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests/'.$request['id'].'/execute')
            ->assertOk()
            ->assertJsonPath('payment.checkout_url', 'https://paymongo.test/cs_platform_1');

        $this->postJson('/api/webhooks/paymongo', [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_platform_1',
                        'attributes' => [
                            'payments' => [[
                                'id' => 'pay_platform_1',
                                'attributes' => ['amount' => 5600, 'currency' => 'PHP'],
                            ]],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('platform_fee_payment_requests', [
            'id' => $request['id'],
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('platform_fee_payments', [
            'shop_id' => $shop->id,
            'provider_checkout_id' => 'cs_platform_1',
            'provider_payment_id' => 'pay_platform_1',
            'status' => 'paid',
            'amount' => '56.00',
        ]);
        $this->assertDatabaseHas('platform_fee_payment_allocations', [
            'allocation_type' => 'payment',
            'amount' => '56.00',
        ]);

        $this->assertSame('0.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['net_payable']);
        $this->assertNotNull($order->fresh());
    }

    #[Test]
    public function an_amount_change_stales_an_approved_business_payment_before_checkout(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $this->clockInEmployee($finance);
        $this->createMarketplaceOrder($shop, 1000);

        $request = $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests')
            ->assertCreated()
            ->json('payment_request');

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/shop-owner/finance/platform-balance/payment-requests/'.$request['id'].'/approve')
            ->assertOk();

        $this->createMarketplaceOrder($shop, 1000);

        Http::fake();
        $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests/'.$request['id'].'/execute')
            ->assertStatus(409)
            ->assertJsonPath('error', 'PAYMENT_REQUEST_STALE');

        $this->assertDatabaseHas('platform_fee_payment_requests', [
            'id' => $request['id'],
            'status' => 'stale',
        ]);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_individual_owner_can_start_one_full_balance_payment_without_finance_approval(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $this->createMarketplaceOrder($shop, 1000);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_individual_1',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/cs_individual_1'],
                ],
            ], 200),
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/shop-owner/finance/platform-balance/pay')
            ->assertOk()
            ->assertJsonPath('payment.amount', '56.00')
            ->assertJsonPath('payment.checkout_url', 'https://paymongo.test/cs_individual_1');

        $this->assertDatabaseCount('platform_fee_payment_requests', 0);
        $this->assertDatabaseHas('platform_fee_payments', [
            'shop_id' => $shop->id,
            'amount' => '56.00',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function an_individual_owner_can_reconcile_a_paid_checkout_when_the_webhook_did_not_arrive(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_name' => 'SoleSpace Test Shop',
        ]);
        $this->createMarketplaceOrder($shop, 1000);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_individual_reconcile',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/cs_individual_reconcile'],
                ],
            ], 200),
            'https://api.paymongo.com/v1/checkout_sessions/cs_individual_reconcile' => Http::response([
                'data' => [
                    'id' => 'cs_individual_reconcile',
                    'attributes' => [
                        'payment_status' => 'paid',
                        'payments' => [[
                            'id' => 'pay_individual_reconcile',
                            'attributes' => [
                                'amount' => 5600,
                                'currency' => 'PHP',
                                'status' => 'paid',
                            ],
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $paymentId = $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/shop-owner/finance/platform-balance/pay')
            ->assertOk()
            ->json('payment.id');

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/shop-owner/finance/platform-balance/reconcile', ['payment_id' => $paymentId])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');

        $this->assertDatabaseHas('platform_fee_payments', [
            'id' => $paymentId,
            'provider_payment_id' => 'pay_individual_reconcile',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('platform_fee_payment_allocations', [
            'platform_fee_payment_id' => $paymentId,
            'allocation_type' => 'payment',
            'amount' => '56.00',
        ]);
        $this->assertSame('0.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['net_payable']);
        $this->assertDatabaseHas('notifications', [
            'super_admin_id' => $admin->id,
            'type' => NotificationType::PLATFORM_BALANCE_ALERT->value,
            'title' => 'Platform Balance Payment Received',
            'message' => 'SoleSpace Test Shop paid the Platform Balance. The payment was confirmed and allocated to the Platform Fee ledger.',
            'action_url' => '/admin/platform-fees',
        ]);
    }

    #[Test]
    public function a_failed_business_checkout_can_be_retried_without_new_owner_approval(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $this->clockInEmployee($finance);
        $this->createMarketplaceOrder($shop, 1000);

        $request = $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests')
            ->assertCreated()
            ->json('payment_request');

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/shop-owner/finance/platform-balance/payment-requests/'.$request['id'].'/approve')
            ->assertOk();

        Http::fakeSequence()
            ->push([], 500)
            ->push([
                'data' => [
                    'id' => 'cs_retry',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/cs_retry'],
                ],
            ], 200);

        $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests/'.$request['id'].'/execute')
            ->assertStatus(422);

        $this->assertDatabaseHas('platform_fee_payment_requests', [
            'id' => $request['id'],
            'status' => 'payment_pending',
        ]);
        $this->assertDatabaseHas('platform_fee_payments', [
            'shop_id' => $shop->id,
            'status' => 'failed',
        ]);

        $this->actingAs($finance, 'user')
            ->postJson('/api/finance/platform-balance/payment-requests/'.$request['id'].'/execute')
            ->assertOk()
            ->assertJsonPath('payment.checkout_url', 'https://paymongo.test/cs_retry');

        $this->assertDatabaseHas('platform_fee_payments', [
            'shop_id' => $shop->id,
            'provider_checkout_id' => 'cs_retry',
            'status' => 'pending',
        ]);
    }

    private function createMarketplaceOrder(ShopOwner $shop, int $total): Order
    {
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => $total,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $order->update(['status' => 'delivered']);

        return $order;
    }
}
