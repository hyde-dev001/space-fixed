<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierAdjustment;
use App\Models\User;
use App\Services\Finance\ExpenseSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierReplacementTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $owner;
    private User $receiver;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        Storage::fake('local');

        $this->owner = ShopOwner::factory()->create();
        $this->receiver = User::factory()->for($this->owner)->create();
        $this->supplier = Supplier::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'email' => 'supplier@example.test',
        ]);

        foreach (['procurement.receive_purchase_orders', 'view-inventory', 'procurement.view'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $this->receiver->givePermissionTo([
            'procurement.receive_purchase_orders',
            'view-inventory',
            'procurement.view',
        ]);
    }

    public function test_receiving_defect_replacement_uses_the_canonical_receiver_and_creates_payable(): void
    {
        [$po, $item, $inventory] = $this->poItem(5, 100);

        $this->receive($po, [
            'idempotency_key' => 'replacement-original',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 5,
                'defective_quantity' => 2,
                'reason_category' => 'damaged',
                'inventory_notes' => 'Two cartons arrived damaged.',
                'defect_evidence' => [$this->fakeImage('original-defect.jpg')],
            ]],
        ])->assertCreated();

        $adjustment = SupplierAdjustment::sole();
        $replacementPayload = [
            'idempotency_key' => 'replacement-receipt',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 2,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ];

        $replacement = $this->receive($po, $replacementPayload)->assertCreated();
        $replacementReceipt = PurchaseOrderReceipt::findOrFail($replacement->json('data.id'));
        $replacementItem = $replacementReceipt->items()->sole();

        $this->assertSame($adjustment->id, $replacementItem->replacement_for_adjustment_id);
        $this->assertSame(SupplierAdjustment::RESOLUTION_REPLACEMENT, $adjustment->fresh()->resolution);
        $this->assertSame(SupplierAdjustment::STATUS_RESOLVED, $adjustment->fresh()->status);
        $this->assertSame('delivered', $po->fresh()->status);
        $this->assertSame(15, $inventory->fresh()->available_quantity);
        $this->assertSame(2, Expense::count());
        $this->assertEqualsCanonicalizing(['300.00', '200.00'], Expense::pluck('amount')->all());

        $this->receive($po, $replacementPayload)->assertOk()->assertJsonPath('data.id', $replacementReceipt->id);
        $this->assertSame(2, PurchaseOrderReceipt::count());

        $this->receive($po, array_replace_recursive($replacementPayload, [
            'items' => [['received_quantity' => 1]],
        ]))->assertConflict();
    }

    public function test_post_payment_replacement_on_completed_po_adds_inventory_without_a_second_expense(): void
    {
        [$po, $item, $inventory] = $this->poItem(1, 100);

        $receiptResponse = $this->receive($po, [
            'idempotency_key' => 'paid-original',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 1,
                'defective_quantity' => 0,
            ]],
        ])->assertCreated();
        $receipt = PurchaseOrderReceipt::findOrFail($receiptResponse->json('data.id'));
        $expense = Expense::sole();
        $expense->update(['status' => 'posted']);
        app(ExpenseSettlementService::class)->record($expense, $this->owner, [
            'amount' => '100.00',
            'payment_method' => 'bank_transfer',
            'reference' => 'PAID-ORIGINAL',
            'idempotency_key' => 'paid-original-settlement',
        ]);
        $po->update(['status' => 'completed', 'is_historical' => true]);

        $issueResponse = $this->actingAs($this->receiver, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/items/{$receipt->items()->sole()->id}/post-payment-issues",
            [
                'idempotency_key' => 'paid-issue',
                'reported_quantity' => 1,
                'reason_category' => 'manufacturing_defect',
                'inventory_notes' => 'Failure appeared after acceptance.',
                'defect_evidence' => [$this->fakeImage('late-defect.jpg')],
            ],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $adjustment = SupplierAdjustment::findOrFail($issueResponse->json('data.id'));

        $replacement = $this->receive($po, [
            'idempotency_key' => 'paid-replacement',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 1,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertCreated();

        $replacementReceipt = PurchaseOrderReceipt::findOrFail($replacement->json('data.id'));
        $this->assertSame($adjustment->id, $replacementReceipt->items()->sole()->replacement_for_adjustment_id);
        $this->assertSame(SupplierAdjustment::STATUS_RESOLVED, $adjustment->fresh()->status);
        $this->assertSame('completed', $po->fresh()->status);
        $this->assertTrue($po->fresh()->is_historical);
        $this->assertSame(12, $inventory->fresh()->available_quantity);
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, ExpenseSettlement::count());
    }

    public function test_replacement_cannot_use_another_po_adjustment_or_claim_more_than_reported(): void
    {
        [$po, $item] = $this->poItem(3, 100);
        $this->receive($po, [
            'idempotency_key' => 'overclaim-original',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 3,
                'defective_quantity' => 2,
                'reason_category' => 'wrong_item',
                'inventory_notes' => 'Two units were the wrong item.',
                'defect_evidence' => [$this->fakeImage('wrong-item.jpg')],
            ]],
        ])->assertCreated();
        $adjustment = SupplierAdjustment::sole();

        $this->receive($po, [
            'idempotency_key' => 'overclaim-replacement',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 3,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        [$otherPo, $otherItem] = $this->poItem(1, 100);
        $this->receive($otherPo, [
            'idempotency_key' => 'wrong-po-replacement',
            'items' => [[
                'purchase_order_item_id' => $otherItem->id,
                'received_quantity' => 1,
                'defective_quantity' => 0,
                'replacement_for_adjustment_id' => $adjustment->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(1, SupplierAdjustment::count());
        $this->assertSame(1, PurchaseOrderReceipt::where('purchase_order_id', $po->id)->count());
    }

    public function test_defective_replacement_attaches_evidence_to_the_original_adjustment_and_stays_unresolved(): void
    {
        [$po, $item] = $this->poItem(2, 100);
        $this->receive($po, [
            'idempotency_key' => 'defective-replacement-original',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 2,
                'defective_quantity' => 1,
                'reason_category' => 'damaged',
                'inventory_notes' => 'One original unit was damaged.',
                'defect_evidence' => [$this->fakeImage('original.jpg')],
            ]],
        ])->assertCreated();
        $adjustment = SupplierAdjustment::sole();

        $replacement = $this->receive($po, [
            'idempotency_key' => 'defective-replacement',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 1,
                'defective_quantity' => 1,
                'replacement_for_adjustment_id' => $adjustment->id,
                'reason_category' => 'manufacturing_defect',
                'inventory_notes' => 'The replacement failed inspection.',
                'defect_evidence' => [$this->fakeImage('replacement.jpg')],
            ]],
        ])->assertCreated();

        $this->assertSame(1, SupplierAdjustment::count());
        $this->assertSame(2, $adjustment->fresh()->getMedia('defect_evidence')->count());
        $this->assertNotSame(SupplierAdjustment::STATUS_RESOLVED, $adjustment->fresh()->status);
        $this->assertSame($adjustment->id, PurchaseOrderReceipt::findOrFail($replacement->json('data.id'))->items()->sole()->replacement_for_adjustment_id);
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, InventoryItem} */
    private function poItem(int $quantity, int $unitCost): array
    {
        $inventory = InventoryItem::factory()->create([
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

    private function receive(PurchaseOrder $purchaseOrder, array $payload)
    {
        return $this->actingAs($this->receiver, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$purchaseOrder->id}/receipts",
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'image/jpeg');
    }
}
