<?php

namespace Tests\Unit;

use App\Jobs\CheckOverdueOrdersJob;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckOverdueOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_marks_orders_as_overdue()
    {
        $shopOwner = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $overdueOrder = SupplierOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => 'confirmed',
            'expected_delivery_date' => now()->subDays(3),
        ]);

        $onTimeOrder = SupplierOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => 'confirmed',
            'expected_delivery_date' => now()->addDays(3),
        ]);

        $job = new CheckOverdueOrdersJob();
        $job->handle();

        $this->assertEquals('overdue', $overdueOrder->fresh()->status);
        $this->assertEquals('confirmed', $onTimeOrder->fresh()->status);
    }

    /** @test */
    public function it_only_checks_confirmed_orders()
    {
        $shopOwner = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $pendingOrder = SupplierOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => 'pending',
            'expected_delivery_date' => now()->subDays(3),
        ]);

        $job = new CheckOverdueOrdersJob();
        $job->handle();

        $this->assertEquals('pending', $pendingOrder->fresh()->status);
    }
}
