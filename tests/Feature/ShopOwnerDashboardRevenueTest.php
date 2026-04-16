<?php

namespace Tests\Feature;

use App\Models\Order;
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
