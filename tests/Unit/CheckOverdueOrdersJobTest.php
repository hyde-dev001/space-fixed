<?php

namespace Tests\Unit;

use App\Events\SupplierOrderOverdue;
use App\Jobs\CheckOverdueOrdersJob;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CheckOverdueOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_marks_orders_as_overdue()
    {
        Event::fake([SupplierOrderOverdue::class]);

        $shopOwner = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $overdueOrder = SupplierOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => 'confirmed',
            'expected_delivery_date' => now()->subDays(3),
        ]);

        $onTimeOrder = SupplierOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => 'confirmed',
            'expected_delivery_date' => now()->addDays(3),
        ]);

        $job = new CheckOverdueOrdersJob($shopOwner->id);
        $job->handle();

        Event::assertDispatched(
            SupplierOrderOverdue::class,
            fn (SupplierOrderOverdue $event) => $event->supplierOrder->is($overdueOrder),
        );
        Event::assertDispatchedTimes(SupplierOrderOverdue::class, 1);
        $this->assertEquals('confirmed', $onTimeOrder->fresh()->status);
    }

    /** @test */
    public function it_only_checks_confirmed_orders()
    {
        Event::fake([SupplierOrderOverdue::class]);

        $shopOwner = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $draftOrder = SupplierOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => 'draft',
            'expected_delivery_date' => now()->subDays(3),
        ]);

        $job = new CheckOverdueOrdersJob($shopOwner->id);
        $job->handle();

        Event::assertNotDispatched(SupplierOrderOverdue::class);
        $this->assertEquals('draft', $draftOrder->fresh()->status);
    }
}
