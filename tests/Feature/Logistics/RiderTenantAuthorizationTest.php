<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\BatchDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiderTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_profile_cannot_link_a_user_from_another_shop(): void
    {
        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $foreignUser = User::factory()->create(['shop_owner_id' => $foreignShop->id]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/logistics/riders', [
                'rider_type' => 'employee',
                'linked_type' => User::class,
                'linked_id' => $foreignUser->id,
                'name' => 'Foreign rider',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linked_id');

        $this->assertDatabaseMissing('rider_profiles', [
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $foreignUser->id,
        ]);
    }

    public function test_rider_profile_rejects_unsupported_linked_type(): void
    {
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/logistics/riders', [
                'rider_type' => 'contractor',
                'linked_type' => ShopOwner::class,
                'linked_id' => $shop->id,
                'name' => 'Unsupported rider',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linked_type');
    }

    public function test_employee_rider_profile_requires_a_linked_user(): void
    {
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/logistics/riders', [
                'rider_type' => 'employee',
                'name' => 'Unlinked rider',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['linked_type', 'linked_id']);

        $this->assertDatabaseMissing('rider_profiles', [
            'shop_owner_id' => $shop->id,
            'name' => 'Unlinked rider',
        ]);
    }

    public function test_company_shop_cannot_update_a_rider_to_shop_owner(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/logistics/riders/{$profile->id}", [
                'rider_type' => 'shop_owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rider_type');

        $this->assertSame('employee', $profile->fresh()->rider_type);
    }

    public function test_rider_profile_update_cannot_link_a_user_from_another_shop(): void
    {
        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $foreignUser = User::factory()->create(['shop_owner_id' => $foreignShop->id]);
        $profile = RiderProfile::factory()->unlinked()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/logistics/riders/{$profile->id}", [
                'linked_type' => User::class,
                'linked_id' => $foreignUser->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linked_id');

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $profile->id,
            'linked_type' => null,
            'linked_id' => null,
        ]);
    }

    public function test_foreign_shop_rider_cannot_accept_a_standalone_offer(): void
    {
        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $foreignUser = User::factory()->create(['shop_owner_id' => $foreignShop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $foreignShop->id,
            'linked_type' => User::class,
            'linked_id' => $foreignUser->id,
        ]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'assigned',
        ]);

        $this->actingAs($foreignUser, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/accept")
            ->assertForbidden();

        $this->assertDatabaseHas('delivery_assignments', [
            'id' => $assignment->id,
            'status' => 'assigned',
        ]);
    }

    public function test_foreign_shop_rider_cannot_accept_a_batch_offer(): void
    {
        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $foreignUser = User::factory()->create(['shop_owner_id' => $foreignShop->id]);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $foreignShop->id,
            'linked_type' => User::class,
            'linked_id' => $foreignUser->id,
        ]);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $profile->id,
            'status' => 'offered',
        ]);

        $this->actingAs($foreignUser, 'user')
            ->postJson("/api/logistics/batches/{$batch->id}/accept")
            ->assertForbidden();

        $this->assertSame('offered', $batch->fresh()->status);
    }

    public function test_batch_service_rejects_a_rider_from_another_shop(): void
    {
        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $foreignShop->id]);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $profile->id,
            'status' => 'offered',
        ]);

        $this->expectException(ValidationException::class);

        app(BatchDispatchService::class)->accept($batch, $profile);
    }
}
