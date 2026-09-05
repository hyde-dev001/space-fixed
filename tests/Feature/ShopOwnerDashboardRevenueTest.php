<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerDashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dashboard_retail_revenue_excludes_vat_when_order_uses_grand_total(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $this->createOrder($shopOwner->id, [
            'total_amount' => 100,
            'vat_amount' => 12,
            'shipping_fee' => 0,
            'grand_total' => 112,
            'status' => 'processing',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats');

        $response->assertOk();

        $payload = $response->json();

        $this->assertEqualsWithDelta(100.0, (float) ($payload['revenue']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(100.0, (float) ($payload['revenue']['this_month'] ?? 0), 0.01);
    }

    #[Test]
    public function dashboard_adds_only_paid_shop_owned_retail_delivery_revenue(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        foreach ([
            ['paid', 'Shop-owned logistics'],
            ['paid', 'Third-party courier'],
            ['pending', 'Shop-owned logistics'],
        ] as [$paymentStatus, $carrierCompany]) {
            $order = $this->createOrder($shopOwner->id, [
                'total_amount' => 100,
                'vat_amount' => 12,
                'shipping_fee' => 20,
                'payment_status' => $paymentStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $order->forceFill(['carrier_company' => $carrierCompany])->save();
        }

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();

        $this->assertEqualsWithDelta(320.0, (float) $response->json('revenue.total'), 0.01);
    }

    #[Test]
    public function dashboard_keeps_paid_delivery_on_partial_refund_and_removes_it_on_full_refund(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $order = $this->createOrder($shopOwner->id, [
            'total_amount' => 100,
            'vat_amount' => 12,
            'shipping_fee' => 20,
            'payment_status' => 'paid',
        ]);
        $order->forceFill(['carrier_company' => 'Shop-owned logistics'])->save();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'succeeded',
            'amount' => 40,
            'idempotency_key' => 'dashboard-partial-refund',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();
        $this->assertEqualsWithDelta(80.0, (float) $response->json('revenue.total'), 0.01);

        $refund->update(['amount' => 132]);
        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $response->json('revenue.total'), 0.01);
    }

    #[Test]
    public function dashboard_separates_repair_service_and_locked_shop_owned_delivery_revenue(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
        ]);
        $repair = $this->createRepairRequest($shopOwner->id, [
            'request_id' => 'REP-DASH-DELIVERY',
            'payment_status' => 'completed',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'total' => 1120,
            'final_total' => 1120,
            'total_paid_amount' => 1240,
            'total_refunded_amount' => 0,
            'intake_delivery_method' => 'shop_pickup',
            'intake_delivery_fee' => 50,
            'intake_logistics_locked_at' => now(),
            'return_delivery_method' => 'shop_delivery',
            'return_delivery_fee' => 70,
            'return_logistics_locked_at' => now(),
            'pricing_breakdown' => ['tax_mode' => 'vat_inclusive'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();
        $this->assertEqualsWithDelta(1120.0, (float) $response->json('revenue.total'), 0.01);

        $repair->update(['intake_logistics_locked_at' => null]);
        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();
        $this->assertEqualsWithDelta(1070.0, (float) $response->json('revenue.total'), 0.01);

        $repair->update(['total_refunded_amount' => 1240]);
        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $response->json('revenue.total'), 0.01);
    }

    #[Test]
    public function dashboard_revenue_includes_pos_aligned_repair_payments(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $this->createOrder($shopOwner->id, [
            'total_amount' => 100,
            'status' => 'processing',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createRepairRequest($shopOwner->id, [
            'request_id' => 'REP-DASH-REV-0001',
            'payment_status' => 'paid',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'total' => 1000,
            'final_total' => 1000,
            'total_paid_amount' => 500,
            'total_refunded_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createRepairRequest($shopOwner->id, [
            'request_id' => 'REP-DASH-REV-0002',
            'payment_status' => 'completed',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'total' => 800,
            'final_total' => 800,
            'total_paid_amount' => 800,
            'total_refunded_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createRepairRequest($shopOwner->id, [
            'request_id' => 'REP-DASH-REV-0003',
            'payment_status' => 'partially_refunded',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'total' => 1000,
            'final_total' => 1000,
            'total_paid_amount' => 1000,
            'total_refunded_amount' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createRepairRequest($otherShopOwner->id, [
            'request_id' => 'REP-DASH-REV-0004',
            'payment_status' => 'completed',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'total' => 5000,
            'final_total' => 5000,
            'total_paid_amount' => 5000,
            'total_refunded_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats');

        $response->assertOk();

        $payload = $response->json();

        // Retail revenue already excludes VAT via total_amount.
        // Repair revenue now excludes VAT by converting gross paid totals to net.
        // 100 (retail net) + ((500 + 800 + (1000 - 200)) / 1.12) = 1975
        $this->assertEqualsWithDelta(1975.0, (float) ($payload['revenue']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(1975.0, (float) ($payload['revenue']['this_month'] ?? 0), 0.01);
    }

    #[Test]
    public function dashboard_completed_orders_counts_delivered_status_as_completed(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $this->createOrder($shopOwner->id, [
            'status' => 'delivered',
            'payment_status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats');

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame(1, (int) ($payload['orders']['completed'] ?? 0));
    }

    #[Test]
    public function dashboard_completed_orders_exclude_terminal_orders_with_open_refunds(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $this->createOrder($shopOwner->id, [
            'status' => 'delivered',
            'payment_status' => 'completed',
        ]);
        $openRefundOrder = $this->createOrder($shopOwner->id, [
            'status' => 'completed',
            'payment_status' => 'completed',
        ]);

        OrderRefund::create([
            'order_id' => $openRefundOrder->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'approved',
            'return_status' => 'not_required',
            'amount' => 40,
            'idempotency_key' => 'dashboard-open-refund-closure',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/dashboard/stats')
            ->assertOk();

        $this->assertSame(1, (int) ($response->json('orders.completed') ?? 0));
    }

    private function createOrder(int $shopOwnerId, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'shop_owner_id' => $shopOwnerId,
            'order_number' => 'ORD-DASH-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
            'customer_name' => 'Dashboard Customer',
            'customer_email' => 'dashboard@example.test',
            'customer_phone' => '09170000000',
            'total_amount' => 100,
            'status' => 'processing',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ], $overrides));
    }

    private function createRepairRequest(int $shopOwnerId, array $overrides = []): RepairRequest
    {
        return RepairRequest::create(array_merge([
            'request_id' => 'REP-DASH-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'customer_name' => 'Dashboard Repair Customer',
            'email' => 'repair-dashboard@example.test',
            'phone' => '09170000001',
            'shoe_type' => 'Sneakers',
            'brand' => 'TestBrand',
            'description' => 'Dashboard repair revenue test fixture',
            'shop_owner_id' => $shopOwnerId,
            'images' => json_encode([]),
            'total' => 500,
            'final_total' => 500,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
        ], $overrides));
    }
}
