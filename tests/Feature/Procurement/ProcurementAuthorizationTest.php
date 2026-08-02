<?php

namespace Tests\Feature\Procurement;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\StockRequestApproval;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProcurementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
    }

    public function test_cross_shop_purchase_order_is_hidden(): void
    {
        [$user] = $this->userForShop();
        [, $otherShop] = $this->userForShop();
        $this->give($user, 'procurement.view');

        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'supplier_id' => Supplier::factory()->create(['shop_owner_id' => $otherShop->id])->id,
        ]);

        $this->actingAs($user)
            ->getJson("/api/erp/procurement/purchase-orders/{$purchaseOrder->id}")
            ->assertNotFound();
    }

    public function test_dashboard_access_does_not_authorize_purchase_request_review(): void
    {
        [$user, $shop] = $this->userForShop();
        $this->give($user, 'access-procurement-dashboard');
        $requester = User::factory()->for($shop)->create();
        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'requested_by' => $requester->id,
            'status' => 'pending_finance',
        ]);

        $this->actingAs($user)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Reviewed.',
            ])
            ->assertForbidden();
    }

    public function test_requester_cannot_review_their_own_purchase_request(): void
    {
        [$requester, $shop] = $this->userForShop();
        $this->give($requester, 'procurement.review_purchase_requests');
        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'requested_by' => $requester->id,
            'status' => 'pending_finance',
        ]);

        $this->actingAs($requester)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Self reviewed.',
            ])
            ->assertForbidden();
    }

    public function test_purchase_request_rejects_foreign_supplier_and_inventory_ids(): void
    {
        [$user] = $this->userForShop();
        [, $otherShop] = $this->userForShop();
        $this->give($user, 'procurement.create_purchase_requests');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $otherShop->id]);
        $inventoryItem = InventoryItem::factory()->create(['shop_owner_id' => $otherShop->id]);

        $this->actingAs($user)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'product_name' => 'Foreign stock',
                'supplier_id' => $supplier->id,
                'inventory_item_id' => $inventoryItem->id,
                'quantity' => 1,
                'unit_cost' => 100,
                'priority' => 'medium',
                'justification' => 'Tenant boundary check.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id', 'inventory_item_id']);
    }

    public function test_purchase_order_rejects_a_foreign_purchase_request_id(): void
    {
        [$user] = $this->userForShop();
        [, $otherShop] = $this->userForShop();
        $this->give($user, 'procurement.create_purchase_orders');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $otherShop->id]);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'supplier_id' => $supplier->id,
            'status' => 'approved',
        ]);

        $this->actingAs($user)
            ->postJson('/api/erp/procurement/purchase-orders', [
                'pr_id' => $purchaseRequest->id,
                'payment_terms' => 'COD',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pr_id');
    }

    public function test_seeded_receiving_and_void_permissions_are_separate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $inventory = Role::findByName('Inventory Manager', 'user');
        $procurement = Role::findByName('Procurement Manager', 'user');

        $this->assertTrue($inventory->hasPermissionTo('procurement.receive_purchase_orders'));
        $this->assertFalse($inventory->hasPermissionTo('procurement.void_purchase_order_receipts'));
        $this->assertFalse($procurement->hasPermissionTo('procurement.receive_purchase_orders'));
        $this->assertFalse($procurement->hasPermissionTo('procurement.void_purchase_order_receipts'));
    }

    public function test_receiving_permission_without_inventory_access_is_forbidden(): void
    {
        [$user, $shop] = $this->userForShop();
        $this->give($user, 'procurement.receive_purchase_orders');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'in_transit',
        ]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'ordered_quantity' => 1,
        ]);

        $this->actingAs($user)
            ->postJson("/api/erp/procurement/purchase-orders/{$purchaseOrder->id}/receipts", [
                'idempotency_key' => 'inventory-gate',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 1,
                    'defective_quantity' => 0,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_completion_uses_the_dedicated_permission(): void
    {
        [$completer, $shop] = $this->userForShop();
        $this->give($completer, 'procurement.complete_purchase_orders');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'delivered',
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'ordered_quantity' => 0,
        ]);

        $this->actingAs($completer)
            ->postJson("/api/erp/procurement/purchase-orders/{$purchaseOrder->id}/update-status", ['status' => 'completed'])
            ->assertOk();

        [$manager] = $this->userForShop();
        $this->give($manager, 'procurement.manage_purchase_orders');
        $otherSupplier = Supplier::factory()->create(['shop_owner_id' => $manager->shop_owner_id]);
        $otherOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $manager->shop_owner_id,
            'supplier_id' => $otherSupplier->id,
            'status' => 'delivered',
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $otherOrder->id,
            'ordered_quantity' => 0,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/erp/procurement/purchase-orders/{$otherOrder->id}/update-status", ['status' => 'completed'])
            ->assertForbidden();
    }

    public function test_cross_shop_stock_request_is_hidden(): void
    {
        [$reviewer] = $this->userForShop();
        [$foreignRequester, $otherShop] = $this->userForShop();
        $this->give($reviewer, 'procurement.review_stock_requests');
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'inventory_item_id' => InventoryItem::factory()->create(['shop_owner_id' => $otherShop->id])->id,
            'requested_by' => $foreignRequester->id,
        ]);

        $this->actingAs($reviewer)
            ->getJson("/api/erp/procurement/stock-requests/{$stockRequest->id}")
            ->assertNotFound();
    }

    public function test_dashboard_access_does_not_authorize_stock_request_approval(): void
    {
        [$reviewer, $shop] = $this->userForShop();
        $this->give($reviewer, 'access-procurement-dashboard');
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => InventoryItem::factory()->create(['shop_owner_id' => $shop->id])->id,
            'requested_by' => User::factory()->for($shop)->create()->id,
        ]);

        $this->actingAs($reviewer)
            ->postJson("/api/erp/procurement/stock-requests/{$stockRequest->id}/approve", [
                'approval_notes' => 'Should not be allowed.',
            ])
            ->assertForbidden();
    }

    public function test_stock_request_rejects_foreign_inventory_id(): void
    {
        [$requester] = $this->userForShop();
        [, $otherShop] = $this->userForShop();
        $this->give($requester, 'procurement.create_purchase_requests');
        $inventoryItem = InventoryItem::factory()->create(['shop_owner_id' => $otherShop->id]);

        $this->actingAs($requester)
            ->postJson('/api/erp/procurement/stock-requests', [
                'inventory_item_id' => $inventoryItem->id,
                'quantity_needed' => 2,
                'priority' => 'medium',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inventory_item_id');
    }

    public function test_supplier_mutation_requires_manage_permission(): void
    {
        [$viewer, $shop] = $this->userForShop();
        $this->give($viewer, 'access-procurement-dashboard');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($viewer)
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}", ['name' => 'Unauthorized rename'])
            ->assertForbidden();
    }

    public function test_partially_received_order_blocks_supplier_archiving(): void
    {
        [$manager, $shop] = $this->userForShop();
        $this->give($manager, 'procurement.manage_suppliers');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'partially_received',
        ]);

        $this->actingAs($manager)
            ->deleteJson("/api/erp/procurement/suppliers/{$supplier->id}")
            ->assertUnprocessable();

        $this->assertNull($supplier->fresh()->deleted_at);
    }

    public function test_unsupported_supplier_analysis_routes_are_absent(): void
    {
        [$viewer, $shop] = $this->userForShop();
        $this->give($viewer, 'procurement.view');
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($viewer)
            ->getJson("/api/erp/procurement/suppliers/{$supplier->id}/performance")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->getJson("/api/erp/procurement/suppliers/{$supplier->id}/purchase-history")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->postJson("/api/erp/procurement/suppliers/{$supplier->id}/rating", ['rating' => 5])
            ->assertNotFound();
    }

    /** @return array{User, ShopOwner} */
    private function userForShop(): array
    {
        $shop = ShopOwner::factory()->create();

        return [User::factory()->for($shop)->create(), $shop];
    }

    private function give(User $user, string $permission): void
    {
        Permission::findOrCreate($permission, 'user');
        $user->givePermissionTo($permission);
    }
}
