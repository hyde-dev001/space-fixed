<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\BatchDispatchService;
use App\Services\Logistics\CustomerTrackingService;
use App\Services\Logistics\ShipmentLegService;
use App\Services\OrderReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentLegStatusConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_required_leg_cannot_be_assigned_as_new_work(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);

        try {
            app(AssignmentService::class)->assignInternalRider($leg, $rider, $shop);
            $this->fail('A correction-required leg was assigned as new work.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipment_leg_id', $exception->errors());
        }

        $this->assertDatabaseCount('delivery_assignments', 0);
        $this->assertSame('proof_correction_required', $leg->fresh()->status->value);
    }

    public function test_correction_required_leg_cannot_be_marked_urgent(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);

        $this->expectException(ValidationException::class);

        app(BatchDispatchService::class)->markUrgent($leg, true);
    }

    public function test_correction_required_leg_cannot_be_rebatched_as_ordinary_work(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
        ]);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'draft',
            'assigned_stop_count' => 2,
        ]);
        $correction = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 1,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        $pending = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 2,
            'status' => 'pending',
        ]);

        try {
            app(BatchDispatchService::class)->replaceStops($batch, [$pending->id, $correction->id]);
            $this->fail('A proof-correction leg was rebatched as ordinary work.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legs', $exception->errors());
        }

        $this->assertSame(1, $correction->fresh()->stop_sequence);
        $this->assertSame(2, $pending->fresh()->stop_sequence);
    }

    public function test_correction_required_leg_cannot_accept_an_old_delivery_offer(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'assigned',
        ]);

        $this->expectException(ValidationException::class);

        app(AssignmentService::class)->respondToOffer($leg, $rider, true);
    }

    public function test_cancelling_an_unstarted_batch_does_not_reset_correction_required_leg(): void
    {
        $shop = ShopOwner::factory()->create();
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'offered',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 1,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);

        app(BatchDispatchService::class)->cancel($batch, 'Proof correction requires separate review.');

        $fresh = $leg->fresh();
        $this->assertSame('proof_correction_required', $fresh->status->value);
        $this->assertSame(RiderProgressState::PROOF_ACTION_REQUIRED, $fresh->rider_progress_state);
    }

    public function test_correction_required_leg_remains_eligible_for_early_customer_receipt(): void
    {
        $shop = ShopOwner::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'shipped',
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);

        $this->assertTrue(app(OrderReceiptService::class)->canConfirm($order));
        $this->assertSame('confirmed', app(OrderReceiptService::class)->confirm($order)['result']);
        $this->assertSame('shipped', $order->fresh()->status->value);
    }

    public function test_customer_tracking_maps_correction_status_to_safe_confirmation_state(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'rejected',
            'rejection_reason' => 'Internal-only review detail',
            'file_path' => 'logistics-proof/rejected.jpg',
        ]);

        $payload = app(CustomerTrackingService::class)->payload($shipment);
        $customerLeg = $payload['legs'][0];

        $this->assertSame('awaiting_proof_approval', $customerLeg['status']);
        $this->assertArrayNotHasKey('rejection_reason', $customerLeg);
        $this->assertArrayNotHasKey('delivery_proof', $customerLeg);
    }

    public function test_terminal_delivery_cancellation_releases_rider_progress(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'delivery_attempted',
            'rider_progress_state' => RiderProgressState::ACTIVE,
        ]);

        app(ShipmentLegService::class)->cancel($leg, 'Customer cancelled the delivery.');

        $fresh = $leg->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame(RiderProgressState::RIDER_RELEASED, $fresh->rider_progress_state);
    }
}
