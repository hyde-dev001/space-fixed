<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_rider_can_only_be_assigned_for_individual_shop(): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'shop_owner',
        ]);

        $this->expectException(ValidationException::class);

        app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);
    }

    public function test_employee_rider_can_be_assigned_for_company_shop(): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $company->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $user->id,
        ]);

        $assignment = app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);

        $this->assertSame('assigned', $assignment->status);
        $this->assertSame('assigned', $leg->fresh()->status->value);
    }
}
