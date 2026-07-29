<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LogisticsApiTest extends TestCase
{
    use RefreshDatabase;

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
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
        ]);

        $this->actingAs($shop, 'shop_owner')->postJson('/api/logistics/legs/schedule', [
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
        Storage::fake('local');
        Storage::fake('public');

        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
            ])
            ->assertJsonValidationErrors('proof_file');

        $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->fakeAttemptPhoto('proof.png'),
                'file_path' => 'chosen/by/rider.png',
            ], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file_path');

        $response = $this->actingAs($shop, 'shop_owner')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->fakeAttemptPhoto('proof.png'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $path = $response->json('proof.file_path');
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
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
            ->assertUnprocessable();
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
        $leg->assignments()->create(['assignment_type' => 'internal_rider', 'rider_profile_id' => $profile->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Storage::fake('local');
        $proof = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'proof_file' => $this->fakeAttemptPhoto('proof.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('proof');
        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/delivered")->assertUnprocessable();
        $this->actingAs($approver, 'user')->postJson("/api/logistics/proofs/{$proof['id']}/approve")->assertOk();
        $this->assertSame('delivered', $leg->fresh()->status->value);
        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertDatabaseHas('handoff_proofs', ['id' => $proof['id'], 'review_status' => 'approved']);
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
        $this->assertSame('in_transit', $leg->fresh()->status->value);
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
            ->assertUnprocessable();

        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->assertSame('pending', $pickupProof->fresh()->review_status);
    }

    public function test_assigned_rider_can_report_a_delivery_issue_with_a_customer_safe_event(): void
    {
        Storage::fake('public');
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
        Storage::disk('public')->assertExists($response->json('attempt.file_path'));

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
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_assigned_rider_can_report_a_failed_repair_pickup_once(): void
    {
        Storage::fake('public');
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
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_reassigned_repair_and_warranty_pickups_require_a_fresh_arrival_and_increment_attempts(): void
    {
        Storage::fake('public');
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

    public function test_failed_repair_pickup_requires_arrival_photo_idempotency_and_valid_context(): void
    {
        Storage::fake('public');
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
        Storage::fake('public');
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
        Storage::fake('public');
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
        Storage::fake('public');
        Permission::findOrCreate('update-logistics-status', 'user');
        [, $leg, $rider] = $this->assignedRiderLeg('in_transit');
        $rider->update(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $rider->givePermissionTo('update-logistics-status');

        $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/report-issue", [
            'reason_code' => 'other',
            'proof_file' => $this->fakeAttemptPhoto(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
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
        Storage::fake('public');
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

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_rider_can_report_issues_from_each_delivery_status(): void
    {
        Storage::fake('public');
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
        Storage::fake('public');
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
        $this->assertSame([], Storage::disk('public')->allFiles());
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
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", $payload)
            ->assertForbidden();
    }

    public function test_only_logistics_dispatchers_can_cancel_after_a_failed_attempt(): void
    {
        [$shop, $leg, $rider] = $this->assignedRiderLeg('delivery_attempted');
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'recipient_unavailable', 'notes' => 'Private rider note', 'attempted_at' => now()]);

        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/cancel")->assertForbidden();
        $this->actingAs($shop, 'shop_owner')->postJson("/api/logistics/legs/{$leg->id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Recipient was unavailable');

        $event = DeliveryEvent::query()->where('shipment_leg_id', $leg->id)->where('visibility', 'customer')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('Private rider note', $event->message);
        $this->assertArrayNotHasKey('notes', $event->metadata);
    }

    public function test_cancel_requires_a_failed_delivery_attempt_and_maps_each_reason(): void
    {
        $shop = ShopOwner::factory()->create();
        $withoutAttempt = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
        $this->actingAs($shop, 'shop_owner')->postJson("/api/logistics/legs/{$withoutAttempt->id}/cancel")->assertUnprocessable();

        foreach ([
            'recipient_unavailable' => 'Recipient was unavailable',
            'wrong_or_incomplete_address' => 'Address could not be completed',
            'recipient_refused' => 'Recipient refused the delivery',
            'vehicle_or_delivery_problem' => 'A delivery problem prevented completion',
            'other' => 'Delivery could not be completed',
        ] as $reason => $message) {
            $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
            $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => $reason, 'attempted_at' => now()]);

            $this->actingAs($shop, 'shop_owner')->postJson("/api/logistics/legs/{$leg->id}/cancel")
                ->assertOk()
                ->assertJsonPath('message', $message);
        }
    }

    public function test_dispatcher_user_with_permission_can_cancel_its_shop_leg(): void
    {
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo('assign-logistics-deliveries');
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
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'delivery_attempted']);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'recipient_unavailable', 'attempted_at' => now()->subMinute()]);
        $attemptedAt = now();
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'recipient_refused', 'attempted_at' => $attemptedAt]);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'other', 'attempted_at' => $attemptedAt]);

        $this->actingAs($shop, 'shop_owner')->postJson("/api/logistics/legs/{$leg->id}/cancel")
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
