<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $owner;
    private User $user;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        $this->owner = ShopOwner::factory()->create();
        $this->user = User::factory()->for($this->owner)->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->owner->id]);
        Permission::findOrCreate('procurement.create_purchase_orders', 'user');
        Permission::findOrCreate('procurement.view', 'user');
        $this->user->givePermissionTo('procurement.create_purchase_orders');
        $this->user->givePermissionTo('procurement.view');
    }

    public function test_one_or_more_approved_prs_create_server_snapshotted_items(): void
    {
        $first = $this->approvedPr(['quantity' => 2, 'unit_cost' => 100, 'total_cost' => 200]);
        $second = $this->approvedPr(['product_name' => 'Repair glue', 'quantity' => 3, 'unit_cost' => 50, 'total_cost' => 150]);

        $response = $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$first->id, $second->id],
            'payment_terms' => 'Net 30',
        ]);

        $response->assertCreated()->assertJsonPath('data.total_cost', '350.00');
        $po = PurchaseOrder::with('items')->sole();
        $this->assertCount(2, $po->items);
        $this->assertNull($po->pr_id);
        $this->assertSame('350.00', $po->total_cost);
        $this->assertSame(2, $po->items->firstWhere('purchase_request_id', $first->id)->ordered_quantity);
        $this->assertSame('200.00', $po->items->firstWhere('purchase_request_id', $first->id)->line_total);
    }

    public function test_client_authored_line_values_are_rejected(): void
    {
        $pr = $this->approvedPr();

        $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$pr->id],
            'payment_terms' => 'COD',
            'quantity' => 999,
            'total_cost' => 1,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_all_size_order_keeps_total_units_and_snapshots_sizes_without_multiplying(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'shoes',
        ]);
        $sizeIds = collect(['6', '7', '8', '9'])->map(fn ($size) => InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => $size,
            'size_system' => 'US',
            'quantity' => 0,
        ])->id)->all();
        $pr = $this->approvedPr([
            'inventory_item_id' => $inventory->id,
            'requested_size' => null,
            'quantity' => 200,
            'unit_cost' => 4100,
            'total_cost' => 820000,
        ]);

        $response = $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$pr->id],
            'payment_terms' => 'COD',
        ])->assertCreated();

        $po = PurchaseOrder::with('items')->findOrFail($response->json('data.id'));
        $this->assertSame(200, $po->quantity);
        $this->assertSame('820000.00', $po->total_cost);
        $this->assertSame(200, $po->items->sole()->ordered_quantity);
        $this->assertSame(1, $po->items->sole()->quantity_multiplier);
        $this->assertEqualsCanonicalizing($sizeIds, $po->items->sole()->eligible_size_ids);
    }

    public function test_invalid_requested_variant_is_rejected_before_po_creation(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->owner->id,
            'category' => 'shoes',
        ]);
        InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 0,
        ]);
        $pr = $this->approvedPr([
            'inventory_item_id' => $inventory->id,
            'requested_size' => 'US 9',
        ]);

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/procurement/purchase-orders', [
                'purchase_request_ids' => [$pr->id],
                'payment_terms' => 'COD',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_mixed_supplier_and_unapproved_prs_are_rejected(): void
    {
        $approved = $this->approvedPr();
        $otherSupplier = Supplier::factory()->create(['shop_owner_id' => $this->owner->id]);
        $mixed = $this->approvedPr(['supplier_id' => $otherSupplier->id]);
        $draft = $this->approvedPr(['status' => 'draft']);

        $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$approved->id, $mixed->id],
            'payment_terms' => 'COD',
        ])->assertUnprocessable();

        $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$draft->id],
            'payment_terms' => 'COD',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_non_cancelled_po_blocks_pr_reuse_but_cancelled_po_releases_it(): void
    {
        $pr = $this->approvedPr();
        $payload = ['purchase_request_ids' => [$pr->id], 'payment_terms' => 'COD'];

        $first = $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/procurement/purchase-orders', $payload)
            ->assertCreated();

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/procurement/purchase-orders', $payload)
            ->assertUnprocessable();

        PurchaseOrder::findOrFail($first->json('data.id'))->update(['status' => 'cancelled']);

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/procurement/purchase-orders', $payload)
            ->assertCreated();

        $this->assertDatabaseCount('purchase_orders', 2);
    }

    public function test_grouped_po_hides_used_prs_until_the_po_is_cancelled(): void
    {
        $first = $this->approvedPr();
        $second = $this->approvedPr(['product_name' => 'Shoe glue']);

        $response = $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$first->id, $second->id],
            'payment_terms' => 'COD',
        ])->assertCreated();

        $this->actingAs($this->user, 'user')
            ->getJson('/api/erp/procurement/purchase-requests/approved')
            ->assertOk()
            ->assertJsonCount(0);

        PurchaseOrder::findOrFail($response->json('data.id'))->update(['status' => 'cancelled']);

        $this->actingAs($this->user, 'user')
            ->getJson('/api/erp/procurement/purchase-requests/approved')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_purchase_order_may_be_expected_today(): void
    {
        $pr = $this->approvedPr();

        $poId = $this->actingAs($this->user, 'user')->postJson('/api/erp/procurement/purchase-orders', [
            'purchase_request_ids' => [$pr->id],
            'expected_delivery_date' => today()->toDateString(),
            'payment_terms' => 'COD',
        ])->assertCreated()->json('data.id');

        Permission::findOrCreate('procurement.manage_purchase_orders', 'user');
        $this->user->givePermissionTo('procurement.manage_purchase_orders');

        $this->putJson("/api/erp/procurement/purchase-orders/{$poId}", [
            'expected_delivery_date' => today()->toDateString(),
        ])->assertOk();
    }

    private function approvedPr(array $overrides = []): PurchaseRequest
    {
        return PurchaseRequest::factory()->create(array_merge([
            'shop_owner_id' => $this->owner->id,
            'supplier_id' => $this->supplier->id,
            'requested_by' => $this->user->id,
            'status' => 'approved',
            'requested_size' => 'US 8',
        ], $overrides));
    }
}
