<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LogisticsOperationalMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_metrics_are_tenant_scoped(): void
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('access-logistics-dashboard', 'user');
        $user->givePermissionTo('access-logistics-dashboard');
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $due = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'pending', 'scheduled_delivery_date' => today()]);
        ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivery_attempted', 'scheduled_delivery_date' => today()->subDay()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $due->id, 'status' => 'assigned']);
        ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create()->id, 'status' => 'pending', 'scheduled_delivery_date' => today()]);

        $stats = $this->actingAs($user, 'user')->get('/erp/logistics')->assertOk()->viewData('page')['props']['stats'];

        $this->assertSame(1, $stats['due_today']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame(1, $stats['failed_attempts']);
        $this->assertSame(1, $stats['rider_workload']);
    }
}
