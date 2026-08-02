<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_without_writing(): void
    {
        $this->legacyPurchaseOrder();

        $this->artisan('procurement:backfill-purchase-orders', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertDatabaseCount('purchase_order_items', 0);
        $this->assertDatabaseCount('purchase_order_receipts', 0);
    }

    public function test_live_backfill_is_idempotent_and_does_not_replay_side_effects(): void
    {
        $po = $this->legacyPurchaseOrder([
            'status' => 'in_transit',
            'quantity' => 5,
            'received_quantity' => 4,
            'defective_quantity' => 1,
        ]);
        $stockBefore = $po->inventoryItem->available_quantity;

        $this->artisan('procurement:backfill-purchase-orders')->assertSuccessful();
        $this->artisan('procurement:backfill-purchase-orders')->assertSuccessful();

        $item = PurchaseOrderItem::sole();
        $receipt = PurchaseOrderReceipt::with('items')->sole();

        $this->assertSame($po->id, $item->purchase_order_id);
        $this->assertSame($po->pr_id, $item->purchase_request_id);
        $this->assertSame(5, $item->ordered_quantity);
        $this->assertSame('migration', $receipt->source);
        $this->assertSame(4, $receipt->items->sole()->received_quantity);
        $this->assertSame(1, $receipt->items->sole()->defective_quantity);
        $this->assertSame(3, $receipt->items->sole()->accepted_quantity);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame($stockBefore, $po->inventoryItem->fresh()->available_quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_all_size_line_snapshots_current_size_ids_and_total_units(): void
    {
        $po = $this->legacyPurchaseOrder([
            'requested_size' => null,
            'quantity' => 2,
            'unit_cost' => 100,
            'total_cost' => 600,
        ]);
        $sizes = collect(['7', '8', '9'])->map(fn (string $size) => InventorySize::create([
            'inventory_item_id' => $po->inventory_item_id,
            'size' => $size,
            'size_system' => 'US',
            'quantity' => 0,
        ]));

        $this->artisan('procurement:backfill-purchase-orders')->assertSuccessful();

        $item = PurchaseOrderItem::sole();
        $this->assertSame(1, $item->quantity_multiplier);
        $this->assertSame(6, $item->ordered_quantity);
        $this->assertSame(6, $po->fresh()->quantity);
        $this->assertEqualsCanonicalizing($sizes->pluck('id')->all(), $item->eligible_size_ids);
        $this->assertSame('600.00', $item->line_total);
    }

    public function test_terminal_legacy_order_is_marked_historical_and_keeps_its_stored_total(): void
    {
        $po = $this->legacyPurchaseOrder([
            'status' => 'completed',
            'requested_size' => null,
            'quantity' => 2,
            'unit_cost' => 100,
            'total_cost' => 450,
            'received_quantity' => 2,
            'defective_quantity' => 0,
        ]);
        InventorySize::create([
            'inventory_item_id' => $po->inventory_item_id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 0,
        ]);

        $this->artisan('procurement:backfill-purchase-orders')->assertSuccessful();

        $this->assertTrue($po->fresh()->is_historical);
        $this->assertSame('450.00', PurchaseOrderItem::sole()->line_total);
        $this->assertSame('completed', $po->fresh()->status);
        $this->assertSame('migration', PurchaseOrderReceipt::sole()->source);
    }

    public function test_non_terminal_all_size_total_conflict_is_reported_without_partial_writes(): void
    {
        $po = $this->legacyPurchaseOrder([
            'status' => 'in_transit',
            'requested_size' => null,
            'quantity' => 2,
            'unit_cost' => 100,
            'total_cost' => 450,
        ]);
        foreach (['8', '9'] as $size) {
            InventorySize::create([
                'inventory_item_id' => $po->inventory_item_id,
                'size' => $size,
                'size_system' => 'US',
                'quantity' => 0,
            ]);
        }

        $this->artisan('procurement:backfill-purchase-orders')
            ->expectsOutputToContain('does not match')
            ->assertFailed();

        $this->assertDatabaseCount('purchase_order_items', 0);
        $this->assertDatabaseCount('purchase_order_receipts', 0);
    }

    private function legacyPurchaseOrder(array $overrides = []): PurchaseOrder
    {
        $owner = ShopOwner::factory()->create();
        $user = User::factory()->for($owner)->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'available_quantity' => 10,
        ]);
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $inventory->id,
            'requested_by' => $user->id,
            'status' => 'approved',
        ]);

        return PurchaseOrder::factory()->create(array_merge([
            'pr_id' => $pr->id,
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $inventory->id,
            'ordered_by' => $user->id,
            'requested_size' => 'US 8',
            'quantity' => 5,
            'unit_cost' => 100,
            'total_cost' => 550,
        ], $overrides));
    }
}
