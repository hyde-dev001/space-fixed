<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementQuantityNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_all_size_rows_are_normalized_once_without_replaying_side_effects(): void
    {
        $owner = ShopOwner::factory()->create();
        $user = User::factory()->for($owner)->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
        $inventory = InventoryItem::factory()->create(['shop_owner_id' => $owner->id, 'available_quantity' => 32]);
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $owner->id,
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'quantity_needed' => 50,
        ]);
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'stock_request_id' => $stockRequest->id,
            'supplier_id' => $supplier->id,
            'requested_by' => $user->id,
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'quantity' => 50,
            'unit_cost' => 4100,
            'total_cost' => 820000,
        ]);
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'ordered_by' => $user->id,
            'quantity' => 50,
            'received_quantity' => 10,
            'defective_quantity' => 2,
        ]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'purchase_request_id' => $pr->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => 50,
            'unit_cost' => 4100,
            'line_total' => 820000,
            'quantity_multiplier' => 4,
        ]);
        $receipt = PurchaseOrderReceipt::create([
            'purchase_order_id' => $po->id,
            'shop_owner_id' => $owner->id,
            'status' => 'posted',
            'idempotency_key' => 'legacy-receipt',
            'received_by' => $user->id,
            'received_at' => now(),
        ]);
        $receipt->items()->create([
            'purchase_order_item_id' => $item->id,
            'received_quantity' => 10,
            'defective_quantity' => 2,
            'accepted_quantity' => 8,
            'inventory_effects' => [],
        ]);
        StockMovement::create([
            'inventory_item_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 32,
            'quantity_before' => 0,
            'quantity_after' => 32,
            'performed_by' => $user->id,
            'performed_at' => now(),
        ]);
        Expense::create([
            'reference' => 'LEGACY-EXPENSE',
            'date' => today(),
            'category' => 'Procurement',
            'amount' => 131200,
            'status' => 'submitted',
            'shop_id' => $owner->id,
        ]);

        $migration = require database_path('migrations/2026_08_02_000005_normalize_procurement_quantities.php');
        $migration->up();
        $migration->up();

        $this->assertSame(200, $stockRequest->fresh()->quantity_needed);
        $this->assertSame(200, $pr->fresh()->quantity);
        $this->assertSame(200, $item->fresh()->ordered_quantity);
        $this->assertSame(1, $item->fresh()->quantity_multiplier);
        $this->assertSame(40, $receipt->items()->sole()->received_quantity);
        $this->assertSame(8, $receipt->items()->sole()->defective_quantity);
        $this->assertSame(32, $receipt->items()->sole()->accepted_quantity);
        $this->assertSame(200, $po->fresh()->quantity);
        $this->assertSame(40, $po->fresh()->received_quantity);
        $this->assertSame(8, $po->fresh()->defective_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('finance_expenses', 1);
        $this->assertSame(32, $inventory->fresh()->available_quantity);
        $this->assertSame('131200.00', Expense::sole()->amount);
    }
}
