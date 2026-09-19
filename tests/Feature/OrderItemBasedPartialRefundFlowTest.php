<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderRefundService;
use App\Services\PaymentSettlementService;
use App\Services\PaymongoRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderItemBasedPartialRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    #[Test]
    public function online_partial_refund_can_match_subtotal_when_order_has_vat_on_top(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_item_refund_vat',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'VAT Partial Refund Shoe',
            'slug' => 'vat-partial-refund-shoe-' . random_int(1000, 9999),
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-ITEM-REFUND-VAT-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 1000,
            'vat_amount' => 120,
            'shipping_fee' => 0,
            'grand_total' => 1120,
            'status' => 'delivered',
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_item_refund_vat_123',
            'paid_at' => now()->subDay(),
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 500,
            'quantity' => 2,
            'subtotal' => 1000,
            'size' => '42',
            'color' => 'Black',
            'product_image' => null,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->post('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'damaged_item',
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'requested_qty' => 2,
                    ],
                ],
                'media' => $this->buildRefundMedia(),
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $refund = OrderRefund::query()->latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertSame(1000.0, round((float) ($refund?->amount ?? 0), 2));
    }

    #[Test]
    public function refund_line_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('order_refund_items'));
        $this->assertTrue(Schema::hasTable('pos_refund_items'));
    }

    #[Test]
    public function online_full_refund_uses_captured_product_amount_when_voucher_reduced_payment(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_voucher_refund',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Voucher Refund Shoe',
            'slug' => 'voucher-refund-shoe-' . random_int(1000, 9999),
            'price' => 7589.29,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-VOUCHER-REFUND-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 7589.29,
            'vat_amount' => 910.71,
            'shipping_fee' => 98,
            'status' => 'delivered',
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_voucher_refund_123',
            'paid_at' => now()->subDay(),
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 7589.29,
            'quantity' => 1,
            'subtotal' => 7589.29,
            'size' => '43',
            'color' => 'Black',
            'product_image' => null,
        ]);

        $this->mock(PaymongoRefundService::class, function ($mock): void {
            $mock->shouldReceive('getPaymentAmountInCentavos')
                ->once()
                ->andReturn(759800);
        });

        $response = $this->actingAs($customer, 'user')
            ->post('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'damaged_item',
                'request_type' => 'full',
                'media' => $this->buildRefundMedia(),
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $refund = OrderRefund::query()->latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertSame(7598.0, round((float) ($refund?->amount ?? 0), 2));
        $this->assertSame(
            7500.0,
            app(\App\Services\OrderRefundService::class)->resolvePayoutAmount($refund, $order),
        );
    }

    #[Test]
    public function voucher_refund_execution_sends_product_amount_without_shipping(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_voucher_execution',
        ]);
        $customer = User::factory()->create();
        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-VOUCHER-EXEC-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 7589.29,
            'vat_amount' => 910.71,
            'shipping_fee' => 98,
            'status' => 'delivered',
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_voucher_execution_123',
            'paid_at' => now()->subDay(),
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'amount' => 8598,
            'reason_code' => 'damaged_item',
        ]);

        $this->mock(PaymongoRefundService::class, function ($mock): void {
            $mock->shouldReceive('getPaymentAmountInCentavos')
                ->once()
                ->andReturn(759800);
            $mock->shouldReceive('createRefund')
                ->once()
                ->withArgs(fn ($key, $paymentId, $amount) => $key === 'sk_test_voucher_execution'
                    && $paymentId === 'pay_voucher_execution_123'
                    && $amount === 750000)
                ->andReturn([
                    'success' => true,
                    'status' => 'succeeded',
                    'refund_id' => 're_voucher_product_only',
                ]);
        });
        $this->mock(PaymentSettlementService::class, function ($mock): void {
            $mock->shouldReceive('settleOrderRefunded')->once();
        });

        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh());

        $this->assertSame('refunded', $result['result']);
        $this->assertSame(7500.0, (float) $refund->fresh()->amount);
    }

    #[Test]
    public function voucher_partial_refund_line_amount_is_not_prorated_twice_during_execution(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_voucher_partial_execution',
        ]);
        $customer = User::factory()->create();
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Voucher Partial Execution Shoe',
            'slug' => 'voucher-partial-execution-shoe-' . random_int(1000, 9999),
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-VOUCHER-PARTIAL-EXEC-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 1000,
            'vat_amount' => 0,
            'shipping_fee' => 50,
            'status' => 'delivered',
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_voucher_partial_execution_123',
            'paid_at' => now()->subDay(),
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 500,
            'quantity' => 2,
            'subtotal' => 1000,
            'size' => '43',
            'color' => 'Black',
            'product_image' => null,
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'amount' => 400,
            'reason_code' => 'damaged_item',
        ]);
        $refund->items()->create([
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'requested_qty' => 1,
            'approved_qty' => 1,
            'unit_price_snapshot' => 500,
            'line_amount' => 400,
            'inspection_disposition' => 'resellable',
            'inventory_action' => 'restock',
        ]);

        $this->mock(PaymongoRefundService::class, function ($mock): void {
            $mock->shouldReceive('getPaymentAmountInCentavos')->zeroOrMoreTimes()->andReturn(85000);
            $mock->shouldReceive('createRefund')
                ->once()
                ->withArgs(fn ($key, $paymentId, $amount) => $key === 'sk_test_voucher_partial_execution'
                    && $paymentId === 'pay_voucher_partial_execution_123'
                    && $amount === 40000)
                ->andReturn([
                    'success' => true,
                    'status' => 'succeeded',
                    'refund_id' => 're_voucher_partial_product_only',
                ]);
        });
        $this->mock(PaymentSettlementService::class, function ($mock): void {
            $mock->shouldReceive('settleOrderRefunded')->once();
        });

        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh());

        $this->assertSame('refunded', $result['result']);
        $this->assertSame(400.0, (float) $refund->fresh()->amount);
    }

    #[Test]
    public function online_partial_refund_requires_line_qty_payload_and_derives_amount(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_item_refund',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Damaged Test Shoe',
            'slug' => 'damaged-test-shoe-' . random_int(1000, 9999),
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-ITEM-REFUND-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 1000,
            'shipping_fee' => 0,
            'status' => 'delivered',
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_item_refund_123',
            'paid_at' => now()->subDay(),
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 500,
            'quantity' => 2,
            'subtotal' => 1000,
            'size' => '42',
            'color' => 'Black',
            'product_image' => null,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->post('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'damaged_item',
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'requested_qty' => 1,
                    ],
                ],
                'media' => $this->buildRefundMedia(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('order_refund_items', [
            'order_item_id' => $orderItem->id,
            'requested_qty' => 1,
            'approved_qty' => 1,
            'inspection_disposition' => 'pending',
            'inventory_action' => 'pending',
        ]);

        $refund = OrderRefund::query()->latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertSame(500.0, round((float) ($refund?->amount ?? 0), 2));
    }

    #[Test]
    public function online_full_refund_path_still_works_without_line_payload(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_item_refund_full',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Full Refund Test Shoe',
            'slug' => 'full-refund-test-shoe-' . random_int(1000, 9999),
            'price' => 700,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-FULL-REFUND-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 1400,
            'shipping_fee' => 50,
            'status' => 'delivered',
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_item_refund_full_123',
            'paid_at' => now()->subDay(),
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 700,
            'quantity' => 2,
            'subtotal' => 1400,
            'size' => '43',
            'color' => 'Blue',
            'product_image' => null,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->post('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'changed_mind',
                'request_type' => 'full',
                'media' => $this->buildRefundMedia(),
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $refund = OrderRefund::query()->latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertSame(1450.0, round((float) ($refund?->amount ?? 0), 2));

        $this->assertDatabaseMissing('order_refund_items', [
            'order_refund_id' => (int) ($refund?->id ?? 0),
        ]);
    }

    #[Test]
    public function second_partial_refund_cannot_exceed_remaining_qty(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_item_refund_2',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Cap Test Shoe',
            'slug' => 'cap-test-shoe-' . random_int(1000, 9999),
            'price' => 600,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);

        $order = Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-CAP-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 1200,
            'shipping_fee' => 0,
            'status' => 'delivered',
            'payment_method' => 'paymongo_wallet',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_item_refund_cap',
            'paid_at' => now()->subDay(),
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 600,
            'quantity' => 2,
            'subtotal' => 1200,
            'size' => '41',
            'color' => 'White',
            'product_image' => null,
        ]);

        $firstResponse = $this->actingAs($customer, 'user')
            ->post('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'damaged_item',
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'requested_qty' => 1,
                    ],
                ],
                'media' => $this->buildRefundMedia(),
            ]);

        $firstResponse->assertStatus(200);

        $latestRefund = OrderRefund::query()->latest('id')->firstOrFail();
        $latestRefund->update([
            'status' => 'succeeded',
            'approved_at' => now(),
            'refunded_at' => now(),
        ]);

        $secondResponse = $this->actingAs($customer, 'user')
            ->post('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'damaged_item',
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'requested_qty' => 2,
                    ],
                ],
                'media' => $this->buildRefundMedia(),
            ]);

        $secondResponse->assertStatus(422)
            ->assertJsonPath('message', 'Requested qty exceeds remaining refundable quantity for one or more items.');
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function buildRefundMedia(): array
    {
        return [
            UploadedFile::fake()->create('evidence-1.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-2.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-3.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-4.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-5.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-6.mp4', 512, 'video/mp4'),
        ];
    }
}
