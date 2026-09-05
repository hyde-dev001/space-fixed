<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\RiderActiveWorkGuard;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_confirms_pickup_without_shop_arrival_or_location_logging(): void
    {
        [$leg, $rider] = $this->fixture();
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'pickup', 'proof_type' => 'photo']);
        $service = app(ShipmentLegService::class);

        $pickedUp = $service->confirmPickup($leg, $proof, $rider);

        $this->assertSame('picked_up', $pickedUp->status->value);
        $this->assertSame(0, $leg->events()->where('event_type', 'pickup_arrived')->count());
        $this->assertSame('approved', $proof->fresh()->review_status);
        $started = $service->markOutForDelivery($pickedUp->fresh(), $rider);
        $this->assertSame('in_transit', $started->status->value);
        $this->assertNotNull($started->out_for_delivery_at);
    }

    public function test_previous_assignment_arrival_does_not_block_direct_pickup_confirmation(): void
    {
        [$leg, $rider, , $assignment] = $this->fixture();
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'pickup',
            'proof_type' => 'photo',
        ]);
        $previous = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'cancelled',
            'assigned_at' => now()->subHour(),
            'cancelled_at' => now()->subMinutes(30),
        ]);
        $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'message' => 'Previous rider arrival.',
            'metadata' => ['delivery_assignment_id' => $previous->id],
        ]);

        $confirmed = app(ShipmentLegService::class)->confirmPickup($leg, $proof, $rider);

        $this->assertSame('picked_up', $confirmed->status->value);
        $this->assertSame('accepted', $assignment->fresh()->status);
        $this->assertSame('approved', $proof->fresh()->review_status);
    }

    public function test_proof_free_batched_pickup_requires_an_in_progress_batch(): void
    {
        [$leg] = $this->fixture();
        $leg->update(['requires_pickup_proof' => false]);

        $pickedUp = app(ShipmentLegService::class)->markPickedUp($leg->fresh());
        $this->assertSame('picked_up', $pickedUp->status->value);

        [$blockedLeg] = $this->fixture();
        $blockedLeg->update(['requires_pickup_proof' => false]);
        $blockedLeg->deliveryBatch->update(['status' => 'accepted']);

        $this->expectException(ValidationException::class);
        app(ShipmentLegService::class)->markPickedUp($blockedLeg->fresh());
    }

    public function test_repeating_proof_free_batched_pickup_is_idempotent(): void
    {
        [$leg, $rider, , $assignment] = $this->fixture();
        $leg->update(['requires_pickup_proof' => false]);
        $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'message' => 'Rider arrived for pickup.',
            'metadata' => ['delivery_assignment_id' => $assignment->id],
        ]);
        $service = app(ShipmentLegService::class);

        $pickedUp = $service->markPickedUp($leg->fresh(), $rider);
        $replayed = $service->markPickedUp($pickedUp->fresh(), $rider);

        $this->assertSame($pickedUp->id, $replayed->id);
        $this->assertSame(1, DeliveryEvent::where('event_type', 'picked_up')->count());
    }

    public function test_failed_attempt_evidence_matrix_is_enforced_by_the_service(): void
    {
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
            [$leg] = $this->fixture();
            $leg->update(['delivery_batch_id' => null, 'status' => 'in_transit']);

            try {
                app(ShipmentLegService::class)->recordFailedAttempt($leg->fresh(), [
                    'reason_code' => $reason,
                ], true);
                $this->fail("{$reason} accepted missing {$requiredField}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($requiredField, $exception->errors());
            }
        }
    }

    public function test_failed_attempt_is_idempotent_and_stages_resolution_at_maximum(): void
    {
        [$leg, $rider, $shop, $firstAssignment] = $this->fixture();
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['max_delivery_attempts' => 2, 'lead_time_days' => 0]);
        $service = app(ShipmentLegService::class);
        $originatingBatchId = $leg->delivery_batch_id;
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'proof_type' => 'photo']);

        $proofCount = $leg->proofs()->count();
        $attempt = $service->recordFailedAttempt($leg, [
            'delivery_assignment_id' => $firstAssignment->id,
            'reason_code' => 'recipient_unavailable',
            'file_path' => 'proof.jpg',
        ], true);
        $this->assertSame('proof.jpg', $attempt->file_path);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame($firstAssignment->id, $attempt->delivery_assignment_id);
        $this->assertSame($originatingBatchId, $attempt->delivery_batch_id);
        $this->assertSame($proofCount, $leg->proofs()->count());
        $this->assertSame(2, $leg->fresh()->attempt_number);
        $this->assertSame('pending', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $firstAssignment->fresh()->status);
        $this->assertNotNull($firstAssignment->fresh()->cancelled_at);

        $replayed = $service->recordFailedAttempt($leg->fresh(), [
            'delivery_assignment_id' => $firstAssignment->id,
            'reason_code' => 'other',
            'file_path' => 'different.jpg',
            'notes' => 'Replay note',
        ], true);
        $this->assertSame($attempt->id, $replayed->id);
        $this->assertSame(1, $leg->attempts()->count());

        $secondAssignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $leg->update(['status' => 'in_transit']);
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'proof_type' => 'photo']);
        $service->recordFailedAttempt($leg->fresh(), [
            'delivery_assignment_id' => $secondAssignment->id,
            'reason_code' => 'recipient_unavailable',
            'file_path' => 'proof2.jpg',
        ], true);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertNull($leg->fresh()->resolution_type);
        $this->assertSame('accepted', $secondAssignment->fresh()->status);
        $this->assertSame(0, ShipmentLeg::where('return_for_leg_id', $leg->id)->count());
        $this->assertDatabaseHas('delivery_assignments', [
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        try {
            app(RiderActiveWorkGuard::class)->assertCanStartStandalone(
                $rider,
                ShipmentLeg::factory()->create(['shipment_id' => $leg->shipment_id]),
            );
            $this->fail('A rider with unresolved custody started unrelated work.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_work', $exception->errors());
        }
    }

    public function test_failed_attempt_cancels_an_in_progress_batch_after_its_last_stop_is_removed(): void
    {
        [$firstLeg, $rider, $shop] = $this->fixture();
        $batch = $firstLeg->deliveryBatch;
        $secondLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => $batch->id,
            'status' => 'assigned',
            'stop_sequence' => 2,
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $secondLeg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $snapshot = [
            ['id' => $firstLeg->id, 'stop_sequence' => 1],
            ['id' => $secondLeg->id, 'stop_sequence' => 2],
        ];
        $batch->update(['assigned_stop_count' => 2, 'stop_snapshot' => $snapshot]);
        $service = app(ShipmentLegService::class);

        $service->recordFailedAttempt($firstLeg, [
            'reason_code' => 'recipient_unavailable',
            'file_path' => 'first-failure.jpg',
        ], true);

        $this->assertSame('in_progress', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->assigned_stop_count);

        $service->recordFailedAttempt($secondLeg, [
            'reason_code' => 'recipient_unavailable',
            'file_path' => 'second-failure.jpg',
        ], true);

        $batch->refresh();
        $this->assertSame('cancelled', $batch->status);
        $this->assertNotNull($batch->cancelled_at);
        $this->assertSame(0, $batch->assigned_stop_count);
        $this->assertSame($snapshot, $batch->stop_snapshot);
        $this->assertNull($firstLeg->fresh()->delivery_batch_id);
        $this->assertNull($secondLeg->fresh()->delivery_batch_id);
    }

    public function test_failed_attempt_rejects_a_stop_that_moved_to_another_batch(): void
    {
        [$leg, , $shop] = $this->fixture();
        $originalBatch = $leg->deliveryBatch;
        $staleLeg = $leg->fresh();
        $newBatch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'in_progress',
            'assigned_stop_count' => 1,
        ]);
        $leg->update(['delivery_batch_id' => $newBatch->id]);

        try {
            app(ShipmentLegService::class)->recordFailedAttempt($staleLeg, [
                'reason_code' => 'recipient_unavailable',
                'file_path' => 'stale-failure.jpg',
            ], true);
            $this->fail('A stale failed-attempt request changed a stop in another batch.');
        } catch (ValidationException) {
        }

        $this->assertSame($newBatch->id, $leg->fresh()->delivery_batch_id);
        $this->assertSame('assigned', $leg->fresh()->status->value);
        $this->assertSame(0, $leg->attempts()->count());
        $this->assertSame('in_progress', $originalBatch->fresh()->status);
        $this->assertSame('in_progress', $newBatch->fresh()->status);
    }

    public function test_approved_final_proof_completes_batch(): void
    {
        [$leg] = $this->fixture();
        $leg->update(['status' => 'awaiting_proof_approval', 'requires_delivery_proof' => true]);
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'proof_type' => 'photo', 'review_status' => 'approved']);

        app(ShipmentLegService::class)->markDelivered($leg->fresh());

        $this->assertSame('completed', $leg->deliveryBatch->fresh()->status);
    }

    public function test_cancelling_the_last_open_stop_completes_a_batch_with_a_delivered_stop(): void
    {
        [$deliveredLeg, , $shop] = $this->fixture();
        $batch = $deliveredLeg->deliveryBatch;
        $deliveredLeg->update(['status' => 'delivered', 'delivered_at' => now()]);
        $cancelledLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => $batch->id,
            'status' => 'needs_resolution',
        ]);

        app(ShipmentLegService::class)->cancel($cancelledLeg, 'Customer cancelled');

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
    }

    public function test_detaching_the_last_failed_stop_completes_a_batch_with_a_delivered_stop(): void
    {
        [$deliveredLeg, $rider, $shop] = $this->fixture();
        $batch = $deliveredLeg->deliveryBatch;
        $deliveredLeg->update(['status' => 'delivered', 'delivered_at' => now()]);
        $failedLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => $batch->id,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $failedLeg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        app(ShipmentLegService::class)->recordFailedAttempt($failedLeg, [
            'reason_code' => 'recipient_unavailable',
            'file_path' => 'failed.jpg',
        ], true);

        $this->assertNull($failedLeg->fresh()->delivery_batch_id);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
    }

    public function test_needs_resolution_can_retry_or_stage_return(): void
    {
        [$leg] = $this->fixture();
        $leg->update(['status' => 'needs_resolution']);
        $service = app(ShipmentLegService::class);
        $retry = $service->resolveRetry($leg->fresh(), 'One final attempt');
        $this->assertSame('needs_resolution', $retry->status->value);
        $this->assertSame('retry', $retry->resolution_type);
        $this->assertSame('accepted', $leg->assignments()->latest('id')->value('status'));
        $this->assertSame(2, $leg->events()->where('event_type', 'delivery_retry_authorized')->count());
        $retryAgain = $service->resolveRetry($retry->fresh(), 'One final attempt');
        $this->assertSame($retry->id, $retryAgain->id);
        $this->assertSame(2, $leg->events()->where('event_type', 'delivery_retry_authorized')->count());

        try {
            $service->requireReturn($retry->fresh(), 'Customer cancelled');
            $this->fail('A retry-selected leg accepted a return decision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('resolution', $exception->errors());
        }

        [$returnLeg] = $this->fixture();
        $returnLeg->update(['status' => 'needs_resolution']);
        $return = $service->requireReturn($returnLeg->fresh(), 'Customer cancelled');
        $this->assertSame('needs_resolution', $return->status->value);
        $this->assertSame('return_required', $return->resolution_type);
        $returnLeg = $returnLeg->fresh();
        $returnAssignment = ShipmentLeg::where('return_for_leg_id', $returnLeg->id)->firstOrFail();
        $this->assertSame('cancelled', $returnLeg->assignments()->latest('id')->value('status'));
        $this->assertSame('picked_up', $returnAssignment->status->value);

        try {
            $service->resolveRetry($returnLeg, 'Retry after return selection');
            $this->fail('A return-selected leg accepted a retry decision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('resolution', $exception->errors());
        }
    }

    public function test_staged_delivery_retry_requires_due_date_and_assigned_rider(): void
    {
        [$leg, $rider] = $this->fixture();
        $leg->update([
            'status' => 'needs_resolution',
            'resolution_type' => 'retry',
            'scheduled_delivery_date' => now(config('app.shop_timezone', 'Asia/Manila'))->addDay()->toDateString(),
        ]);

        try {
            app(ShipmentLegService::class)->markInTransit($leg->fresh(), $rider);
            $this->fail('A staged retry started before its scheduled date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_delivery_date', $exception->errors());
        }

        $leg->update(['scheduled_delivery_date' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString()]);

        try {
            app(ShipmentLegService::class)->markInTransit($leg->fresh());
            $this->fail('A staged retry started without a rider handoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rider', $exception->errors());
        }

        $started = app(ShipmentLegService::class)->markInTransit($leg->fresh(), $rider);

        $this->assertSame('in_transit', $started->status->value);
        $this->assertSame(0, $leg->attempts()->count());
    }

    public function test_assignment_service_rejects_non_retryable_leg_states(): void
    {
        foreach (['needs_resolution', 'delivered', 'cancelled'] as $status) {
            [$leg, $rider, $shop] = $this->fixture();
            $leg->update(['status' => $status]);

            try {
                app(AssignmentService::class)->assignInternalRider($leg->fresh(), $rider, $shop);
                $this->fail("{$status} leg was assigned.");
            } catch (ValidationException) {
                $this->assertSame($status, $leg->fresh()->status->value);
            }
        }
    }

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5, 'shop_longitude' => 121]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id, 'status' => 'in_progress']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'delivery_batch_id' => $batch->id, 'status' => 'assigned', 'requires_pickup_proof' => true]);
        $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);

        return [$leg, $rider, $shop, $assignment];
    }
}
