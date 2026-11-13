<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcurementDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_tenant_scoped_six_month_procurement_read_model(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 2)->setTime(12, 0));

        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending_finance',
            'total_cost' => 1250,
            'created_at' => now()->subMonths(2)->setDay(5),
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'approved',
            'total_cost' => 900,
            'created_at' => now()->subMonth()->setDay(8),
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $foreignShop->id,
            'status' => 'pending_finance',
            'created_at' => now()->subMonth()->setDay(10),
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'in_transit',
            'total_cost' => 2400,
            'created_at' => now()->subMonth()->setDay(12),
        ]);
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'cancelled',
            'total_cost' => 500,
            'created_at' => now()->subMonths(3)->setDay(14),
        ]);
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $foreignShop->id,
            'supplier_id' => Supplier::factory()->create(['shop_owner_id' => $foreignShop->id])->id,
            'status' => 'in_transit',
            'total_cost' => 9900,
        ]);

        $dashboard = app(\App\Services\ProcurementDashboardService::class)->forShopOwner($shop->id);

        $this->assertSame(2, $dashboard['summary']['purchase_requests']);
        $this->assertSame(1, $dashboard['summary']['awaiting_review']);
        $this->assertSame(2, $dashboard['summary']['purchase_orders']);
        $this->assertSame('2400.00', (string) $dashboard['summary']['open_order_value']);
        $this->assertSame('Last 6 months', $dashboard['trend']['period_label']);
        $this->assertCount(6, $dashboard['trend']['months']);
        $this->assertSame(1, $dashboard['trend']['months'][3]['purchase_requests']);
        $this->assertSame(1, $dashboard['trend']['months'][4]['purchase_orders']);
        $this->assertSame(1, collect($dashboard['request_statuses'])->firstWhere('key', 'pending_finance')['count']);
        $this->assertSame(1, collect($dashboard['order_statuses'])->firstWhere('key', 'in_transit')['count']);
        $this->assertCount(4, $dashboard['recent_activity']);
        $this->assertTrue(collect($dashboard['recent_activity'])->every(
            static fn (array $record): bool => $record['url'] === null,
        ));
    }

    public function test_it_returns_zeroed_buckets_and_statuses_for_an_empty_shop(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 2)->setTime(12, 0));
        $shop = ShopOwner::factory()->create();

        $dashboard = app(\App\Services\ProcurementDashboardService::class)->forShopOwner($shop->id);

        $this->assertSame(0, $dashboard['summary']['purchase_requests']);
        $this->assertSame(0, $dashboard['summary']['awaiting_review']);
        $this->assertSame(0, $dashboard['summary']['purchase_orders']);
        $this->assertSame('0.00', (string) $dashboard['summary']['open_order_value']);
        $this->assertCount(6, $dashboard['trend']['months']);
        $this->assertTrue(collect($dashboard['trend']['months'])->every(
            static fn (array $month): bool => $month['purchase_requests'] === 0
                && $month['purchase_orders'] === 0,
        ));
        $this->assertTrue(collect($dashboard['request_statuses'])->every(
            static fn (array $status): bool => $status['count'] === 0,
        ));
        $this->assertTrue(collect($dashboard['order_statuses'])->every(
            static fn (array $status): bool => $status['count'] === 0,
        ));
        $this->assertSame([], $dashboard['recent_activity']);
    }
}
