<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
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

    public function test_leg_cannot_have_duplicate_active_rider_assignments(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'pending']);
        $first = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $second = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $first->id,
            ])
            ->assertOk();

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $second->id,
            ])
            ->assertUnprocessable();
    }

    public function test_rider_cannot_update_leg_assigned_to_another_rider(): void
    {
        Permission::firstOrCreate(['name' => 'update-logistics-status', 'guard_name' => 'user']);

        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'picked_up']);
        $assignedRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $riderProfile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $assignedRider->id,
        ]);

        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $riderProfile->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        $otherRider->givePermissionTo('update-logistics-status');

        $this->actingAs($otherRider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/in-transit")
            ->assertForbidden();
    }

    public function test_shop_owner_can_upload_delivery_proof_before_delivery(): void
    {
        Storage::fake('public');

        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);

        $response = $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            ])
            ->assertCreated();

        Storage::disk('public')->assertExists($response->json('proof.file_path'));
    }

    public function test_proof_cannot_be_changed_after_delivery(): void
    {
        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivered']);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'file_path' => 'proof.jpg',
            ])
            ->assertUnprocessable();
    }
}
