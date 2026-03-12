<?php

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_supplier()
    {
        $shopOwner = ShopOwner::factory()->create();

        $supplier = Supplier::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
            'phone' => '1234567890',
            'address' => '123 Test St',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
        ]);
    }

    /** @test */
    public function it_has_orders_relationship()
    {
        $supplier = Supplier::factory()->create();
        
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            $supplier->orders()
        );
    }

    /** @test */
    public function it_belongs_to_shop_owner()
    {
        $supplier = Supplier::factory()->create();
        
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            $supplier->shopOwner()
        );
    }

    /** @test */
    public function it_can_have_multiple_orders()
    {
        $supplier = Supplier::factory()->create();
        
        $supplier->orders()->create([
            'order_number' => 'PO-001',
            'status' => 'pending',
            'total_amount' => 1000,
            'expected_delivery_date' => now()->addDays(7),
            'created_by' => 1,
        ]);

        $supplier->orders()->create([
            'order_number' => 'PO-002',
            'status' => 'confirmed',
            'total_amount' => 2000,
            'expected_delivery_date' => now()->addDays(10),
            'created_by' => 1,
        ]);

        $this->assertCount(2, $supplier->orders);
    }
}
