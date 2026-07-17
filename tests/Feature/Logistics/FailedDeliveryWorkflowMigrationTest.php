<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FailedDeliveryWorkflowMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        return ['--step' => true];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        RefreshDatabaseState::$inMemoryConnections = [];
    }

    public function test_migration_backfills_attempt_numbers_per_type_and_exact_variant_matches(): void
    {
        $leg = ShipmentLeg::factory()->create();
        $shop = ShopOwner::factory()->create();
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Legacy variant item',
            'slug' => 'legacy-variant-item',
            'price' => 100,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '42',
            'color' => 'Black',
            'quantity' => 5,
            'is_active' => true,
        ]);
        $order = Order::factory()->create(['shop_owner_id' => $product->shop_owner_id]);

        $migration = require database_path('migrations/2026_07_17_000003_harden_failed_delivery_refund_workflow.php');
        $migration->down();

        $laterDeliveryId = DB::table('delivery_attempts')->insertGetId($this->attempt($leg->id, 'delivery', '2026-07-17 10:00:00'));
        $firstDeliveryId = DB::table('delivery_attempts')->insertGetId($this->attempt($leg->id, 'delivery', '2026-07-17 09:00:00'));
        $pickupId = DB::table('delivery_attempts')->insertGetId($this->attempt($leg->id, 'pickup', '2026-07-17 08:00:00'));
        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'size' => ' 42 ',
            'color' => 'black',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(1, DB::table('delivery_attempts')->where('id', $firstDeliveryId)->value('attempt_number'));
        $this->assertSame(2, DB::table('delivery_attempts')->where('id', $laterDeliveryId)->value('attempt_number'));
        $this->assertSame(1, DB::table('delivery_attempts')->where('id', $pickupId)->value('attempt_number'));
        $this->assertSame($variant->id, DB::table('order_items')->where('id', $orderItemId)->value('product_variant_id'));
    }

    private function attempt(int $legId, string $type, string $attemptedAt): array
    {
        return [
            'shipment_leg_id' => $legId,
            'attempt_type' => $type,
            'status' => 'failed',
            'attempted_at' => $attemptedAt,
            'created_at' => $attemptedAt,
            'updated_at' => $attemptedAt,
        ];
    }
}
