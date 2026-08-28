<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ShopOwnerLogisticsResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_assign_and_schedule_a_same_shop_leg(): void
    {
        $shop = $this->shop();
        $leg = $this->leg($shop, 'pending');
        $rider = $this->rider($shop);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertOk();

        $this->actingAs($shop, 'shop_owner')
            ->postJson('/api/logistics/legs/schedule', [
                'delivery_date' => now()->addDay()->toDateString(),
                'delivery_window' => 'morning',
                'leg_ids' => [$leg->id],
            ])
            ->assertOk();
    }

    public function test_owner_can_review_a_delivery_proof_but_the_submitter_cannot_review_it(): void
    {
        $shop = $this->shop();
        $submitter = $this->user($shop);
        $leg = $this->leg($shop, 'awaiting_proof_approval');
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
            'confirmed_by_type' => User::class,
            'confirmed_by_id' => $submitter->id,
        ]);

        $this->actingAs($submitter, 'user')
            ->postJson("/api/logistics/proofs/{$proof->id}/approve")
            ->assertForbidden();

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/proofs/{$proof->id}/approve")
            ->assertOk();

        self::assertSame('approved', $proof->fresh()->review_status);
    }

    public function test_owner_can_resolve_an_exception_for_their_shop(): void
    {
        $shop = $this->shop();
        $leg = $this->leg($shop, 'needs_resolution');
        $rider = $this->rider($shop);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $incident = DeliveryIncident::factory()->create([
            'shop_owner_id' => $shop->id,
            'shipment_leg_id' => $leg->id,
            'type' => 'vehicle_problem',
            'status' => 'reported',
            'reporting_rider_profile_id' => $rider->id,
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/incidents/{$incident->id}/resolve", [
                'resolution' => 'retry',
                'note' => 'Retry after the vehicle issue was resolved.',
            ])
            ->assertOk();
    }

    public function test_owner_can_confirm_a_return_receipt_after_rider_handoff(): void
    {
        $shop = $this->shop();
        $original = $this->leg($shop, 'needs_resolution');
        $return = ShipmentLeg::factory()->create([
            'shipment_id' => $original->shipment_id,
            'leg_type' => 'return_to_shop',
            'return_for_leg_id' => $original->id,
            'status' => 'picked_up',
        ]);
        $rider = $this->rider($shop);
        $return->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $return->id,
            'handoff_type' => 'receive',
            'review_status' => 'rider_confirmed',
            'reviewed_by_type' => RiderProfile::class,
            'reviewed_by_id' => $rider->id,
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$return->id}/return-proofs/{$proof->id}/receipt")
            ->assertOk();

        self::assertSame('delivered', $return->fresh()->status->value);
        self::assertSame('approved', $proof->fresh()->review_status);
    }

    public function test_owner_guard_alone_cannot_submit_custody_proof(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $leg = $this->leg($shop, 'in_transit');

        $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->proofFile(),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        self::assertDatabaseCount('handoff_proofs', 0);
    }

    public function test_linked_owner_rider_requires_an_active_exact_assignment_for_custody(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $leg = $this->leg($shop, 'assigned');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'shop_owner',
            'linked_type' => ShopOwner::class,
            'linked_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'pickup',
                'proof_type' => 'photo',
                'proof_file' => $this->proofFile(),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'pickup',
                'proof_type' => 'photo',
                'proof_file' => $this->proofFile('owner-pickup.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_cross_shop_and_terminal_source_denials_are_generic_and_side_effect_free(): void
    {
        Log::spy();
        $shop = $this->shop();
        $foreignShop = $this->shop();
        $foreignLeg = $this->leg($foreignShop, 'pending');
        $rider = $this->rider($shop);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$foreignLeg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertForbidden();

        $terminal = $this->leg($shop, 'delivered');
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$terminal->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertForbidden();

        self::assertSame(0, $terminal->assignments()->count());
        Log::shouldHaveReceived('warning')->atLeast()->withArgs(function (string $message, array $context): bool {
            return $message === 'Logistics action denied'
                && $context['domain'] === 'logistics'
                && isset($context['action'], $context['denial_category'], $context['route_name'])
                && ! array_key_exists('email', $context)
                && ! array_key_exists('proof', $context)
                && ! array_key_exists('notes', $context);
        });
    }

    public function test_disabled_logistics_module_denies_owner_mutation(): void
    {
        $shop = $this->shop();
        $shop->modules()->where('module_key', 'logistics')->update(['enabled' => false]);
        $leg = $this->leg($shop, 'pending');
        $rider = $this->rider($shop);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
            ])
            ->assertForbidden();

        self::assertSame(0, $leg->assignments()->count());
    }

    private function shop(): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shop->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'operating_days' => [1, 2, 3, 4, 5, 6, 7],
            'blackout_dates' => [],
            'lead_time_days' => 0,
        ]);

        return $shop;
    }

    /** @param array<int, string> $permissions */
    private function user(ShopOwner $shop, array $permissions = []): User
    {
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
        }

        return $user;
    }

    private function rider(ShopOwner $shop): RiderProfile
    {
        $user = $this->user($shop);

        return RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $user->id,
        ]);
    }

    private function leg(ShopOwner $shop, string $status): ShipmentLeg
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
        ]);

        return ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => $status,
        ]);
    }

    private function proofFile(string $name = 'proof.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
