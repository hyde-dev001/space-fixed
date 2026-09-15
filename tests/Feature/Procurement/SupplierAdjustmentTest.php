<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierAdjustment;
use App\Models\User;
use App\Services\Finance\ExpenseSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $owner;
    private User $inventoryUser;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        Storage::fake('local');

        $this->owner = ShopOwner::factory()->create();
        $this->inventoryUser = User::factory()->for($this->owner)->create();
        $this->supplier = Supplier::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'email' => 'supplier@example.test',
        ]);

        foreach (['procurement.receive_purchase_orders', 'view-inventory', 'procurement.view'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $this->inventoryUser->givePermissionTo([
            'procurement.receive_purchase_orders',
            'view-inventory',
            'procurement.view',
        ]);
    }

    public function test_receiving_defect_requires_category_notes_and_image_evidence(): void
    {
        [$po, $item] = $this->poItem();

        $this->actingAs($this->inventoryUser, 'user')->postJson(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            [
                'idempotency_key' => 'receive-defect-required',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 1,
                    'defective_quantity' => 1,
                ]],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.0.reason_category',
                'items.0.inventory_notes',
                'items.0.defect_evidence',
            ]);

        $this->assertDatabaseCount('purchase_order_receipts', 0);
        $this->assertDatabaseCount('supplier_adjustments', 0);
    }

    public function test_other_receiving_defect_requires_notes(): void
    {
        [$po, $item] = $this->poItem();

        $this->withHeaders(['Accept' => 'application/json'])->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            [
                'idempotency_key' => 'receive-other-notes',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 1,
                    'defective_quantity' => 1,
                    'reason_category' => 'other',
                    'defect_evidence' => [$this->fakeImage('defect.jpg')],
                ]],
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('items.0.inventory_notes');
    }

    public function test_receiving_defect_rejects_unsupported_evidence_type(): void
    {
        [$po, $item] = $this->poItem();

        $this->withHeaders(['Accept' => 'application/json'])->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            [
                'idempotency_key' => 'receive-invalid-evidence',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 1,
                    'defective_quantity' => 1,
                    'reason_category' => 'damaged',
                    'inventory_notes' => 'Unsupported proof type.',
                    'defect_evidence' => [$this->fakeDocument('proof.pdf')],
                ]],
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('items.0.defect_evidence.0');

        $this->assertDatabaseCount('purchase_order_receipts', 0);
        $this->assertDatabaseCount('supplier_adjustments', 0);
    }

    public function test_receiving_defect_creates_private_adjustment_and_excludes_defect_from_payable(): void
    {
        [$po, $item, $inventory] = $this->poItem();
        $receipt = $this->postReceipt($po, $item, [
            'received_quantity' => 2,
            'defective_quantity' => 1,
            'reason_category' => 'damaged',
            'inventory_notes' => 'One box arrived torn.',
            'defect_evidence' => [$this->fakeImage('defect.jpg')],
        ]);

        $adjustment = SupplierAdjustment::sole();
        $this->assertSame(SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT, $adjustment->issue_stage);
        $this->assertSame(SupplierAdjustment::STATUS_REPORTED, $adjustment->status);
        $this->assertSame(1, $adjustment->reported_quantity);
        $this->assertSame('100.00', (string) $adjustment->unit_cost_snapshot);
        $this->assertSame($this->inventoryUser->id, $adjustment->reported_by);
        $this->assertNotNull($adjustment->reported_at);
        $this->assertSame(1, $adjustment->getMedia('defect_evidence')->count());
        $this->assertSame('local', $adjustment->getMedia('defect_evidence')->sole()->disk);

        $receiptItem = $receipt->items()->sole();
        $this->assertSame(1, $receiptItem->accepted_quantity);
        $this->assertSame(11, $inventory->fresh()->available_quantity);
        $this->assertSame(0, Expense::count());
        $this->assertSame(1, StockMovement::count());

        $this->actingAs($this->inventoryUser, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/finalize")
            ->assertUnprocessable();
    }

    public function test_receiving_defect_idempotency_replays_and_rejects_changed_payloads(): void
    {
        [$po, $item] = $this->poItem();
        $payload = [
            'idempotency_key' => 'receiving-defect-replay',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 2,
                'defective_quantity' => 1,
                'reason_category' => 'damaged',
                'inventory_notes' => 'Same report replay.',
                'defect_evidence' => [$this->fakeImage('defect.jpg')],
            ]],
        ];

        $first = $this->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            $payload,
        )->assertCreated();

        $this->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            array_replace_recursive($payload, [
                'items' => [['defect_evidence' => [$this->fakeImage('replay.jpg')]]],
            ]),
        )->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->withHeaders(['Accept' => 'application/json'])->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            array_replace_recursive($payload, [
                'items' => [['inventory_notes' => 'Changed report.']],
            ]),
        )->assertStatus(409);

        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame(1, SupplierAdjustment::count());
        $this->assertSame(1, SupplierAdjustment::sole()->getMedia('defect_evidence')->count());
    }

    public function test_supplier_adjustment_evidence_is_private_and_tenant_protected(): void
    {
        [$po, $item] = $this->poItem();
        $receipt = $this->postReceipt($po, $item, [
            'received_quantity' => 2,
            'defective_quantity' => 1,
            'reason_category' => 'wrong_item',
            'inventory_notes' => 'The label does not match the PO.',
            'defect_evidence' => [$this->fakeImage('private-defect.jpg')],
        ]);
        $adjustment = SupplierAdjustment::sole();
        $media = $adjustment->getMedia('defect_evidence')->sole();

        $this->actingAs($this->inventoryUser, 'user')->get(
            "/api/erp/procurement/supplier-adjustments/{$adjustment->id}/evidence/{$media->id}",
        )->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $foreignOwner = ShopOwner::factory()->create();
        $foreignUser = User::factory()->for($foreignOwner)->create();
        $foreignUser->givePermissionTo([
            'procurement.receive_purchase_orders',
            'view-inventory',
            'procurement.view',
        ]);

        $this->actingAs($foreignUser, 'user')->get(
            "/api/erp/procurement/supplier-adjustments/{$adjustment->id}/evidence/{$media->id}",
        )->assertNotFound();
        $this->assertSame('receiving', $receipt->fresh()->status);
        $this->assertSame(1, $adjustment->fresh()->getMedia('defect_evidence')->count());
    }

    public function test_post_payment_issue_preserves_original_receipt_inventory_and_settlement(): void
    {
        [$po, $item, $inventory] = $this->poItem();
        $receipt = $this->postReceipt($po, $item, [
            'received_quantity' => 2,
            'defective_quantity' => 0,
        ]);
        $expense = Expense::sole();
        $expense->update(['status' => 'posted']);
        app(ExpenseSettlementService::class)->record($expense, $this->owner, [
            'amount' => '200.00',
            'payment_method' => 'bank_transfer',
            'reference' => 'BANK-POST-PAYMENT-1',
            'paid_at' => now()->toDateTimeString(),
            'idempotency_key' => 'settle-post-payment-1',
        ]);

        $receiptItem = $receipt->items()->sole();
        $originalEffects = $receiptItem->inventory_effects;
        $originalStockMovements = StockMovement::count();
        $originalSettlements = ExpenseSettlement::count();
        $po->update(['status' => 'completed', 'is_historical' => true]);

        $response = $this->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/items/{$receiptItem->id}/post-payment-issues",
            [
                'idempotency_key' => 'late-issue-1',
                'reported_quantity' => 1,
                'reason_category' => 'manufacturing_defect',
                'inventory_notes' => 'Failure appeared after acceptance.',
                'defect_evidence' => [$this->fakeImage('late-defect.jpg')],
            ],
        )->assertCreated();

        $this->assertSame(SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT, $response->json('data.issue_stage'));
        $this->assertSame(SupplierAdjustment::STATUS_REPORTED, $response->json('data.status'));
        $this->assertSame('completed', $po->fresh()->status);
        $this->assertSame($originalEffects, $receiptItem->fresh()->inventory_effects);
        $this->assertSame(2, $receiptItem->fresh()->accepted_quantity);
        $this->assertSame(12, $inventory->fresh()->available_quantity);
        $this->assertSame($originalStockMovements, StockMovement::count());
        $this->assertSame($originalSettlements, ExpenseSettlement::count());
    }

    public function test_post_payment_issue_quantity_cannot_be_claimed_twice(): void
    {
        [$po, $item] = $this->poItem();
        $receipt = $this->postReceipt($po, $item, [
            'received_quantity' => 2,
            'defective_quantity' => 0,
        ]);
        $expense = Expense::sole();
        $expense->update(['status' => 'posted']);
        app(ExpenseSettlementService::class)->record($expense, $this->owner, [
            'amount' => '200.00',
            'payment_method' => 'bank_transfer',
            'reference' => 'BANK-POST-PAYMENT-2',
            'idempotency_key' => 'settle-post-payment-2',
        ]);
        $receiptItem = $receipt->items()->sole();

        $payload = [
            'reported_quantity' => 2,
            'reason_category' => 'damaged',
            'inventory_notes' => 'One paid unit failed.',
            'defect_evidence' => [$this->fakeImage('late-defect.jpg')],
        ];
        $this->withHeaders(['Accept' => 'application/json'])->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/items/{$receiptItem->id}/post-payment-issues",
            ['idempotency_key' => 'late-issue-2'] + $payload,
        )->assertCreated();

        $this->withHeaders(['Accept' => 'application/json'])->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$receipt->id}/items/{$receiptItem->id}/post-payment-issues",
            ['idempotency_key' => 'late-issue-3'] + array_replace($payload, [
                'defect_evidence' => [$this->fakeImage('late-defect-duplicate.jpg')],
            ]),
        )->assertUnprocessable()->assertJsonValidationErrors('reported_quantity');

        $this->assertSame(1, SupplierAdjustment::where('issue_stage', SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT)->count());
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, InventoryItem} */
    private function poItem(): array
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'repair_materials',
            'available_quantity' => 10,
        ]);
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'supplier_id' => $this->supplier->id,
            'ordered_by' => $this->inventoryUser->id,
            'inventory_item_id' => $inventory->id,
            'quantity' => 2,
            'unit_cost' => 100,
            'total_cost' => 200,
            'status' => 'in_transit',
        ]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => 2,
            'unit_cost' => 100,
            'line_total' => 200,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => [],
        ]);

        return [$po, $item, $inventory];
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'image/jpeg');
    }

    private function fakeDocument(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    private function postReceipt(PurchaseOrder $po, PurchaseOrderItem $item, array $line): PurchaseOrderReceipt
    {
        $response = $this->actingAs($this->inventoryUser, 'user')->post(
            "/api/erp/procurement/purchase-orders/{$po->id}/receipts",
            [
                'idempotency_key' => fake()->uuid(),
                'items' => [array_merge([
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 2,
                    'defective_quantity' => 0,
                ], $line)],
            ],
        )->assertCreated();

        if ((int) ($line['defective_quantity'] ?? 0) === 0) {
            $this->actingAs($this->inventoryUser, 'user')
                ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts/{$response->json('data.id')}/finalize")
                ->assertCreated();
        }

        return PurchaseOrderReceipt::findOrFail($response->json('data.id'));
    }
}
