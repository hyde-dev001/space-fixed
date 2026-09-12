<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\Approval;
use App\Enums\ApprovalStatus;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseOrderReceiptVoidTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $owner;
    private User $receiver;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        $this->owner = ShopOwner::factory()->create();
        $this->receiver = User::factory()->for($this->owner)->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->owner->id]);
        foreach (['procurement.receive_purchase_orders', 'procurement.void_purchase_order_receipts', 'view-inventory'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $this->receiver->givePermissionTo(['procurement.receive_purchase_orders', 'procurement.void_purchase_order_receipts', 'view-inventory']);
    }

    public function test_void_reverses_inventory_once_and_rejects_submitted_expense(): void
    {
        [$po, $item, $inventory] = $this->poItem(5, 100);
        $receiptId = $this->postReceipt($po, $item, 3, 1);

        $response = $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Incorrect delivery count entered.']
        )->assertOk();

        $response->assertJsonPath('data.status', 'voided');
        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame('in_transit', $po->fresh()->status);
        $this->assertSame(0, $po->fresh()->received_quantity);
        $this->assertSame('rejected', Expense::sole()->status);
        $this->assertStringContainsString('voided', strtolower((string) Expense::sole()->approval_notes));
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(-2, StockMovement::whereNotNull('reversal_of_stock_movement_id')->sole()->quantity_change);

        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Retrying the same void request.']
        )->assertOk();
        $this->assertSame(2, StockMovement::count());
    }

    public function test_all_size_void_reverses_exact_snapshot(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'shoes',
            'available_quantity' => 0,
        ]);
        $sizes = collect(['7', '8', '9'])->map(fn ($size) => InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => $size,
            'size_system' => 'US',
            'quantity' => 0,
        ]));
        [$po, $item] = $this->poItem(3, 100, [
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => $sizes->pluck('id')->all(),
            'line_total' => 300,
        ]);
        $receiptId = (int) $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            [
                'idempotency_key' => fake()->uuid(),
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 3,
                    'defective_quantity' => 0,
                    'size_quantities' => $sizes->map(fn ($size) => [
                        'inventory_size_id' => $size->id,
                        'received_quantity' => 1,
                        'defective_quantity' => 0,
                    ])->all(),
                ]],
            ]
        )->assertCreated()->json('data.id');

        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Wrong all-size shipment recorded.']
        )->assertOk();

        $this->assertSame(0, $inventory->fresh()->available_quantity);
        $this->assertSame([0, 0, 0], $sizes->map(fn ($size) => $size->fresh()->quantity)->all());
        $this->assertSame(-3, StockMovement::whereNotNull('reversal_of_stock_movement_id')->sole()->quantity_change);
    }

    public function test_void_requires_permission_and_reason(): void
    {
        [$po, $item] = $this->poItem(2, 100);
        $receiptId = $this->postReceipt($po, $item, 1, 0);

        $this->receiver->revokePermissionTo('procurement.void_purchase_order_receipts');
        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'A sufficiently long reason.']
        )->assertForbidden();

        $this->receiver->givePermissionTo('procurement.void_purchase_order_receipts');
        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void"
        )->assertUnprocessable();
    }

    public function test_approved_expense_completed_po_and_migration_receipt_cannot_be_voided(): void
    {
        [$po, $item] = $this->poItem(2, 100);
        $receiptId = $this->postReceipt($po, $item, 1, 0);
        Expense::sole()->update(['status' => 'approved']);

        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Trying an ineligible correction.']
        )->assertUnprocessable();

        Expense::sole()->update(['status' => 'submitted']);
        $po->update(['status' => 'completed']);
        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Trying after completion now.']
        )->assertUnprocessable();

        $po->update(['status' => 'partially_received']);
        PurchaseOrderReceipt::findOrFail($receiptId)->update(['source' => 'migration']);
        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Trying migration receipt correction.']
        )->assertUnprocessable();
    }

    public function test_insufficient_stock_rolls_back_void(): void
    {
        [$po, $item, $inventory] = $this->poItem(2, 100);
        $receiptId = $this->postReceipt($po, $item, 2, 0);
        $inventory->update(['available_quantity' => 1]);

        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Correcting after stock was consumed.']
        )->assertUnprocessable();

        $this->assertSame('posted', PurchaseOrderReceipt::findOrFail($receiptId)->status);
        $this->assertSame('submitted', Expense::sole()->status);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_void_cancels_a_pending_expense_approval(): void
    {
        [$po, $item] = $this->poItem(2, 100);
        $receiptId = $this->postReceipt($po, $item, 1, 0);
        $expense = Expense::sole();
        $approval = Approval::create([
            'shop_owner_id' => $this->receiver->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => 'Procurement expense approval',
            'amount' => $expense->amount,
            'requested_by' => $this->receiver->id,
            'current_level' => 1,
            'total_levels' => 4,
            'status' => ApprovalStatus::PENDING,
            'approval_roles' => ['1' => 'finance'],
        ]);
        $expense->update(['approval_id' => $approval->id]);

        $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receiptId}/void",
            ['reason' => 'Void receipt and pending approval.']
        )->assertOk();

        $this->assertSame(ApprovalStatus::CANCELLED, $approval->fresh()->status);
        $this->assertSame('rejected', $expense->fresh()->status);
    }

    private function postReceipt(PurchaseOrder $po, PurchaseOrderItem $item, int $received, int $defective): int
    {
        return (int) $this->actingAs($this->receiver, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            [
                'idempotency_key' => fake()->uuid(),
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => $received,
                    'defective_quantity' => $defective,
                ]],
            ]
        )->assertCreated()->json('data.id');
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, InventoryItem} */
    private function poItem(int $quantity, int $unitCost, array $itemOverrides = []): array
    {
        $inventory = isset($itemOverrides['inventory_item_id'])
            ? InventoryItem::findOrFail($itemOverrides['inventory_item_id'])
            : InventoryItem::factory()->create([
                'shop_owner_id' => $this->owner->id,
                'category' => 'repair_materials',
                'available_quantity' => 10,
            ]);
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'supplier_id' => $this->supplier->id,
            'ordered_by' => $this->receiver->id,
            'inventory_item_id' => $inventory->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'status' => 'in_transit',
        ]);
        $item = PurchaseOrderItem::factory()->create(array_merge([
            'purchase_order_id' => $po->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $quantity * $unitCost,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => [],
        ], $itemOverrides));

        return [$po, $item, $inventory];
    }
}
