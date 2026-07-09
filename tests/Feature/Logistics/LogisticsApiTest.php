<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_without_logistics_permission_cannot_assign_leg(): void
    {
        $user = User::factory()->create();
        $leg = ShipmentLeg::factory()->create();

        $this->actingAs($user, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [])
            ->assertForbidden();
    }

    public function test_shop_owner_can_assign_leg_to_valid_rider(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'individual']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'shop_owner',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.status', 'assigned');
    }
}
