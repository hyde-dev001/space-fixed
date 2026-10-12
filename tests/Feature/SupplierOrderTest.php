<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        $this->shopOwner = ShopOwner::factory()->approved()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        Permission::findOrCreate('view-inventory', 'user');
        $this->user->givePermissionTo('view-inventory');
        $this->actingAs($this->user, 'user');
    }

    /** @test */
    public function it_lists_supplier_orders()
    {
        SupplierOrder::factory()->count(5)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/erp/inventory/supplier-orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'po_number',
                        'status',
                        'total_amount',
                    ]
                ],
            ]);
    }

    /** @test */
    public function legacy_supplier_order_mutations_return_gone()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->postJson('/api/erp/inventory/supplier-orders', [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->format('Y-m-d'),
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
            'items' => [
                [
                    'inventory_item_id' => $item->id,
                    'product_name' => $item->name,
                    'quantity' => 10,
                    'unit_price' => 50.00,
                ]
            ],
            'remarks' => 'Test order',
        ]);

        $response->assertGone()->assertJsonPath('data.canonical_url', '/api/erp/procurement/purchase-orders');
    }

    /** @test */
    public function it_shows_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/erp/inventory/supplier-orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $order->id,
                'po_number' => $order->po_number,
            ]);
    }

    /** @test */
    public function it_updates_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'draft',
        ]);

        $response = $this->putJson("/api/erp/inventory/supplier-orders/{$order->id}", [
            'supplier_id' => $this->supplier->id,
            'expected_delivery_date' => now()->addDays(10)->format('Y-m-d'),
            'remarks' => 'Updated notes',
        ]);

        $response->assertGone();
        $this->assertNotEquals('Updated notes', $order->fresh()->remarks);
    }

    /** @test */
    public function it_receives_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'confirmed',
        ]);

        $item = SupplierOrderItem::create([
            'supplier_order_id' => $order->id,
            'product_name' => 'Test Product',
            'quantity' => 10,
        ]);

        $response = $this->postJson("/api/erp/inventory/supplier-orders/{$order->id}/receive", [
            'items' => [[
                'id' => $item->id,
                'quantity_received' => 10,
            ]],
        ]);

        $response->assertGone();
        $this->assertEquals('confirmed', $order->fresh()->status);
    }

    /** @test */
    public function it_filters_orders_by_status()
    {
        SupplierOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'draft',
        ]);

        SupplierOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'delivered',
        ]);

        $response = $this->getJson('/api/erp/inventory/supplier-orders?status=draft');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function it_deletes_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'draft',
        ]);

        $response = $this->deleteJson("/api/erp/inventory/supplier-orders/{$order->id}");

        $response->assertGone();
        $this->assertNotSoftDeleted($order);
    }
}
