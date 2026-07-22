<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\Order;
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
        $proof = $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/proof", ['handoff_type' => 'delivery', 'proof_type' => 'photo', 'file_path' => 'proof.jpg'])->assertCreated()->json('proof');
        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/delivered")->assertUnprocessable();
        $this->actingAs($approver, 'user')->postJson("/api/logistics/proofs/{$proof['id']}/approve")->assertOk();
        $this->assertSame('delivered', $leg->fresh()->status->value);
        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertDatabaseHas('handoff_proofs', ['id' => $proof['id'], 'review_status' => 'approved']);
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
            'proof_file' => $this->fakeAttemptPhoto('replay.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame($response->json('attempt.id'), $replay->json('attempt.id'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
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
            ->assertJsonValidationErrors('proof_file');
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
                'proof_file' => $this->fakeAttemptPhoto("attempt-{$status}.png"),
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
            'reason_code' => 'other',
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
            'reason_code' => 'recipient_unavailable',
        ];

        $first = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", $payload)
            ->assertCreated();
        $second = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/attempts", [...$payload, 'reason_code' => 'other'])
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
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => $status]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $rider->id]);
        $leg->assignments()->create(['assignment_type' => 'internal_rider', 'rider_profile_id' => $profile->id, 'status' => 'assigned', 'assigned_at' => now()]);

        return [$shop, $leg, $rider];
    }

    private function fakeAttemptPhoto(string $name = 'attempt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
