<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentLifecycleFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createShopOwner(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'both',
            'registration_type' => 'individual',
            'paymongo_secret_key' => 'sk_test_lifecycle',
        ], $overrides));
    }

    private function createOrder(ShopOwner $shopOwner, User $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'total_amount' => 1200,
            'status' => 'pending',
            'payment_method' => 'paymongo',
            'payment_status' => 'pending',
            'paymongo_link_id' => 'plink_' . random_int(10000, 99999),
            'payment_link_created_at' => now()->subMinutes(10),
            'payment_expires_at' => now()->addMinutes(50),
        ], $overrides));
    }

    private function createRepairRequest(ShopOwner $shopOwner, User $customer, array $overrides = []): RepairRequest
    {
        return RepairRequest::create(array_merge([
            'request_id' => 'REP-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'brand' => 'TestBrand',
            'description' => 'Lifecycle test repair',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'paymongo_link_id' => 'rplink_' . random_int(10000, 99999),
            'payment_link_created_at' => now()->subMinutes(10),
            'payment_expires_at' => now()->addMinutes(50),
            'payment_status' => 'pending',
            'payment_enabled' => true,
            'payment_enabled_at' => now()->subMinutes(15),
            'payment_policy' => 'deposit_50',
        ], $overrides));
    }

    private function paidWebhookPayload(string $paymentLinkId, ?string $paymentId = null): array
    {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'link.payment.paid',
                    'data' => [
                        'id' => $paymentId ?? ('pay_' . random_int(10000, 99999)),
                        'attributes' => [
                            'payment_link_id' => $paymentLinkId,
                            'amount' => 100000,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function failedWebhookPayload(string $paymentLinkId): array
    {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'link.payment.failed',
                    'data' => [
                        'id' => 'fail_' . random_int(10000, 99999),
                        'attributes' => [
                            'payment_link_id' => $paymentLinkId,
                        ],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function order_paid_webhook_settles_and_is_idempotent(): void
    {
        config()->set('services.paymongo.webhook_secret', '');

        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        $order = $this->createOrder($shopOwner, $customer);

        $payload = $this->paidWebhookPayload((string) $order->paymongo_link_id, 'pay_order_123');

        $first = $this->postJson('/api/webhooks/paymongo', $payload);
        $first->assertOk()->assertJson(['message' => 'Payment processed']);

        $order->refresh();
        $firstPaidAt = $order->paid_at;

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pay_order_123', $order->paymongo_payment_id);
        $this->assertNotNull($firstPaidAt);

        $second = $this->postJson('/api/webhooks/paymongo', $payload);
        $second->assertOk()->assertJson(['message' => 'Already paid']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pay_order_123', $order->paymongo_payment_id);
        $this->assertEquals($firstPaidAt?->format('Y-m-d H:i:s'), $order->paid_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function order_failed_webhook_records_failure(): void
    {
        config()->set('services.paymongo.webhook_secret', '');

        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        $order = $this->createOrder($shopOwner, $customer);

        $response = $this->postJson('/api/webhooks/paymongo', $this->failedWebhookPayload((string) $order->paymongo_link_id));
        $response->assertOk()->assertJson(['message' => 'Order payment failure recorded']);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNotNull($order->payment_failed_at);
        $this->assertSame('paymongo_payment_failed', $order->payment_failure_reason);
    }

    #[Test]
    public function order_late_paid_webhook_after_expiry_is_ignored(): void
    {
        config()->set('services.paymongo.webhook_secret', '');

        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        $order = $this->createOrder($shopOwner, $customer, [
            'payment_expires_at' => now()->subHour(),
            'payment_expired_at' => now()->subMinutes(30),
        ]);

        $response = $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload((string) $order->paymongo_link_id, 'pay_order_late'));
        $response->assertOk()->assertJson(['message' => 'Expired payment session']);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->paymongo_payment_id);
    }

    #[Test]
    public function repair_paid_webhook_deposit_flow_is_idempotent_until_next_phase_is_due(): void
    {
        config()->set('services.paymongo.webhook_secret', '');

        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'payment_policy' => 'deposit_50',
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $payload = $this->paidWebhookPayload((string) $repair->paymongo_link_id, 'pay_repair_123');

        $first = $this->postJson('/api/webhooks/paymongo', $payload);
        $first->assertOk()->assertJson(['message' => 'Repair payment processed']);

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status);

        $second = $this->postJson('/api/webhooks/paymongo', $payload);
        $second->assertOk()->assertJson(['message' => 'No payable phase due']);

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status);
    }

    #[Test]
    public function repair_failed_webhook_records_failure_state(): void
    {
        config()->set('services.paymongo.webhook_secret', '');

        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        $repair = $this->createRepairRequest($shopOwner, $customer);

        $response = $this->postJson('/api/webhooks/paymongo', $this->failedWebhookPayload((string) $repair->paymongo_link_id));
        $response->assertOk()->assertJson(['message' => 'Repair payment failure recorded']);

        $repair->refresh();
        $this->assertSame('failed', (string) $repair->payment_status);
        $this->assertNotNull($repair->payment_failed_at);
        $this->assertSame('paymongo_payment_failed', (string) $repair->payment_failure_reason);
    }

    #[Test]
    public function cod_order_is_marked_paid_when_customer_confirms_delivery(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();

        $order = $this->createOrder($shopOwner, $customer, [
            'status' => OrderStatus::SHIPPED,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/confirm-delivery', [
                'order_id' => $order->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();

        $this->assertSame(OrderStatus::DELIVERED, $order->status);
        $this->assertSame('paid', (string) $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    #[Test]
    public function repairer_can_mark_repair_paid_in_shop_for_due_phase(): void
    {
        $shopOwner = $this->createShopOwner(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
            'role' => 'STAFF',
        ]);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'assigned_repairer_id' => $repairer->id,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_status' => 'pending',
            'payment_expires_at' => now()->subHour(),
            'payment_expired_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-paid-in-shop");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'In-shop payment recorded successfully.');

        $repair->refresh();

        $this->assertSame('paid', (string) $repair->payment_status);
        $this->assertNotNull($repair->payment_completed_at);
        $this->assertNotNull($repair->paymongo_payment_id);
        $this->assertStringStartsWith('in_shop_manual_', (string) $repair->paymongo_payment_id);
    }

    #[Test]
    public function cleanup_command_releases_order_stock_once_and_is_idempotent(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Lifecycle Test Shoe',
            'slug' => 'lifecycle-test-shoe-' . random_int(1000, 9999),
            'description' => 'Test product for stock release',
            'price' => 500,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $order = $this->createOrder($shopOwner, $customer, [
            'total_amount' => 1000,
            'payment_expires_at' => now()->subHour(),
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 500,
            'quantity' => 2,
            'subtotal' => 1000,
        ]);

        $this->artisan('payments:expire-stale')->assertExitCode(0);

        $order->refresh();
        $product->refresh();

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertNotNull($order->payment_expired_at);
        $this->assertNotNull($order->payment_released_at);
        $this->assertSame(7, (int) $product->stock_quantity);

        $this->artisan('payments:expire-stale')->assertExitCode(0);

        $order->refresh();
        $product->refresh();

        $this->assertSame(7, (int) $product->stock_quantity);
    }

    #[Test]
    public function cleanup_command_releases_repair_assignment_capacity_on_expiry(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        $repairer = User::factory()->create();
        $manager = User::factory()->create();

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'assigned_repairer_id' => $repairer->id,
            'assigned_manager_id' => $manager->id,
            'assigned_at' => now()->subDay(),
            'payment_expires_at' => now()->subHour(),
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->artisan('payments:expire-stale')->assertExitCode(0);

        $repair->refresh();

        $this->assertSame('cancelled', (string) $repair->status);
        $this->assertSame('expired', (string) $repair->payment_status);
        $this->assertNotNull($repair->payment_expired_at);
        $this->assertNull($repair->assigned_repairer_id);
        $this->assertNull($repair->assigned_manager_id);
        $this->assertNull($repair->assigned_at);
    }
}
