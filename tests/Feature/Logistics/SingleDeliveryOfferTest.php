<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SingleDeliveryOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopOwner::created(function (ShopOwner $shop): void {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $shop->id,
                'module_key' => 'logistics',
                'enabled' => true,
            ]);
        });
    }

    public function test_rider_can_accept_or_reject_a_single_delivery_offer(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $user->id,
            'rider_type' => 'employee',
            'active' => true,
            'availability_status' => 'available',
        ]);
        $otherUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $otherUser->id,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $acceptedLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'pending',
            'requires_pickup_proof' => false,
        ]);
        $rejectedLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'pending',
            'requires_pickup_proof' => false,
        ]);

        $acceptedAssignmentId = $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$acceptedLeg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.status', 'assigned')
            ->json('assignment.id');

        try {
            app(ShipmentLegService::class)->markPickedUp($acceptedLeg->fresh(), $rider);
            $this->fail('The rider started a single delivery before accepting its offer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rider', $exception->errors());
        }

        $this->actingAs($otherUser, 'user')
            ->postJson("/api/logistics/legs/{$acceptedLeg->id}/accept")
            ->assertForbidden();

        $this->actingAs($user, 'user')
            ->postJson("/api/logistics/legs/{$acceptedLeg->id}/accept")
            ->assertOk()
            ->assertJsonPath('assignment.status', 'accepted');

        $this->assertDatabaseHas('delivery_assignments', [
            'id' => $acceptedAssignmentId,
            'status' => 'accepted',
        ]);

        $rejectedAssignmentId = $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$rejectedLeg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertOk()
            ->json('assignment.id');

        $this->actingAs($user, 'user')
            ->postJson("/api/logistics/legs/{$rejectedLeg->id}/reject", [
                'rejection_reason' => 'Schedule conflict',
            ])
            ->assertOk()
            ->assertJsonPath('assignment.status', 'rejected')
            ->assertJsonPath('leg.status', 'pending');

        $this->actingAs($user, 'user')
            ->postJson("/api/logistics/legs/{$rejectedLeg->id}/reject", [
                'rejection_reason' => 'Schedule conflict',
            ])
            ->assertOk()
            ->assertJsonPath('assignment.id', $rejectedAssignmentId)
            ->assertJsonPath('assignment.status', 'rejected');

        $this->assertDatabaseHas('delivery_assignments', [
            'id' => $rejectedAssignmentId,
            'status' => 'rejected',
            'rejection_reason' => 'Schedule conflict',
        ]);
        $this->assertSame(1, DeliveryEvent::where('event_type', 'leg_offer_rejected')->count());
        $this->assertSame('pending', $rejectedLeg->fresh()->status->value);
    }
}
