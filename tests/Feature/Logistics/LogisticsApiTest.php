<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\Logistics\ProofService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LogisticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopOwner::creating(function (ShopOwner $shop): void {
            $shop->forceFill([
                'registration_type' => 'company',
                'business_type' => 'both',
            ]);
        });
        ShopOwner::created(function (ShopOwner $shop): void {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $shop->id,
                'module_key' => 'logistics',
                'enabled' => true,
            ]);
        });
    }

    public function test_staff_without_logistics_permission_cannot_schedule_leg(): void
    {
        $user = User::factory()->create();
        $leg = ShipmentLeg::factory()->create();

        $this->actingAs($user, 'user')->postJson('/api/logistics/legs/schedule', [
            'delivery_date' => now()->addDay()->toDateString(),
            'delivery_window' => 'morning',
            'leg_ids' => [$leg->id],
        ])->assertForbidden();
    }

    public function test_schedule_rejects_past_delivery_date(): void
    {
        $shop = ShopOwner::factory()->create();
        $dispatcher = $this->dispatcher($shop, 'assign-logistics-deliveries');
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
        ]);

        $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/legs/schedule', [
            'delivery_date' => now()->subDay()->toDateString(),
            'delivery_window' => 'morning',
            'leg_ids' => [$leg->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_date');
    }

    public function test_staff_without_logistics_permission_cannot_assign_leg(): void
    {
        $user = User::factory()->create();
        $leg = ShipmentLeg::factory()->create();

        $this->actingAs($user, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [])
            ->assertForbidden();
    }

    public function test_dispatcher_can_assign_leg_to_valid_rider(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);
        $dispatcher = $this->dispatcher($shop, 'assign-logistics-deliveries');

        $this->actingAs($dispatcher, 'user')
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
        $dispatcher = $this->dispatcher($shop, 'assign-logistics-deliveries');

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $first->id,
            ])
            ->assertOk();

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $second->id,
            ])
            ->assertForbidden();
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

    public function test_shop_owner_cannot_upload_delivery_proof_without_rider_custody(): void
    {
        Storage::fake('local');

        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);

        $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->fakeAttemptPhoto('proof.png'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame(0, $leg->proofs()->count());
    }

    public function test_proof_cannot_be_changed_after_delivery(): void
    {
        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivered']);

        $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->fakeAttemptPhoto('proof.png'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_assigned_rider_submits_proof_and_only_proof_approver_can_complete_delivery(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        Permission::findOrCreate('update-logistics-status', 'user');
        Permission::findOrCreate('approve-proof-of-delivery', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'shipped', 'carrier_company' => 'Shop-owned logistics']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active', 'source_type' => 'order', 'source_id' => $order->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $approver = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo(['record-logistics-proof', 'update-logistics-status']);
        $approver->givePermissionTo('approve-proof-of-delivery');
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $rider->id]);
        $assignment = $leg->assignments()->create(['assignment_type' => 'internal_rider', 'rider_profile_id' => $profile->id, 'status' => 'assigned', 'assigned_at' => now()]);
        $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'dropoff_arrived',
            'visibility' => 'internal',
            'message' => 'Rider arrived at the customer location.',
            'metadata' => ['delivery_assignment_id' => $assignment->id],
        ]);
        Storage::fake('local');
        $proof = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'proof_file' => $this->fakeAttemptPhoto('proof.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('proof');
        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->actingAs(User::findOrFail($order->customer_id), 'user')
            ->postJson('/orders/confirm-delivery', ['order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('order_status', 'shipped')
            ->assertJsonPath('receipt_status', 'confirmed');
        $this->assertSame('shipped', $order->fresh()->status->value);
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/delivered")->assertUnprocessable();
        $this->actingAs($approver, 'user')->postJson("/api/logistics/proofs/{$proof['id']}/approve")->assertOk();
        $this->assertSame('delivered', $leg->fresh()->status->value);
        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertSame('confirmed', $order->fresh()->customer_receipt_status);
        $this->assertDatabaseHas('handoff_proofs', ['id' => $proof['id'], 'review_status' => 'approved']);
    }

    public function test_rider_delivery_proof_replays_by_idempotency_key_without_storing_a_duplicate(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $assignment = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);
        $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'dropoff_arrived',
            'visibility' => 'internal',
            'message' => 'Rider arrived at the customer location.',
            'metadata' => ['delivery_assignment_id' => $assignment->id],
        ]);
        Storage::fake('local');
        $payload = [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'idempotency_key' => '99999999-9999-4999-8999-999999999999',
        ];

        $first = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            ...$payload,
            'proof_file' => $this->fakeAttemptPhoto('first-proof.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $replayed = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            ...$payload,
            'proof_file' => $this->fakeAttemptPhoto('replayed-proof.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($first->json('proof.id'), $replayed->json('proof.id'));
        $first->assertJsonMissingPath('proof.file_path');
        $replayed->assertJsonMissingPath('proof.file_path');
        $this->assertSame(1, $leg->proofs()->count());
        Storage::disk('local')->assertExists((string) $leg->proofs()->value('file_path'));
        $this->assertCount(1, Storage::disk('local')->allFiles("logistics-proof/{$leg->id}"));
    }

    public function test_unassigned_same_shop_rider_cannot_submit_delivery_proof(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        Storage::fake('local');

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'proof_file' => $this->fakeAttemptPhoto('unassigned-proof.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame('in_transit', $leg->fresh()->status->value);
        $this->assertSame(0, $leg->proofs()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unassigned_rider_cannot_replay_another_riders_proof_key(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'awaiting_proof_approval',
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'idempotency_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        Storage::fake('local');

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'idempotency_key' => $proof->idempotency_key,
            'proof_file' => $this->fakeAttemptPhoto('replayed-proof.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(1, $leg->proofs()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_assigned_rider_cannot_submit_delivery_proof_without_current_arrival(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);
        Storage::fake('local');

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'proof_file' => $this->fakeAttemptPhoto('no-arrival-proof.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('arrival');

        $this->assertSame('in_transit', $leg->fresh()->status->value);
        $this->assertSame(0, $leg->proofs()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_back_office_proof_capability_does_not_bypass_custody_arrival(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');

        foreach (['assign-logistics-deliveries', 'approve-proof-of-delivery'] as $capability) {
            Permission::findOrCreate($capability, 'user');
            $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
            $leg = ShipmentLeg::factory()->create([
                'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
                'status' => 'in_transit',
            ]);
            $user = User::factory()->create(['shop_owner_id' => $shop->id]);
            $user->givePermissionTo(['record-logistics-proof', $capability]);
            $profile = RiderProfile::factory()->create([
                'shop_owner_id' => $shop->id,
                'linked_type' => User::class,
                'linked_id' => $user->id,
            ]);
            $leg->assignments()->create([
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $profile->id,
                'status' => 'accepted',
                'assigned_at' => now(),
            ]);
            Storage::fake('local');

            $this->actingAs($user, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->fakeAttemptPhoto("{$capability}.png"),
            ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('arrival');
        }
    }

    public function test_pickup_rejection_cannot_mutate_a_proof_from_another_leg(): void
    {
        [$shop, $leg, $rider] = $this->assignedRiderLeg('assigned');
        $otherLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'assigned',
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $otherLeg->id,
            'handoff_type' => 'pickup',
            'review_status' => 'pending',
        ]);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/pickup-proofs/{$proof->id}/reject", [
                'reason' => 'Wrong parcel',
            ])
            ->assertUnprocessable();

        $this->assertSame('pending', $proof->fresh()->review_status);
    }

    public function test_pickup_rejection_cannot_mutate_non_pickup_or_reviewed_proof(): void
    {
        [, $leg, $rider] = $this->assignedRiderLeg('assigned');
        $deliveryProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
        ]);
        $approvedPickup = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'pickup',
            'review_status' => 'approved',
        ]);

        foreach ([$deliveryProof, $approvedPickup] as $proof) {
            $this->actingAs($rider, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/pickup-proofs/{$proof->id}/reject", [
                    'reason' => 'Wrong parcel',
                ])
                ->assertUnprocessable();
        }

        $this->assertSame('pending', $deliveryProof->fresh()->review_status);
        $this->assertSame('approved', $approvedPickup->fresh()->review_status);
    }

    public function test_rejected_pickup_proof_cannot_be_confirmed(): void
    {
        [, $leg, $rider] = $this->assignedRiderLeg('assigned');
        $leg->assignments()->update(['status' => 'accepted', 'accepted_at' => now()]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'pickup',
            'review_status' => 'rejected',
        ]);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/pickup-proofs/{$proof->id}/confirm")
            ->assertUnprocessable();

        $this->assertSame('rejected', $proof->fresh()->review_status);
        $this->assertSame('assigned', $leg->fresh()->status->value);
    }

    public function test_proof_service_rechecks_that_the_rider_assignment_is_active(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
        ]);
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'cancelled',
        ]);

        try {
            app(ProofService::class)->recordProof($leg, [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'file_path' => 'proof.jpg',
            ], $profile);
            $this->fail('A rider without an active assignment recorded proof.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $this->assertSame(0, $leg->proofs()->count());
        $this->assertSame('in_transit', $leg->fresh()->status->value);
    }

    public function test_rider_cannot_submit_proof_for_a_noncanonical_legacy_active_delivery(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $currentLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => null,
            'status' => 'in_transit',
            'out_for_delivery_at' => now()->subMinutes(10),
        ]);
        $blockedLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => null,
            'status' => 'in_transit',
            'out_for_delivery_at' => now(),
        ]);
        foreach ([$currentLeg, $blockedLeg] as $leg) {
            DeliveryAssignment::factory()->create([
                'shipment_leg_id' => $leg->id,
                'rider_profile_id' => $profile->id,
                'status' => 'accepted',
            ]);
        }
        Storage::fake('local');

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$blockedLeg->id}/proof", [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'proof_file' => $this->fakeAttemptPhoto('blocked-proof.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('active_work');

        $this->assertSame(0, $blockedLeg->proofs()->count());
        Storage::disk('local')->assertMissing("logistics-proof/{$blockedLeg->id}");
    }

    public function test_dispatcher_can_fetch_private_proof_but_other_actors_cannot(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        Permission::findOrCreate('record-logistics-proof', 'user');

        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'file_path' => "logistics-proof/{$leg->id}/proof.png",
        ]);
        Storage::disk('local')->put($proof->file_path, 'raw-proof-bytes');

        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo('assign-logistics-deliveries');
        $this->actingAs($dispatcher, 'user')
            ->get("/api/logistics/proofs/{$proof->id}/file")
            ->assertOk()
            ->assertStreamedContent('raw-proof-bytes');

        $otherDispatcher = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $otherDispatcher->givePermissionTo('assign-logistics-deliveries');
        $this->actingAs($otherDispatcher, 'user')
            ->get("/api/logistics/proofs/{$proof->id}/file")
            ->assertForbidden();

        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        $this->actingAs($rider, 'user')
            ->get("/api/logistics/proofs/{$proof->id}/file")
            ->assertForbidden();

        $this->actingAs(User::factory()->create(), 'user')
            ->get("/api/logistics/proofs/{$proof->id}/file")
            ->assertForbidden();
    }

    public function test_same_shop_job_order_staff_can_fetch_refund_return_and_retail_delivery_proofs_only(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('access-staff-job-orders', 'user');

        $shop = ShopOwner::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo('access-staff-job-orders');
        $refund = OrderRefund::factory()->create(['shop_owner_id' => $shop->id]);
        $returnShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);
        $returnLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $returnShipment->id,
            'leg_type' => 'inbound',
        ]);
        $returnProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $returnLeg->id,
            'file_path' => "logistics-proof/{$returnLeg->id}/refund-return.png",
        ]);
        Storage::disk('local')->put($returnProof->file_path, 'refund-return-proof');

        $this->actingAs($staff, 'user')
            ->get("/api/logistics/proofs/{$returnProof->id}/file")
            ->assertOk()
            ->assertStreamedContent('refund-return-proof');

        $otherShopStaff = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $otherShopStaff->givePermissionTo('access-staff-job-orders');
        $this->actingAs($otherShopStaff, 'user')
            ->get("/api/logistics/proofs/{$returnProof->id}/file")
            ->assertForbidden();

        $deliveryShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
        ]);
        $deliveryLeg = ShipmentLeg::factory()->create(['shipment_id' => $deliveryShipment->id]);
        $deliveryProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $deliveryLeg->id,
            'file_path' => "logistics-proof/{$deliveryLeg->id}/delivery.png",
        ]);
        Storage::disk('local')->put($deliveryProof->file_path, 'delivery-proof');

        $this->actingAs($staff, 'user')
            ->get("/api/logistics/proofs/{$deliveryProof->id}/file")
            ->assertOk()
            ->assertStreamedContent('delivery-proof');

        $repairShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'purpose' => 'repair_pickup',
        ]);
        $repairLeg = ShipmentLeg::factory()->create(['shipment_id' => $repairShipment->id]);
        $repairProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $repairLeg->id,
            'file_path' => "logistics-proof/{$repairLeg->id}/repair.png",
        ]);
        Storage::disk('local')->put($repairProof->file_path, 'repair-proof');

        $this->actingAs($staff, 'user')
            ->get("/api/logistics/proofs/{$repairProof->id}/file")
            ->assertForbidden();
    }

    public function test_proof_approver_can_reject_delivery_proof_for_rider_resubmission(): void
    {
        Permission::findOrCreate('approve-proof-of-delivery', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'awaiting_proof_approval',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $approver = User::factory()->create(['shop_owner_id' => $shop->id]);
        $approver->givePermissionTo('approve-proof-of-delivery');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
            'confirmed_by_type' => User::class,
            'confirmed_by_id' => $rider->id,
        ]);

        $this->actingAs($approver, 'user')
            ->postJson("/api/logistics/proofs/{$proof->id}/reject", [
                'rejection_reason' => 'Photo does not show the recipient.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('handoff_proofs', [
            'id' => $proof->id,
            'review_status' => 'rejected',
            'rejection_reason' => 'Photo does not show the recipient.',
        ]);
        $this->assertSame('proof_correction_required', $leg->fresh()->status->value);
        $this->assertSame(RiderProgressState::PROOF_ACTION_REQUIRED, $leg->fresh()->rider_progress_state);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'proof_rejected',
        ]);
        $this->assertTrue(Notification::query()->where('user_id', $rider->id)
            ->where('data->event_type', 'proof_rejected')->exists());
    }

    public function test_non_delivery_evidence_cannot_complete_delivery(): void
    {
        Permission::findOrCreate('approve-proof-of-delivery', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'awaiting_proof_approval',
            'requires_delivery_proof' => true,
        ]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'approved',
        ]);
        $pickupProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'pickup',
            'review_status' => 'pending',
        ]);
        $approver = User::factory()->create(['shop_owner_id' => $shop->id]);
        $approver->givePermissionTo('approve-proof-of-delivery');

        $this->actingAs($approver, 'user')
            ->postJson("/api/logistics/proofs/{$pickupProof->id}/approve")
            ->assertForbidden();

        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->assertSame('pending', $pickupProof->fresh()->review_status);
    }

    public function test_assigned_rider_can_report_a_delivery_issue_with_a_customer_safe_event(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->givePermissionTo('update-logistics-status');

        $response = $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                'delivery_assignment_id' => $leg->assignments()->value('id'),
                'reason_code' => 'recipient_unavailable',
                'notes' => 'Gate code was unavailable.',
                'proof_file' => $this->fakeAttemptPhoto(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('attempt.attempt_type', 'delivery')
            ->assertJsonPath('attempt.reason_code', 'recipient_unavailable');
        Storage::disk('local')->assertExists((string) $leg->attempts()->value('file_path'));

        $this->assertDatabaseHas('delivery_attempts', [
            'shipment_leg_id' => $leg->id,
            'delivery_assignment_id' => $leg->assignments()->value('id'),
            'attempt_number' => 1,
            'recorded_by_type' => User::class,
            'recorded_by_id' => $rider->id,
        ]);
        $this->assertSame('cancelled', $leg->assignments()->firstOrFail()->fresh()->status);
        $event = DeliveryEvent::query()->where('shipment_leg_id', $leg->id)->where('visibility', 'customer')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('Gate code', $event->message);
        $this->assertArrayNotHasKey('notes', $event->metadata);

        $replay = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/report-issue", [
            'delivery_assignment_id' => $leg->assignments()->value('id'),
            'reason_code' => 'other',
            'notes' => 'Replay note',
            'proof_file' => $this->fakeAttemptPhoto('replay.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame($response->json('attempt.id'), $replay->json('attempt.id'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_failed_attempt_photo_is_stored_privately_and_not_returned_as_a_public_path(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->givePermissionTo('update-logistics-status');

        $response = $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                'delivery_assignment_id' => $leg->assignments()->value('id'),
                'reason_code' => 'recipient_unavailable',
                'proof_file' => $this->fakeAttemptPhoto(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('attempt.file_path', null);

        $path = (string) $leg->attempts()->value('file_path');
        $this->assertStringStartsWith("logistics-attempt/{$leg->id}/", $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertNotSame('', (string) $response->json('attempt.id'));
    }

    public function test_same_shop_logistics_staff_can_fetch_private_attempt_evidence_but_other_shop_staff_cannot(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherStaff = User::factory()->create(['shop_owner_id' => $otherShop->id]);
        $exceptionStaff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $path = "logistics-attempt/{$leg->id}/attempt.jpg";
        $attempt = $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'recipient_unavailable',
            'file_path' => $path,
            'attempted_at' => now(),
        ]);
        Storage::disk('local')->put($path, 'private-attempt-photo');
        $url = "/api/logistics/attempts/{$attempt->id}/file";

        $staff->givePermissionTo('assign-logistics-deliveries');
        $otherStaff->givePermissionTo('assign-logistics-deliveries');
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        $exceptionStaff->givePermissionTo('resolve-logistics-exceptions');

        $this->actingAs($staff, 'user')
            ->get($url)
            ->assertOk()
            ->assertStreamedContent('private-attempt-photo');
        $this->actingAs($otherStaff, 'user')->get($url)->assertForbidden();
        $this->actingAs($exceptionStaff, 'user')
            ->get($url)
            ->assertOk()
            ->assertStreamedContent('private-attempt-photo');
    }

    public function test_assigned_rider_can_report_a_failed_repair_pickup_once(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRepairPickupLeg();
        $rider->givePermissionTo('update-logistics-status');
        $payload = [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $leg->assignments()->value('id'),
            'idempotency_key' => '66270d9f-a25b-4130-8494-9e757d92c798',
            'reason_code' => 'customer_unavailable',
            'proof_file' => $this->fakeAttemptPhoto(),
        ];

        $first = $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", $payload, [
                'Accept' => 'application/json',
            ])
            ->assertCreated();
        $replay = $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                ...$payload,
                'proof_file' => $this->fakeAttemptPhoto('duplicate.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame($first->json('attempt.id'), $replay->json('attempt.id'));
        $this->assertSame(1, $leg->attempts()->where('attempt_type', 'pickup')->count());
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_reassigned_repair_and_warranty_pickups_require_a_fresh_arrival_and_increment_attempts(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');

        foreach ([false, true] as $isWarranty) {
            [$shop, $leg, $rider] = $this->assignedRepairPickupLeg();
            $repair = RepairRequest::factory()->create([
                'shop_owner_id' => $shop->id,
                'is_warranty_job' => $isWarranty,
            ]);
            $leg->shipment->update(['source_id' => $repair->id]);
            $rider->givePermissionTo('update-logistics-status');

            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$this->failedPickupPayload($leg),
                    'idempotency_key' => $isWarranty
                        ? '11111111-1111-4111-8111-111111111111'
                        : '22222222-2222-4222-8222-222222222222',
                    'proof_file' => $this->fakeAttemptPhoto($isWarranty ? 'warranty-first.png' : 'repair-first.png'),
                ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('attempt.attempt_number', 1);

            app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Customer requested another pickup.');
            $riderProfileId = $leg->assignments()->latest('id')->value('rider_profile_id');
            $secondAssignment = $leg->assignments()->create([
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $riderProfileId,
                'status' => 'accepted',
                'assigned_at' => now(),
                'accepted_at' => now(),
            ]);
            $leg->fresh()->update(['status' => 'assigned']);
            $secondPayload = [
                'attempt_type' => 'pickup',
                'delivery_assignment_id' => $secondAssignment->id,
                'idempotency_key' => $isWarranty
                    ? '33333333-3333-4333-8333-333333333333'
                    : '44444444-4444-4444-8444-444444444444',
                'reason_code' => 'customer_unavailable',
            ];

            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$secondPayload,
                    'proof_file' => $this->fakeAttemptPhoto($isWarranty ? 'warranty-no-arrival.png' : 'repair-no-arrival.png'),
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('arrival');

            $leg->events()->create([
                'shipment_id' => $leg->shipment_id,
                'event_type' => 'pickup_arrived',
                'visibility' => 'internal',
                'metadata' => ['delivery_assignment_id' => $secondAssignment->id, 'result' => 'verified'],
            ]);

            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$secondPayload,
                    'proof_file' => $this->fakeAttemptPhoto($isWarranty ? 'warranty-second.png' : 'repair-second.png'),
                ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('attempt.attempt_number', 2);

            $this->assertSame(2, $leg->attempts()->where('attempt_type', 'pickup')->count());
            $this->assertSame(0, $leg->attempts()->where('attempt_type', 'delivery')->count());
        }
    }

    public function test_final_repair_pickup_attempt_is_terminal_and_blocks_stale_actions(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg, $rider] = $this->assignedRepairPickupLeg();
        LogisticsSetting::updateOrCreate(
            ['shop_owner_id' => $shop->id],
            ['max_delivery_attempts' => 2],
        );
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending',
        ]);
        $leg->shipment->update(['source_id' => $repair->id]);
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                ...$this->failedPickupPayload($leg),
                'idempotency_key' => '55555555-5555-4555-8555-555555555555',
                'proof_file' => $this->fakeAttemptPhoto('terminal-first.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('attempt.attempt_number', 1);

        app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Customer requested another pickup.');
        $riderProfileId = $leg->assignments()->latest('id')->value('rider_profile_id');
        $secondAssignment = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $riderProfileId,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $leg->fresh()->update(['status' => 'assigned']);
        $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'metadata' => ['delivery_assignment_id' => $secondAssignment->id, 'result' => 'verified'],
        ]);

        $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                'attempt_type' => 'pickup',
                'delivery_assignment_id' => $secondAssignment->id,
                'idempotency_key' => '66666666-6666-4666-8666-666666666666',
                'reason_code' => 'customer_unavailable',
                'proof_file' => $this->fakeAttemptPhoto('terminal-second.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('attempt.attempt_number', 2);

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('pickup_attempts_exhausted', $leg->fresh()->resolution_type);
        $this->assertSame('cancelled', $leg->shipment->fresh()->status->value);
        $this->assertSame('cancelled', (string) $repair->fresh()->status);
        $this->assertSame(2, $leg->attempts()->where('attempt_type', 'pickup')->count());
        $this->assertSame(0, $leg->attempts()->where('attempt_type', 'delivery')->count());
        $this->assertFalse(ShipmentLeg::query()->where('return_for_leg_id', $leg->id)->exists());

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/resolve/retry", ['reason' => 'Retry stale page.'])
            ->assertForbidden();
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", [
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $riderProfileId,
            ])
            ->assertForbidden();
    }

    public function test_final_paid_repair_pickup_refund_obeys_failure_responsibility(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        $cases = [
            'customer_unavailable' => ['partial', 400.00, 'pickup fee of PHP 100.00 was retained'],
            'vehicle_or_rider_problem' => ['full', 500.00, 'includes the paid pickup fee of PHP 100.00'],
            'other' => ['full', 500.00, 'Finance must decide whether the paid pickup fee of PHP 100.00 is refundable'],
        ];

        foreach ($cases as $reason => [$requestType, $requestedAmount, $noteFragment]) {
            [$shop, $leg, $rider] = $this->assignedRepairPickupLeg();
            LogisticsSetting::updateOrCreate(
                ['shop_owner_id' => $shop->id],
                ['max_delivery_attempts' => 1],
            );
            $customer = User::factory()->create();
            $repair = RepairRequest::factory()->create([
                'shop_owner_id' => $shop->id,
                'user_id' => $customer->id,
                'status' => 'pending',
                'payment_policy' => 'deposit_50',
                'payment_status' => 'paid',
                'total' => 1000,
                'final_total' => 1000,
                'total_paid_amount' => 500,
                'intake_delivery_method' => 'shop_pickup',
                'intake_delivery_fee' => 100,
                'intake_logistics_locked_at' => now(),
                'is_warranty_job' => false,
            ]);
            $earlierSource = PosTransaction::create([
                'transaction_no' => "POS-PICKUP-FIRST-{$repair->id}",
                'shop_owner_id' => $shop->id,
                'module_type' => 'repair',
                'module_reference_id' => $repair->id,
                'customer_type' => 'registered',
                'customer_id' => $customer->id,
                'due_type' => 'deposit',
                'subtotal' => 300,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 300,
                'paid_amount' => 300,
                'status' => 'paid',
                'paid_at' => now()->subMinute(),
                'metadata' => ['phase' => 'initial', 'leg' => 'intake', 'service_amount' => 300, 'delivery_amount' => 0],
            ]);
            $latestSource = PosTransaction::create([
                'transaction_no' => "POS-PICKUP-LATEST-{$repair->id}",
                'shop_owner_id' => $shop->id,
                'module_type' => 'repair',
                'module_reference_id' => $repair->id,
                'customer_type' => 'registered',
                'customer_id' => $customer->id,
                'due_type' => 'deposit',
                'subtotal' => 200,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 200,
                'paid_amount' => 200,
                'status' => 'paid',
                'paid_at' => now(),
                'metadata' => ['phase' => 'initial', 'leg' => 'intake', 'service_amount' => 100, 'delivery_amount' => 100],
            ]);
            $this->assertNotSame($earlierSource->id, $latestSource->id);
            $repair->update(['latest_pos_transaction_id' => $latestSource->id]);
            $leg->shipment->update(['source_id' => $repair->id]);
            $rider->givePermissionTo('update-logistics-status');
            $payload = [
                ...$this->failedPickupPayload($leg),
                'idempotency_key' => match ($reason) {
                    'customer_unavailable' => '77777777-7777-4777-8777-777777777777',
                    'vehicle_or_rider_problem' => '88888888-8888-4888-8888-888888888888',
                    default => '99999999-9999-4999-8999-999999999999',
                },
                'reason_code' => $reason,
                'notes' => $reason === 'other' ? 'Cause requires Finance review.' : null,
                'proof_file' => $this->fakeAttemptPhoto("paid-terminal-{$reason}.png"),
            ];

            $first = $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", $payload, [
                    'Accept' => 'application/json',
                ])
                ->assertCreated();
            $replay = $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$payload,
                    'proof_file' => $this->fakeAttemptPhoto("paid-terminal-replay-{$reason}.png"),
                ], ['Accept' => 'application/json'])
                ->assertCreated();

            $this->assertSame($first->json('attempt.id'), $replay->json('attempt.id'));
            $refund = PosRefund::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('reason_code', 'pickup_attempts_exhausted')
                ->sole();
            $this->assertSame($latestSource->id, $refund->source_transaction_id);
            $this->assertSame('requested', $refund->status);
            $this->assertSame($requestType, $refund->request_type);
            $this->assertSame($requestedAmount, (float) $refund->requested_amount);
            $this->assertStringContainsString($noteFragment, (string) $refund->reason_notes);
        }
    }

    public function test_final_online_repair_pickup_backfills_a_refund_source_and_retains_customer_caused_pickup_fee(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg, $rider] = $this->assignedRepairPickupLeg();
        LogisticsSetting::updateOrCreate(
            ['shop_owner_id' => $shop->id],
            ['max_delivery_attempts' => 1],
        );
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_status' => 'paid',
            'total' => 1000,
            'final_total' => 1000,
            'total_paid_amount' => 500,
            'intake_delivery_method' => 'shop_pickup',
            'intake_delivery_fee' => 100,
            'intake_logistics_locked_at' => now(),
            'paymongo_payment_id' => 'pay_pickup_refund_online',
            'paymongo_payment_ids' => ['pay_pickup_refund_online'],
            'latest_pos_transaction_id' => null,
            'is_warranty_job' => false,
        ]);
        RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => "link-pickup-refund-{$repair->id}",
            'phase' => 'initial',
            'status' => 'paid',
            'delivery_method' => 'shop_pickup',
            'service_amount' => 400,
            'delivery_amount' => 100,
        ]);
        $leg->shipment->update(['source_id' => $repair->id]);
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                ...$this->failedPickupPayload($leg),
                'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'proof_file' => $this->fakeAttemptPhoto('online-paid-terminal.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $source = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->sole();
        $this->assertSame($source->id, $repair->fresh()->latest_pos_transaction_id);
        $this->assertDatabaseHas('pos_payment_lines', [
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pay_pickup_refund_online',
            'amount' => 500,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('pos_refunds', [
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'status' => 'requested',
            'request_type' => 'partial',
            'requested_amount' => 400,
            'reason_code' => 'pickup_attempts_exhausted',
        ]);
    }

    public function test_final_warranty_pickup_cancels_without_refund(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg, $rider] = $this->assignedRepairPickupLeg();
        LogisticsSetting::updateOrCreate(
            ['shop_owner_id' => $shop->id],
            ['max_delivery_attempts' => 1],
        );
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending',
            'is_warranty_job' => true,
            'billing_mode' => 'warranty_no_charge',
            'total_paid_amount' => 500,
        ]);
        $source = PosTransaction::create([
            'transaction_no' => "POS-WARRANTY-PICKUP-{$repair->id}",
            'shop_owner_id' => $shop->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $repair->user_id,
            'due_type' => 'deposit',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $repair->update(['latest_pos_transaction_id' => $source->id]);
        $leg->shipment->update(['source_id' => $repair->id]);
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                ...$this->failedPickupPayload($leg),
                'idempotency_key' => '88888888-8888-4888-8888-888888888888',
                'proof_file' => $this->fakeAttemptPhoto('warranty-terminal.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $leg->shipment->fresh()->status->value);
        $repair->refresh();
        $this->assertSame('cancelled', (string) $repair->status);
        $recovery = collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->firstWhere('type', 'pickup_recovery');
        $this->assertSame('awaiting_arrangement', data_get($recovery, 'status'));
        $this->assertSame($leg->shipment_id, data_get($recovery, 'shipment_id'));
        $this->assertSame($leg->id, data_get($recovery, 'failed_leg_id'));
        $this->assertSame(1, $leg->attempts()->where('attempt_type', 'pickup')->count());
        $proofPath = (string) $leg->attempts()->value('file_path');
        $this->assertNotSame('', $proofPath);
        Storage::disk('local')->assertExists($proofPath);
        $this->assertFalse(PosRefund::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->exists());
    }

    public function test_failed_repair_pickup_requires_arrival_photo_idempotency_and_valid_context(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');

        foreach ([
            'arrival' => [$this->assignedRepairPickupLeg(arrived: false), 'arrival'],
            'picked_up' => [$this->assignedRepairPickupLeg(status: 'picked_up'), 'status'],
        ] as [$fixture, $error]) {
            [, $leg, $rider] = $fixture;
            $rider->givePermissionTo('update-logistics-status');
            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$this->failedPickupPayload($leg),
                    'proof_file' => $this->fakeAttemptPhoto("{$error}.png"),
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($error);
        }

        [, $nonRepairLeg, $nonRepairRider] = $this->assignedRiderLeg('assigned');
        $nonRepairRider->givePermissionTo('update-logistics-status');
        $nonRepairLeg->events()->create([
            'shipment_id' => $nonRepairLeg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
        ]);
        $this->actingAs($nonRepairRider, 'user')
            ->post("/api/logistics/legs/{$nonRepairLeg->id}/report-issue", [
                ...$this->failedPickupPayload($nonRepairLeg),
                'proof_file' => $this->fakeAttemptPhoto('non-repair.png'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attempt_type');

        foreach ([
            [['proof_file' => null], 'proof_file'],
            [['idempotency_key' => null, 'proof_file' => $this->fakeAttemptPhoto('no-key.png')], 'idempotency_key'],
            [['reason_code' => 'unsupported', 'proof_file' => $this->fakeAttemptPhoto('unknown.png')], 'reason_code'],
            [['reason_code' => 'other', 'proof_file' => $this->fakeAttemptPhoto('other.png')], 'notes'],
        ] as [$overrides, $error]) {
            [, $leg, $rider] = $this->assignedRepairPickupLeg();
            $rider->givePermissionTo('update-logistics-status');
            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$this->failedPickupPayload($leg),
                    ...$overrides,
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($error);
        }
    }

    public function test_failed_repair_pickup_accepts_each_approved_reason(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        $reasons = [
            'customer_unavailable',
            'customer_requested_reschedule',
            'customer_refused_pickup',
            'item_not_ready',
            'wrong_address_or_pin',
            'unsafe_or_inaccessible_location',
            'vehicle_or_rider_problem',
            'other',
        ];

        foreach ($reasons as $index => $reason) {
            [, $leg, $rider] = $this->assignedRepairPickupLeg();
            $rider->givePermissionTo('update-logistics-status');
            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    ...$this->failedPickupPayload($leg),
                    'idempotency_key' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                    'reason_code' => $reason,
                    'notes' => $reason === 'other' ? 'The rider added context.' : null,
                    'proof_file' => $this->fakeAttemptPhoto("{$reason}.png"),
                ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('attempt.reason_code', $reason);
        }
    }

    public function test_other_rider_cannot_report_a_failed_repair_pickup(): void
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg] = $this->assignedRepairPickupLeg();
        $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherRider->givePermissionTo('update-logistics-status');

        $this->actingAs($otherRider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/report-issue", $this->failedPickupPayload($leg))
            ->assertForbidden();
    }

    public function test_report_issue_rejects_missing_or_unlisted_reason_codes(): void
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/report-issue", [])->assertUnprocessable();
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/report-issue", ['reason_code' => 'unsupported'])->assertUnprocessable();
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/report-issue", ['reason_code' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notes');
    }

    public function test_report_issue_enforces_reason_specific_photo_and_note_evidence(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        $matrix = [
            'recipient_unavailable' => 'proof_file',
            'wrong_or_incomplete_address' => 'proof_file',
            'recipient_refused' => 'proof_file',
            'item_damaged' => 'proof_file',
            'unsafe_location' => 'notes',
            'vehicle_or_delivery_problem' => 'notes',
            'other' => 'notes',
        ];

        foreach ($matrix as $reason => $requiredField) {
            [, $leg, $rider] = $this->assignedRiderLeg('in_transit');
            $rider->givePermissionTo('update-logistics-status');
            $base = [
                'delivery_assignment_id' => $leg->assignments()->value('id'),
                'reason_code' => $reason,
            ];

            $this->actingAs($rider, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/report-issue", $base)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($requiredField);

            $valid = $requiredField === 'proof_file'
                ? [...$base, 'proof_file' => $this->fakeAttemptPhoto("{$reason}.png")]
                : [...$base, 'notes' => 'Short safety or delivery note.'];
            $response = $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", $valid, ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('attempt.reason_code', $reason);

            if ($requiredField === 'notes') {
                $response->assertJsonPath('attempt.file_path', null);
            }
        }
    }

    public function test_other_rider_cannot_report_an_issue(): void
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg] = $this->assignedRiderLeg('picked_up');
        $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherRider->givePermissionTo('update-logistics-status');

        $this->actingAs($otherRider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/report-issue", [
                'delivery_assignment_id' => $leg->assignments()->value('id'),
                'reason_code' => 'other',
            ])
            ->assertForbidden();
    }

    public function test_rider_cannot_report_an_issue_for_another_shop(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->update(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/report-issue", [
            'reason_code' => 'other',
            'proof_file' => $this->fakeAttemptPhoto(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_shop_owner_cannot_report_an_issue(): void
    {
        [$shop, $leg] = $this->assignedRiderLeg('picked_up');

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/report-issue", ['reason_code' => 'other'])
            ->assertForbidden();
    }

    public function test_dispatcher_cannot_report_an_issue(): void
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        [$shop, $leg] = $this->assignedRiderLeg('picked_up');
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(['update-logistics-status', 'assign-logistics-deliveries']);

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/report-issue", [
                'delivery_assignment_id' => $leg->assignments()->value('id'),
                'reason_code' => 'other',
            ])
            ->assertForbidden();
    }

    public function test_rider_report_issue_rejects_pre_delivery_statuses_before_upload(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');

        foreach (['assigned', 'picked_up'] as $status) {
            [, $leg, $rider] = $this->assignedRiderLeg($status);
            $rider->givePermissionTo('update-logistics-status');

            $this->actingAs($rider, 'user')
                ->post("/api/logistics/legs/{$leg->id}/report-issue", [
                    'reason_code' => 'other',
                    'notes' => 'Delivery is not ready for an attempt.',
                    'proof_file' => $this->fakeAttemptPhoto("attempt-{$status}.png"),
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable();

            $this->assertSame(0, $leg->attempts()->count());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rider_can_report_issues_from_each_delivery_status(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');

        foreach (['in_transit', 'delivery_attempted'] as $status) {
            [, $leg, $rider] = $this->assignedRiderLeg($status);
            $rider->givePermissionTo('update-logistics-status');

            $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/report-issue", [
                'delivery_assignment_id' => $leg->assignments()->value('id'),
                'reason_code' => 'other',
                'notes' => 'Delivery could not be completed.',
            ], ['Accept' => 'application/json'])->assertCreated();
        }
    }

    public function test_report_issue_deletes_uploaded_photo_when_locked_status_check_rejects_a_race(): void
    {
        Storage::fake('local');
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->givePermissionTo('update-logistics-status');
        $realService = app(ShipmentLegService::class);
        $this->mock(ShipmentLegService::class, function ($mock) use ($leg, $realService) {
            $mock->shouldReceive('recordFailedAttempt')->once()->andReturnUsing(
                function ($passedLeg, $payload, $allowAssigned = false) use ($leg, $realService) {
                    $leg->update(['status' => 'picked_up']);

                    return $realService->recordFailedAttempt($passedLeg, $payload, $allowAssigned);
                }
            );
        });

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/report-issue", [
            'delivery_assignment_id' => $leg->assignments()->value('id'),
            'reason_code' => 'recipient_unavailable',
            'proof_file' => $this->fakeAttemptPhoto(),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(0, $leg->attempts()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_generic_attempts_endpoint_still_rejects_assigned_legs(): void
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRiderLeg('assigned');
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", ['reason_code' => 'generic_reason'])
            ->assertUnprocessable();
    }

    public function test_generic_attempt_replays_by_idempotency_key_and_rejects_shop_owner_guard(): void
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        [$shop, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->givePermissionTo('update-logistics-status');
        $payload = [
            'delivery_assignment_id' => $leg->assignments()->value('id'),
            'idempotency_key' => '66270d9f-a25b-4130-8494-9e757d92c798',
            'reason_code' => 'generic_reason',
        ];

        $first = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", $payload)
            ->assertCreated();
        $second = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", [...$payload, 'reason_code' => 'updated_generic_reason'])
            ->assertCreated();

        $this->assertSame($first->json('attempt.id'), $second->json('attempt.id'));
        $this->assertSame(1, $leg->attempts()->count());
        Auth::guard('user')->logout();
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", $payload)
            ->assertForbidden();
    }

    public function test_only_logistics_dispatchers_can_cancel_after_a_failed_attempt(): void
    {
        [$shop, $leg, $rider] = $this->assignedRiderLeg('delivery_attempted');
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'recipient_unavailable', 'notes' => 'Private rider note', 'attempted_at' => now()]);
        $dispatcher = $this->dispatcher($shop, 'resolve-logistics-exceptions');

        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")->assertForbidden();
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Recipient was unavailable');

        $event = DeliveryEvent::query()->where('shipment_leg_id', $leg->id)->where('visibility', 'customer')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('Private rider note', $event->message);
        $this->assertArrayNotHasKey('notes', $event->metadata);
    }

    public function test_cancel_requires_a_failed_delivery_attempt_and_maps_each_reason(): void
    {
        $shop = ShopOwner::factory()->create();
        $dispatcher = $this->dispatcher($shop, 'resolve-logistics-exceptions');
        $withoutAttempt = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$withoutAttempt->id}/cancel")->assertUnprocessable();

        foreach ([
            'recipient_unavailable' => 'Recipient was unavailable',
            'wrong_or_incomplete_address' => 'Address could not be completed',
            'recipient_refused' => 'Recipient refused the delivery',
            'vehicle_or_delivery_problem' => 'A delivery problem prevented completion',
            'other' => 'Delivery could not be completed',
        ] as $reason => $message) {
            $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
            $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => $reason, 'attempted_at' => now()]);

            $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")
                ->assertOk()
                ->assertJsonPath('message', $message);
        }
    }

    public function test_dispatcher_user_with_permission_can_cancel_its_shop_leg(): void
    {
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo('resolve-logistics-exceptions');
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'other', 'attempted_at' => now()]);

        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")->assertOk();
    }

    public function test_dispatcher_cannot_cancel_a_leg_from_another_shop(): void
    {
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $dispatcher = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $dispatcher->givePermissionTo('assign-logistics-deliveries');
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create()->id, 'status' => 'delivery_attempted']);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'other', 'attempted_at' => now()]);

        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")->assertForbidden();
    }

    public function test_cancel_uses_the_latest_failed_delivery_attempt_by_time_then_id(): void
    {
        $shop = ShopOwner::factory()->create();
        $dispatcher = $this->dispatcher($shop, 'resolve-logistics-exceptions');
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'recipient_unavailable', 'attempted_at' => now()->subMinute()]);
        $attemptedAt = now();
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'recipient_refused', 'attempted_at' => $attemptedAt]);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'other', 'attempted_at' => $attemptedAt]);

        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Delivery could not be completed');
    }

    private function assignedRiderLeg(string $status): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'manual',
            'source_id' => ((int) Shipment::query()->max('source_id')) + 1,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => $status]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $rider->id]);
        $leg->assignments()->create(['assignment_type' => 'internal_rider', 'rider_profile_id' => $profile->id, 'status' => 'assigned', 'assigned_at' => now()]);

        return [$shop, $leg, $rider];
    }

    private function dispatcher(ShopOwner $shop, string $permission): User
    {
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate($permission, 'user'));

        return $dispatcher;
    }

    private function assignedRepairPickupLeg(bool $arrived = true, string $status = 'assigned'): array
    {
        [$shop, $leg, $rider] = $this->assignedRiderLeg($status);
        $leg->shipment->update([
            'source_type' => 'repair_request',
            'purpose' => 'repair_pickup',
            'status' => 'active',
        ]);
        $leg->update(['leg_type' => 'inbound']);
        if ($arrived) {
            $leg->events()->create([
                'shipment_id' => $leg->shipment_id,
                'event_type' => 'pickup_arrived',
                'visibility' => 'internal',
                'message' => 'Rider arrived for pickup.',
                'metadata' => ['delivery_assignment_id' => $leg->assignments()->value('id')],
            ]);
        }

        return [$shop, $leg, $rider];
    }

    private function failedPickupPayload(ShipmentLeg $leg): array
    {
        return [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $leg->assignments()->value('id'),
            'idempotency_key' => 'd7150307-c025-4618-af04-57d12c6463e3',
            'reason_code' => 'customer_unavailable',
        ];
    }

    private function fakeAttemptPhoto(string $name = 'attempt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
