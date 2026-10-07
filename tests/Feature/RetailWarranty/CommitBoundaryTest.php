<?php

namespace Tests\Feature\RetailWarranty;

use App\Jobs\DeliverRetailWarranty;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShopOwner;
use App\Services\RetailWarrantyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommitBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A fresh in-memory connection with real commits, rather than a test transaction.
        $this->artisan('migrate')->assertExitCode(0);
    }

    private function order(): Order
    {
        Queue::fake();
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        app(RetailWarrantyService::class)->saveSettings($shop, ['enabled' => true, 'title' => 'Product Warranty', 'duration_value' => 5, 'duration_unit' => 'days', 'terms' => 'Shop terms']);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'delivered']);
        OrderItem::create(['order_id' => $order->id, 'product_name' => 'Shoe', 'product_slug' => 'shoe', 'price' => 500, 'quantity' => 1, 'subtotal' => 500]);

        return $order;
    }

    public function test_delivery_is_dispatched_only_after_outer_commit(): void
    {
        $order = $this->order();
        DB::beginTransaction();
        app(RetailWarrantyService::class)->captureFulfillment($order);
        Queue::assertNotPushed(DeliverRetailWarranty::class);
        DB::commit();
        Queue::assertPushed(DeliverRetailWarranty::class, 1);
    }

    public function test_rollback_discards_issuance_and_delivery_callback(): void
    {
        $order = $this->order();
        DB::beginTransaction();
        app(RetailWarrantyService::class)->captureFulfillment($order);
        DB::rollBack();
        $this->assertDatabaseCount('retail_warranty_issuances', 0);
        $this->assertNull($order->fresh()->retail_warranty_fulfilled_at);
        Queue::assertNotPushed(DeliverRetailWarranty::class);
    }

    public function test_new_migrations_roll_back_and_reapply_without_changing_repair_settings(): void
    {
        $order = $this->order();
        $shop = $order->shopOwner;
        $shop->update(['repair_warranty_days' => 19]);
        foreach (array_reverse(glob(database_path('migrations/2026_10_07_0755*.php'))) as $path) {
            (require $path)->down();
        }
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('retail_warranties'));
        $this->assertSame(19, $shop->fresh()->repair_warranty_days);
        foreach (glob(database_path('migrations/2026_10_07_0755*.php')) as $path) {
            (require $path)->up();
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('retail_warranties'));
        $this->assertSame(19, $shop->fresh()->repair_warranty_days);
    }
}
