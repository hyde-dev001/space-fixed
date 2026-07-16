<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_confirms_pickup_then_starts_only_one_stop(): void
    {
        [$leg, $rider] = $this->fixture();
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'pickup', 'proof_type' => 'photo']);
        $service = app(ShipmentLegService::class);

        $this->assertSame('picked_up', $service->confirmPickup($leg, $proof, $rider)->status->value);
        $started = $service->markOutForDelivery($leg->fresh(), $rider);
        $this->assertSame('in_transit', $started->status->value);
        $this->assertNotNull($started->out_for_delivery_at);
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

    public function test_failed_attempt_reschedules_then_reaches_needs_resolution(): void
    {
        [$leg, $rider, $shop] = $this->fixture();
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['max_delivery_attempts' => 2, 'lead_time_days' => 0]);
        $service = app(ShipmentLegService::class);
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'proof_type' => 'photo']);

        $service->recordFailedAttempt($leg, ['reason_code' => 'recipient_unavailable', 'file_path' => 'proof.jpg'], true);
        $this->assertSame(2, $leg->fresh()->attempt_number);
        $leg->update(['status' => 'in_transit']);
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'proof_type' => 'photo']);
        $service->recordFailedAttempt($leg->fresh(), ['reason_code' => 'recipient_unavailable', 'file_path' => 'proof2.jpg'], true);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
    }

    public function test_approved_final_proof_completes_batch(): void
    {
        [$leg] = $this->fixture();
        $leg->update(['status' => 'awaiting_proof_approval', 'requires_delivery_proof' => true]);
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'proof_type' => 'photo', 'review_status' => 'approved']);

        app(ShipmentLegService::class)->markDelivered($leg->fresh());

        $this->assertSame('completed', $leg->deliveryBatch->fresh()->status);
    }

    public function test_needs_resolution_can_retry_or_stage_return(): void
    {
        [$leg] = $this->fixture();
        $leg->update(['status' => 'needs_resolution']);
        $service = app(ShipmentLegService::class);
        $retry = $service->resolveRetry($leg->fresh(), 'One final attempt');
        $this->assertSame('pending', $retry->status->value);
        $retry->update(['status' => 'needs_resolution']);
        $return = $service->requireReturn($retry->fresh(), 'Customer cancelled');
        $this->assertSame('needs_resolution', $return->status->value);
        $this->assertSame('return_required', $return->resolution_type);
    }

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5, 'shop_longitude' => 121]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id, 'status' => 'in_progress']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'delivery_batch_id' => $batch->id, 'status' => 'assigned', 'requires_pickup_proof' => true]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        return [$leg, $rider, $shop];
    }
}
