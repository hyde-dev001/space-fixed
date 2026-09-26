<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SimplifiedReceivingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $owner;
    private User $inventoryUser;
    private User $procurementUser;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        Storage::fake('local');

        $this->owner = ShopOwner::factory()->create();
        $this->inventoryUser = User::factory()->for($this->owner)->create();
        $this->procurementUser = User::factory()->for($this->owner)->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->owner->id]);

        foreach ([
            'procurement.receive_purchase_orders',
            'procurement.manage_suppliers',
            'procurement.view',
            'view-inventory',
        ] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $this->inventoryUser->givePermissionTo(['procurement.receive_purchase_orders', 'procurement.view', 'view-inventory']);
        $this->procurementUser->givePermissionTo(['procurement.manage_suppliers', 'procurement.view']);
    }

    public function test_initial_receiving_is_complete_once_and_finance_waits_for_final_receipt(): void
    {
        [$po, $item, $inventory] = $this->poItem($this->owner, $this->inventoryUser, $this->supplier, 5, 100);

        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('partial', $item->id, 3, 0))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $response = $this->receive($po, $this->payload('original', $item->id, 5, 2))
            ->assertCreated()
            ->assertJsonPath('data.status', 'receiving');

        $receipt = PurchaseOrderReceipt::findOrFail($response->json('data.id'));
        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame(0, Expense::count());
        $this->assertSame(13, $inventory->fresh()->available_quantity);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(1, SupplierAdjustment::count());

        $this->receive($po, $this->payload('repeat', $item->id, 5, 0))
            ->assertUnprocessable();
        $this->assertSame(1, PurchaseOrderReceipt::count());

        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/finalize")
            ->assertUnprocessable();
        $this->assertSame(0, Expense::count());
    }

    public function test_initial_receiving_rejects_duplicate_purchase_order_lines(): void
    {
        [$po, $item] = $this->poItem($this->owner, $this->inventoryUser, $this->supplier, 2, 100);
        $secondInventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'repair_materials',
            'available_quantity' => 10,
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'inventory_item_id' => $secondInventory->id,
            'ordered_quantity' => 1,
            'unit_cost' => 100,
            'line_total' => 100,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => [],
        ]);

        $this->receive($po, [
            'idempotency_key' => 'duplicate-lines',
            'items' => [
                ['purchase_order_item_id' => $item->id, 'received_quantity' => 2, 'defective_quantity' => 0],
                ['purchase_order_item_id' => $item->id, 'received_quantity' => 2, 'defective_quantity' => 0],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'items.0.purchase_order_item_id',
            'items.1.purchase_order_item_id',
        ]);

        $this->assertSame(0, PurchaseOrderReceipt::count());
    }

    public function test_simplified_receiving_migration_can_be_replayed_after_partial_deployment(): void
    {
        $migration = require base_path('database/migrations/2026_09_15_000001_add_simplified_procurement_receiving_workflow.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('purchase_order_receipts', 'receipt_reference'));
        $this->assertTrue(Schema::hasTable('shop_procurement_receipt_sequences'));
        $this->assertTrue(Schema::hasColumn('supplier_adjustments', 'replacement_status'));
    }

    public function test_receipt_item_migration_replaces_the_foreign_key_supporting_index_safely(): void
    {
        // SQLite permits dropping child FK indexes; MySQL requires one to remain in place.
        $migration = file_get_contents(database_path('migrations/2026_09_15_000001_add_simplified_procurement_receiving_workflow.php'));
        $newIndex = strpos($migration, "                    'po_receipt_item_line_unique',");
        $oldIndexDrop = strpos($migration, "                \$table->dropUnique('po_receipt_item_unique');");

        $this->assertIsString($migration);
        $this->assertNotFalse($newIndex);
        $this->assertNotFalse($oldIndexDrop);
        $this->assertLessThan($oldIndexDrop, $newIndex);
    }

    public function test_replacement_uses_the_same_receipt_and_finalizes_once(): void
    {
        [$po, $item, $inventory] = $this->poItem($this->owner, $this->inventoryUser, $this->supplier, 5, 100);
        $this->receive($po, $this->payload('original', $item->id, 5, 2))->assertCreated();
        $adjustment = SupplierAdjustment::sole();

        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/resolution", [
                'resolution' => 'replacement',
            ])->assertOk();
        $this->adjustmentAction($adjustment, 'sent')->assertOk();
        $this->adjustmentAction($adjustment, 'accepted')->assertOk();
        $this->adjustmentAction($adjustment, 'in-transit')->assertOk();

        $replacement = $this->receive($po, [
            'idempotency_key' => 'replacement',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 2,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertCreated();

        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame(15, $inventory->fresh()->available_quantity);
        $this->assertSame(SupplierAdjustment::STATUS_RESOLVED, $adjustment->fresh()->status);
        $this->assertNull(PurchaseOrderReceipt::sole()->receipt_reference);
        $this->assertSame(0, Expense::count());

        $final = $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$replacement->json('data.id')}/finalize")
            ->assertCreated();
        $reference = $final->json('data.receipt_reference');
        $this->assertSame('posted', $final->json('data.status'));
        $this->assertMatchesRegularExpression('/^RCV-' . Carbon::now()->year . '-0001$/', $reference);
        $this->assertSame('500.00', Expense::sole()->amount);

        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$replacement->json('data.id')}/finalize")
            ->assertOk()
            ->assertJsonPath('data.receipt_reference', $reference);

        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame(1, Expense::count());
    }

    public function test_final_receipt_reference_is_scoped_to_each_shop(): void
    {
        $this->finalizeWithoutDefect($this->owner, $this->inventoryUser, $this->supplier, 'shop-a');

        $otherOwner = ShopOwner::factory()->create();
        $otherUser = User::factory()->for($otherOwner)->create();
        $otherSupplier = Supplier::factory()->create(['shop_owner_id' => $otherOwner->id]);
        $otherUser->givePermissionTo(['procurement.receive_purchase_orders', 'procurement.view', 'view-inventory']);
        $otherReference = $this->finalizeWithoutDefect($otherOwner, $otherUser, $otherSupplier, 'shop-b');

        $this->assertSame('RCV-' . Carbon::now()->year . '-0001', $otherReference);
        $this->assertSame('RCV-' . Carbon::now()->year . '-0001', PurchaseOrderReceipt::query()->where('shop_owner_id', $this->owner->id)->value('receipt_reference'));
    }

    public function test_supplier_decline_closes_short_fulfillment_without_overpaying(): void
    {
        [$po, $item] = $this->poItem($this->owner, $this->inventoryUser, $this->supplier, 5, 100);
        $this->receive($po, $this->payload('short-original', $item->id, 5, 2))->assertCreated();
        $adjustment = SupplierAdjustment::sole();

        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/resolution", [
                'resolution' => SupplierAdjustment::RESOLUTION_REPLACEMENT,
            ])->assertOk();
        $this->adjustmentAction($adjustment, 'sent')->assertOk();
        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/replacement/declined", [
                'decline_reason' => 'Supplier cannot fulfill the remaining units.',
                'supplier_reference' => 'SUP-DECLINED-1',
            ])->assertOk();
        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/short-fulfillment/close")
            ->assertOk()
            ->assertJsonPath('data.short_fulfillment_quantity', 2)
            ->assertJsonPath('data.status', SupplierAdjustment::STATUS_RESOLVED);

        $receipt = PurchaseOrderReceipt::sole();
        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/finalize")
            ->assertCreated();

        $this->assertSame('300.00', Expense::sole()->amount);
    }

    public function test_required_return_blocks_until_inventory_release_and_supplier_acknowledgement(): void
    {
        [$po, $item] = $this->poItem($this->owner, $this->inventoryUser, $this->supplier, 3, 100);
        $this->receive($po, $this->payload('return-original', $item->id, 3, 1))->assertCreated();
        $adjustment = SupplierAdjustment::sole();

        $this->chooseReplacement($adjustment);
        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/return", [
                'status' => SupplierAdjustment::RETURN_REQUIRED,
            ])->assertOk();

        $this->receive($po, [
            'idempotency_key' => 'return-replacement',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 1,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertCreated();
        $this->assertSame(SupplierAdjustment::STATUS_RESOLUTION_IN_PROGRESS, $adjustment->fresh()->status);

        $receipt = PurchaseOrderReceipt::sole();
        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/finalize")
            ->assertUnprocessable();

        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/return", [
                'status' => SupplierAdjustment::RETURN_RELEASED,
            ])->assertOk();
        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/return", [
                'status' => SupplierAdjustment::RETURN_RECEIVED_BY_SUPPLIER,
            ])->assertOk()
            ->assertJsonPath('data.status', SupplierAdjustment::STATUS_RESOLVED);

        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/finalize")
            ->assertCreated();
    }

    public function test_defective_replacement_reuses_the_adjustment_for_the_remaining_quantity(): void
    {
        [$po, $item, $inventory] = $this->poItem($this->owner, $this->inventoryUser, $this->supplier, 3, 100);
        $this->receive($po, $this->payload('retry-original', $item->id, 3, 2))->assertCreated();
        $adjustment = SupplierAdjustment::sole();
        $this->chooseReplacement($adjustment);

        $this->receive($po, [
            'idempotency_key' => 'retry-first',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 2,
                'defective_quantity' => 1,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertCreated();
        $this->assertSame(SupplierAdjustment::STATUS_RESOLUTION_IN_PROGRESS, $adjustment->fresh()->status);

        $this->adjustmentAction($adjustment, 'sent')->assertOk();
        $this->adjustmentAction($adjustment, 'accepted')->assertOk();
        $this->adjustmentAction($adjustment, 'in-transit')->assertOk();
        $this->receive($po, [
            'idempotency_key' => 'retry-second',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 1,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertCreated();

        $this->assertSame(SupplierAdjustment::STATUS_RESOLVED, $adjustment->fresh()->status);
        $this->assertSame(13, $inventory->fresh()->available_quantity);
        $receipt = PurchaseOrderReceipt::sole();
        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/finalize")
            ->assertCreated();
        $this->assertSame('300.00', Expense::sole()->amount);
    }

    private function adjustmentAction(SupplierAdjustment $adjustment, string $action)
    {
        return $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/replacement/{$action}");
    }

    private function chooseReplacement(SupplierAdjustment $adjustment): void
    {
        $this->actingAs($this->procurementUser, 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$adjustment->id}/resolution", [
                'resolution' => SupplierAdjustment::RESOLUTION_REPLACEMENT,
            ])->assertOk();
        $this->adjustmentAction($adjustment, 'sent')->assertOk();
        $this->adjustmentAction($adjustment, 'accepted')->assertOk();
        $this->adjustmentAction($adjustment, 'in-transit')->assertOk();
    }

    private function receive(PurchaseOrder $purchaseOrder, array $payload)
    {
        foreach ($payload['items'] as $index => $item) {
            if ((int) ($item['defective_quantity'] ?? 0) < 1) {
                continue;
            }
            $payload['items'][$index]['reason_category'] ??= 'damaged';
            $payload['items'][$index]['inventory_notes'] ??= 'Test receiving defect.';
            $payload['items'][$index]['defect_evidence'] ??= [UploadedFile::fake()->create('defect.jpg', 10, 'image/jpeg')];
        }

        return $this->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$purchaseOrder->id}/receipts",
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    private function finalizeWithoutDefect(ShopOwner $owner, User $user, Supplier $supplier, string $key): string
    {
        [$po, $item] = $this->poItem($owner, $user, $supplier, 1, 100);
        $response = $this->actingAs($user, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload($key, $item->id, 1, 0))
            ->assertCreated();
        $receiptId = $response->json('data.id');

        return $this->actingAs($user, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/finalize")
            ->assertCreated()
            ->json('data.receipt_reference');
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, InventoryItem} */
    private function poItem(ShopOwner $owner, User $user, Supplier $supplier, int $quantity, int $unitCost): array
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'category' => 'repair_materials',
            'available_quantity' => 10,
        ]);
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'ordered_by' => $user->id,
            'inventory_item_id' => $inventory->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'status' => 'in_transit',
        ]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $quantity * $unitCost,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => [],
        ]);

        return [$po, $item, $inventory];
    }

    private function payload(string $key, int $itemId, int $received, int $defective): array
    {
        return [
            'idempotency_key' => $key,
            'items' => [[
                'purchase_order_item_id' => $itemId,
                'received_quantity' => $received,
                'defective_quantity' => $defective,
            ]],
        ];
    }
}
