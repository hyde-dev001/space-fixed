<?php

namespace Tests\Unit;

use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierOrderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_supplier_order()
    {
        $supplier = Supplier::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $supplier->shop_owner_id]);

        $order = SupplierOrder::create([
            'shop_owner_id' => $supplier->shop_owner_id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-001',
            'status' => 'draft',
            'order_date' => now(),
            'total_amount' => 1000,
            'expected_delivery_date' => now()->addDays(7),
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('supplier_orders', [
            'po_number' => 'PO-001',
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function it_belongs_to_supplier()
    {
        $order = SupplierOrder::factory()->create();
        
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            $order->supplier()
        );
    }

    /** @test */
    public function it_has_items_relationship()
    {
        $order = SupplierOrder::factory()->create();
        
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            $order->items()
        );
    }

    /** @test */
    public function it_checks_if_order_is_overdue()
    {
        $overdueOrder = SupplierOrder::factory()->create([
            'status' => 'confirmed',
            'expected_delivery_date' => now()->subDays(1),
        ]);

        $onTimeOrder = SupplierOrder::factory()->create([
            'status' => 'confirmed',
            'expected_delivery_date' => now()->addDays(1),
        ]);

        $this->assertTrue($overdueOrder->is_overdue);
        $this->assertFalse($onTimeOrder->is_overdue);
    }

    /** @test */
    public function delivered_order_is_not_overdue()
    {
        $deliveredOrder = SupplierOrder::factory()->create([
            'status' => 'delivered',
            'expected_delivery_date' => now()->subDays(1),
        ]);

        $this->assertFalse($deliveredOrder->is_overdue);
    }

    /** @test */
    public function it_can_be_marked_as_delivered()
    {
        $order = SupplierOrder::factory()->create([
            'status' => 'confirmed',
        ]);

        $order->markAsDelivered();

        $this->assertEquals('delivered', $order->status);
        $this->assertNotNull($order->actual_delivery_date);
    }
}
