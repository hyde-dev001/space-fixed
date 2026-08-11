<?php

namespace Tests\Feature\Procurement;

use App\Enums\ApprovalStatus;
use App\Models\Finance\Expense;
use App\Models\Approval;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderReceivingTest extends TestCase
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
        Permission::findOrCreate('procurement.receive_purchase_orders', 'user');
        Permission::findOrCreate('view-inventory', 'user');
        Permission::findOrCreate('access-finance-expenses', 'user');
        Permission::findOrCreate('access-approval-workflow', 'user');
        $this->receiver->givePermissionTo(['procurement.receive_purchase_orders', 'view-inventory']);
    }

    public function test_partial_receipt_posts_inventory_and_submitted_expense_once(): void
    {
        $finance = User::factory()->for($this->owner)->create();
        $finance->assignRole([
            Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'user']),
            Role::firstOrCreate(['name' => 'Finance Staff', 'guard_name' => 'user']),
        ]);
        [$po, $item, $inventory] = $this->poItem(5, 100);
        $po->update(['payment_terms' => 'Net 30']);
        $payload = $this->payload('receive-1', $item->id, 3, 1);

        $response = $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $payload)
            ->assertCreated();
		$this->assertSame(['message', 'data'], array_keys($response->json()));

        $response->assertJsonPath('data.items.0.accepted_quantity', 2);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(12, $inventory->fresh()->available_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $inventory->id,
            'quantity_change' => 2,
        ]);
        $expense = Expense::sole();
        $this->assertSame('submitted', $expense->status);
        $this->assertNull($expense->approved_by);
        $this->assertNull($expense->approval_id);
        $this->assertSame(0, Approval::where('approvable_type', Expense::class)
            ->where('approvable_id', $expense->id)
            ->count());
        $this->assertSame($this->receiver->id, $expense->created_by);
        $this->assertSame('200.00', $expense->amount);
        $this->assertSame(now()->addDays(30)->toDateString(), $expense->due_date->toDateString());
        $this->assertSame($response->json('data.id'), $expense->procurement_receipt_id);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $finance->id,
            'action_url' => "/finance?section=expense-tracking&expense={$expense->id}",
        ]);
        $this->actingAs($this->owner, 'shop_owner')
            ->getJson('/api/shop-owner/expenses?status=submitted')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($this->owner, 'shop_owner')
            ->postJson("/api/shop-owner/expenses/{$expense->id}/approve")
            ->assertNotFound();
        $finance->givePermissionTo(['access-approval-workflow', 'access-finance-expenses']);
        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/approvals/pending')
            ->assertOk()
            ->assertJsonCount(0, 'approvals');
        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Procurement receipt expenses are review-only and do not require approval.');
        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/reject", ['approval_notes' => 'Not required.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Procurement receipt expenses are review-only and do not require approval.');
        $this->assertSame('submitted', $expense->fresh()->status);
        $this->receiver->givePermissionTo('access-finance-expenses');
        $this->actingAs($this->receiver, 'user')->getJson("/api/finance/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('procurement_details.receipt_id', $response->json('data.id'))
            ->assertJsonPath('procurement_details.items.0.accepted_quantity', 2);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $payload)
            ->assertOk();

        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, Expense::count());
        $this->assertSame(12, $inventory->fresh()->available_quantity);
    }

    public function test_existing_procurement_expense_workflow_is_blocked_for_finance_approval(): void
    {
        $finance = User::factory()->for($this->owner)->create();
        $finance->givePermissionTo('access-approval-workflow');
        $finance->assignRole([
            Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'user']),
            Role::firstOrCreate(['name' => 'Finance Staff', 'guard_name' => 'user']),
        ]);
        [$po, $item] = $this->poItem(2, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('legacy-workflow', $item->id, 2, 0))
            ->assertCreated();

        $expense = Expense::sole();
        $legacyApproval = $this->attachLegacyApproval($expense);
        $expense->update([
            'approval_id' => $legacyApproval->id,
            'current_approval_level' => 2,
        ]);
        $finance->givePermissionTo(['access-approval-workflow', 'access-finance-expenses']);

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/approvals/pending')
            ->assertOk()
            ->assertJsonCount(0, 'approvals');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Procurement receipt expenses are review-only and do not require approval.');

        $this->assertSame('submitted', $expense->fresh()->status);
        $this->assertNull($expense->fresh()->approval_id);
        $this->assertSame(ApprovalStatus::CANCELLED, $legacyApproval->fresh()->status);
    }

    public function test_cod_or_unrecognized_supplier_terms_leave_due_date_empty(): void
    {
        [$po, $item] = $this->poItem(2, 100);
        $po->update(['payment_terms' => 'COD']);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('cod-terms', $item->id, 2, 0))
            ->assertCreated();

        $this->assertNull(Expense::sole()->due_date);
    }

    public function test_existing_procurement_expense_workflow_is_blocked_for_finance_rejection(): void
    {
        $finance = User::factory()->for($this->owner)->create();
        $finance->givePermissionTo('access-approval-workflow');
        $finance->assignRole(Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'user']));
        [$po, $item] = $this->poItem(2, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('legacy-rejection', $item->id, 2, 0))
            ->assertCreated();

        $expense = Expense::sole();
        $legacyApproval = $this->attachLegacyApproval($expense);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/reject", [
                'approval_notes' => 'Receipt cost needs correction.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Procurement receipt expenses are review-only and do not require approval.');

        $this->assertSame('submitted', $expense->fresh()->status);
        $this->assertNull($expense->fresh()->approval_id);
        $this->assertSame(ApprovalStatus::CANCELLED, $legacyApproval->fresh()->status);
    }

    public function test_same_key_with_different_payload_returns_conflict(): void
    {
        [$po, $item] = $this->poItem(5, 100);
        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('same-key', $item->id, 1, 0))
            ->assertCreated();

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('same-key', $item->id, 2, 0))
            ->assertConflict();

        $this->assertSame(1, PurchaseOrderReceipt::count());
    }

    public function test_defects_then_replacements_complete_the_po(): void
    {
        [$po, $item, $inventory] = $this->poItem(5, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('first', $item->id, 5, 2))
            ->assertCreated();
        $this->assertSame('partially_received', $po->fresh()->status);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('replacement', $item->id, 2, 0))
            ->assertCreated();

        $this->assertSame('delivered', $po->fresh()->status);
        $this->assertSame(15, $inventory->fresh()->available_quantity);
        $this->assertEqualsCanonicalizing(['300.00', '200.00'], Expense::pluck('amount')->all());
    }

    public function test_all_size_receipt_posts_exact_inventory_allocations(): void
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
        [$po, $item] = $this->poItem(5, 100, [
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => $sizes->pluck('id')->all(),
            'line_total' => 500,
        ]);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", [
                'idempotency_key' => 'all-sizes',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 5,
                    'defective_quantity' => 1,
                    'size_quantities' => [
                        ['inventory_size_id' => $sizes[0]->id, 'received_quantity' => 2, 'defective_quantity' => 0],
                        ['inventory_size_id' => $sizes[1]->id, 'received_quantity' => 1, 'defective_quantity' => 1],
                        ['inventory_size_id' => $sizes[2]->id, 'received_quantity' => 2, 'defective_quantity' => 0],
                    ],
                ]],
            ])
            ->assertCreated();

        $this->assertSame(4, $inventory->fresh()->available_quantity);
        $this->assertSame([2, 0, 2], $sizes->map(fn ($size) => $size->fresh()->quantity)->all());
        $this->assertSame('400.00', Expense::sole()->amount);
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_normalized_all_size_receipt_accepts_quantity_for_each_configured_size(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'shoes',
            'available_quantity' => 0,
        ]);
        $sizes = collect(['3', '5', '7', '9'])->map(fn ($size) => InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => $size,
            'size_system' => 'US',
            'quantity' => 0,
        ]));
        [$po, $item] = $this->poItem(200, 100, [
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'requested_color' => null,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => $sizes->pluck('id')->all(),
            'line_total' => 20000,
        ]);

        $response = $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", [
                'idempotency_key' => 'normalized-all-sizes',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 200,
                    'defective_quantity' => 0,
                    'size_quantities' => $sizes->map(fn ($size) => [
                        'inventory_size_id' => $size->id,
                        'received_quantity' => 50,
                        'defective_quantity' => 0,
                    ])->all(),
                ]],
            ])
            ->assertCreated();

        $this->assertSame('delivered', $po->fresh()->status);
        $this->assertSame(200, $inventory->fresh()->available_quantity);
        $this->assertSame([50, 50, 50, 50], $sizes->map(fn ($size) => $size->fresh()->quantity)->all());
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $inventory->id,
            'quantity_change' => 200,
        ]);
        $this->assertSame('20000.00', \App\Models\Finance\Expense::sole()->amount);
        $this->assertSame(200, $response->json('data.items.0.accepted_quantity'));
    }

    public function test_all_size_receipt_rejects_over_receiving_one_size(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'shoes',
            'available_quantity' => 0,
        ]);
        $sizes = collect(['3', '5', '7', '9'])->map(fn ($size) => InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => $size,
            'size_system' => 'US',
            'quantity' => 0,
        ]));
        [$po, $item] = $this->poItem(200, 100, [
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'eligible_size_ids' => $sizes->pluck('id')->all(),
            'line_total' => 20000,
        ]);

        $response = $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", [
                'idempotency_key' => 'over-one-size',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 200,
                    'defective_quantity' => 0,
                    'size_quantities' => $sizes->map(fn ($size, $index) => [
                        'inventory_size_id' => $size->id,
                        'received_quantity' => $index === 0 ? 200 : 0,
                        'defective_quantity' => 0,
                    ])->all(),
                ]],
            ])
            ->assertUnprocessable();

        $response->assertJsonValidationErrors('items');
        $this->assertSame('in_transit', $po->fresh()->status);
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $this->assertSame(0, $inventory->fresh()->available_quantity);
        $this->assertSame([0, 0, 0, 0], $sizes->map(fn ($size) => $size->fresh()->quantity)->all());
    }

    public function test_all_size_receipt_requires_each_snapshotted_size(): void
    {
        $inventory = InventoryItem::factory()->create(['shop_owner_id' => $this->owner->id, 'category' => 'shoes']);
        $sizes = collect(['7', '8'])->map(fn ($size) => InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => $size,
            'size_system' => 'US',
            'quantity' => 0,
        ]));
        [$po, $item] = $this->poItem(2, 100, [
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'eligible_size_ids' => $sizes->pluck('id')->all(),
        ]);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('missing-allocation', $item->id, 2, 0))
            ->assertUnprocessable();

        $this->assertSame(0, PurchaseOrderReceipt::count());
    }

    public function test_specific_size_full_receipt_delivers_and_updates_only_that_size(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'shoes',
            'available_quantity' => 0,
        ]);
        $target = InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 0,
        ]);
        $other = InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 0,
        ]);
        [$po, $item] = $this->poItem(2, 100, [
            'inventory_item_id' => $inventory->id,
            'requested_size' => 'US 8',
            'eligible_size_ids' => [$target->id],
        ]);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('specific', $item->id, 2, 0))
            ->assertCreated();

        $this->assertSame('delivered', $po->fresh()->status);
        $this->assertSame(2, $inventory->fresh()->available_quantity);
        $this->assertSame(2, $target->fresh()->quantity);
        $this->assertSame(0, $other->fresh()->quantity);
    }

    public function test_fully_defective_receipt_creates_no_expense(): void
    {
        [$po, $item, $inventory] = $this->poItem(2, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('defective', $item->id, 2, 2))
            ->assertCreated();

        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_missing_snapshotted_size_rolls_back_the_whole_receipt(): void
    {
        [$po, $item, $inventory] = $this->poItem(2, 100, [
            'requested_size' => 'US 8',
            'eligible_size_ids' => [999999],
        ]);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('missing-size', $item->id, 1, 0))
            ->assertUnprocessable();

        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_invalid_receipts_leave_no_side_effects(): void
    {
        [$po, $item, $inventory] = $this->poItem(2, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('too-many', $item->id, 3, 0))
            ->assertUnprocessable();

        $po->update(['status' => 'confirmed']);
        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('wrong-state', $item->id, 1, 0))
            ->assertForbidden();

        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_total_received_cannot_exceed_remaining_ordered_quantity(): void
    {
        [$po, $item, $inventory] = $this->poItem(5, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('over-physical', $item->id, 6, 1))
            ->assertUnprocessable();

        $this->assertSame('in_transit', $po->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_zero_quantity_receipt_is_rejected_without_side_effects(): void
    {
        [$po, $item, $inventory] = $this->poItem(2, 100);

        $this->actingAs($this->receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/receipts", $this->payload('zero', $item->id, 0, 0))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame('in_transit', $po->fresh()->status);
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $this->assertSame(0, Expense::count());
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

    private function attachLegacyApproval(Expense $expense): Approval
    {
        $approval = Approval::create([
            'shop_owner_id' => $this->owner->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'requested_by' => $this->receiver->id,
            'current_level' => 2,
            'total_levels' => 4,
            'status' => ApprovalStatus::PENDING,
            'approval_roles' => [
                '1' => 'finance',
                '2' => 'shop_owner',
                '3' => 'finance',
                '4' => 'finance_final',
            ],
            'current_approver_role' => 'shop_owner',
            'level_reviewers' => [],
            'metadata' => ['source' => 'legacy_procurement_expense'],
        ]);

        $expense->update([
            'approval_id' => $approval->id,
            'current_approval_level' => 2,
        ]);

        return $approval;
    }
}
