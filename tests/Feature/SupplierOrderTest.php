<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
        
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_lists_supplier_orders()
    {
        SupplierOrder::factory()->count(5)->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/erp/inventory/supplier-orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'order_number',
                        'status',
                        'total_amount',
                    ]
                ],
            ]);
    }

    /** @test */
    public function it_creates_supplier_order()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->postJson('/api/erp/inventory/supplier-orders', [
            'supplier_id' => $this->supplier->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
            'items' => [
                [
                    'inventory_item_id' => $item->id,
                    'quantity' => 10,
                    'unit_price' => 50.00,
                ]
            ],
            'notes' => 'Test order',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'order_number',
                'status',
            ]);
    }

    /** @test */
    public function it_shows_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/erp/inventory/supplier-orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $order->id,
                'order_number' => $order->order_number,
            ]);
    }

    /** @test */
    public function it_updates_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/erp/inventory/supplier-orders/{$order->id}", [
            'expected_delivery_date' => now()->addDays(10)->format('Y-m-d'),
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated notes', $order->fresh()->notes);
    }

    /** @test */
    public function it_updates_order_status()
    {
        $order = SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->patchJson("/api/erp/inventory/supplier-orders/{$order->id}/status", [
            'status' => 'confirmed',
            'notes' => 'Order confirmed',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $order->fresh()->status);
    }

    /** @test */
    public function it_receives_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/erp/inventory/supplier-orders/{$order->id}/receive", [
            'received_date' => now()->format('Y-m-d H:i:s'),
            'notes' => 'Order received',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('delivered', $order->fresh()->status);
    }

    /** @test */
    public function it_cannot_receive_pending_order()
    {
        $order = SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/erp/inventory/supplier-orders/{$order->id}/receive", [
            'received_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_filters_orders_by_status()
    {
        SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);

        SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'delivered',
        ]);

        $response = $this->getJson('/api/erp/inventory/supplier-orders?status=pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function it_deletes_supplier_order()
    {
        $order = SupplierOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->deleteJson("/api/erp/inventory/supplier-orders/{$order->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('supplier_orders', ['id' => $order->id]);
    }
}
