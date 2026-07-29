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
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentLegService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentLegServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_leg_cannot_be_marked_picked_up_without_required_pickup_proof(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'assigned',
            'requires_pickup_proof' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markPickedUp($leg);
    }

    #[DataProvider('unapprovedRefundStatuses')]
    public function test_refund_return_cannot_be_picked_up_before_both_approvals(
        string $shopOwnerStatus,
        string $financeStatus,
    ): void {
        $refund = OrderRefund::factory()->create([
            'shop_owner_status' => $shopOwnerStatus,
            'finance_status' => $financeStatus,
            'return_status' => 'pending_staff_pickup',
        ]);
        $shipment = Shipment::factory()->create([
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'assigned',
            'requires_pickup_proof' => false,
        ]);

        try {
            app(ShipmentLegService::class)->markPickedUp($leg);
            $this->fail('The return was picked up before both refund approvals were completed.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Finance and Staff approvals are required before return pickup.',
                $exception->errors()['refund'][0],
            );
        }

        $this->assertSame('assigned', $leg->fresh()->status->value);
    }

    public static function unapprovedRefundStatuses(): array
    {
        return [
            'staff pending' => ['pending', 'approved'],
            'finance pending' => ['approved', 'pending'],
        ];
    }

    public function test_leg_cannot_be_delivered_without_required_delivery_proof(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'awaiting_proof_approval',
            'requires_delivery_proof' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markDelivered($leg);
    }

    public function test_leg_can_be_delivered_after_delivery_proof_is_recorded(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'awaiting_proof_approval',
            'requires_delivery_proof' => true,
        ]);

        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
        ]);

        app(ShipmentLegService::class)->markDelivered($leg);

        $this->assertSame('delivered', $leg->fresh()->status->value);
    }

    public function test_leg_cannot_skip_pickup_before_in_transit(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'assigned']);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markInTransit($leg);
    }

    public function test_rider_cannot_start_a_standalone_delivery_while_a_batch_is_active(): void
    {
        $rider = RiderProfile::factory()->create();
        DeliveryBatch::factory()->create([
            'shop_owner_id' => $rider->shop_owner_id,
            'rider_profile_id' => $rider->id,
            'status' => 'in_progress',
        ]);
        $leg = $this->standaloneLegFor($rider);

        try {
            app(ShipmentLegService::class)->markPickedUp($leg, $rider);
            $this->fail('The rider started a standalone delivery while a batch was active.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_work', $exception->errors());
        }

        $this->assertSame('assigned', $leg->fresh()->status->value);
    }

    public function test_rider_cannot_start_a_second_standalone_delivery(): void
    {
        $rider = RiderProfile::factory()->create();
        $activeLeg = $this->standaloneLegFor($rider, 'in_transit');
        $leg = $this->standaloneLegFor($rider);

        try {
            app(ShipmentLegService::class)->markPickedUp($leg, $rider);
            $this->fail('The rider started a second standalone delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_work', $exception->errors());
        }

        $this->assertSame('in_transit', $activeLeg->fresh()->status->value);
        $this->assertSame('assigned', $leg->fresh()->status->value);
    }

    public function test_repeating_start_for_the_same_standalone_delivery_is_idempotent(): void
    {
        $rider = RiderProfile::factory()->create();
        $leg = $this->standaloneLegFor($rider);
        $service = app(ShipmentLegService::class);

        $started = $service->markPickedUp($leg, $rider);

        $this->assertSame('picked_up', $service->markPickedUp($started, $rider)->status->value);
        $this->assertSame(1, DeliveryEvent::where('event_type', 'picked_up')->count());
    }

    public function test_cancelled_leg_cannot_be_delivered(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'cancelled',
            'requires_delivery_proof' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markDelivered($leg);
    }

    public function test_shipment_completes_when_all_legs_are_delivered(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivered',
            'requires_delivery_proof' => false,
        ]);
        $lastLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
            'requires_delivery_proof' => false,
        ]);

        app(ShipmentLegService::class)->markDelivered($lastLeg);

        $this->assertSame('completed', $shipment->fresh()->status->value);
        $this->assertNotNull($shipment->fresh()->completed_at);
    }

    public function test_completed_shop_owned_delivery_completes_its_order(): void
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
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
            'requires_delivery_proof' => false,
        ]);

        app(ShipmentLegService::class)->markDelivered($leg);

        $this->assertSame('completed', $order->fresh()->status->value);
    }

    public function test_completed_third_party_delivery_keeps_order_shipped_for_staff_activation(): void
    {
        $order = Order::factory()->create(['status' => 'shipped', 'carrier_company' => 'Third Party Courier']);
        $shipment = Shipment::factory()->create(['source_type' => 'order', 'source_id' => $order->id, 'status' => 'active']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit', 'requires_delivery_proof' => false]);

        app(ShipmentLegService::class)->markDelivered($leg);

        $this->assertSame('shipped', $order->fresh()->status->value);
    }

    public function test_completed_shop_owned_return_allows_staff_confirmation(): void
    {
        $refund = OrderRefund::factory()->create([
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'staff_return_carrier' => 'Shop-owned logistics',
        ]);
        $shipment = Shipment::factory()->create([
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
            'requires_delivery_proof' => false,
        ]);

        app(ShipmentLegService::class)->markDelivered($leg);

        $this->assertSame('in_transit', $refund->fresh()->return_status);
    }

    public function test_failed_repair_pickup_waits_for_dispatcher_without_return_or_refund(): void
    {
        [$leg, $assignment] = $this->assignedRepairPickup();

        $attempt = app(ShipmentLegService::class)->recordFailedAttempt($leg, [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $assignment->id,
            'idempotency_key' => '3aa7c6c2-0459-48be-ab0a-32090fe414cd',
            'reason_code' => 'customer_unavailable',
            'file_path' => "logistics-attempt/{$leg->id}/door.jpg",
        ], true);

        $this->assertSame('pickup', $attempt->attempt_type);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame('pickup_failed', $leg->fresh()->resolution_type);
        $this->assertNull($leg->fresh()->delivery_batch_id);
        $this->assertSame('cancelled', $assignment->fresh()->status);
        $this->assertFalse($leg->returnLeg()->exists());
        $this->assertDatabaseMissing('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_attempt_failed',
        ]);
        foreach (['customer', 'internal'] as $visibility) {
            $this->assertDatabaseHas('delivery_events', [
                'shipment_leg_id' => $leg->id,
                'event_type' => 'pickup_attempt_failed',
                'visibility' => $visibility,
            ]);
        }
    }

    public function test_failed_repair_pickup_detaches_only_its_batch_stop_and_replays_once(): void
    {
        $shop = ShopOwner::factory()->create();
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'in_progress',
            'capacity' => 2,
            'assigned_stop_count' => 2,
        ]);
        [$leg, $assignment] = $this->assignedRepairPickup($batch);
        $remainingLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'assigned',
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 2,
        ]);
        $payload = [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $assignment->id,
            'idempotency_key' => 'dd7036c6-5462-48ba-9810-f9104d4b4827',
            'reason_code' => 'customer_unavailable',
            'file_path' => "logistics-attempt/{$leg->id}/door.jpg",
        ];

        $first = app(ShipmentLegService::class)->recordFailedAttempt($leg, $payload, true);
        $replay = app(ShipmentLegService::class)->recordFailedAttempt($leg, $payload, true);

        $this->assertSame($first->id, $replay->id);
        $this->assertNull($leg->fresh()->delivery_batch_id);
        $this->assertSame($batch->id, $remainingLeg->fresh()->delivery_batch_id);
        $this->assertSame(1, $batch->fresh()->assigned_stop_count);
        $this->assertSame('in_progress', $batch->fresh()->status);
        $this->assertSame(2, DeliveryEvent::query()
            ->where('shipment_leg_id', $leg->id)
            ->where('event_type', 'pickup_attempt_failed')
            ->count());
    }

    public function test_failed_repair_pickup_retry_uses_the_next_shop_operating_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-31 09:00', config('app.shop_timezone', 'Asia/Manila')));
        [$leg, $assignment] = $this->assignedRepairPickup();
        LogisticsSetting::create([
            'shop_owner_id' => $leg->shipment->shop_owner_id,
            'operating_days' => [1, 2, 3, 4, 5],
            'blackout_dates' => [],
        ]);
        $service = app(ShipmentLegService::class);
        $service->recordFailedAttempt($leg, [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $assignment->id,
            'idempotency_key' => '9c769aa6-d2d2-49c0-a355-86fc52cf61d4',
            'reason_code' => 'customer_requested_reschedule',
            'file_path' => "logistics-attempt/{$leg->id}/door.jpg",
        ], true);

        $retried = $service->resolveRetry($leg->fresh(), 'Customer requested Monday pickup.');

        $this->assertSame('pending', $retried->status->value);
        $this->assertSame('retry', $retried->resolution_type);
        $this->assertSame('Customer requested Monday pickup.', $retried->resolution_reason);
        $this->assertSame('2026-08-03', $retried->scheduled_delivery_date->toDateString());
        $this->assertNull($retried->delivery_batch_id);
        $this->assertFalse($retried->assignments()->whereIn('status', ['assigned', 'accepted'])->exists());
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'pickup_rescheduled',
            'visibility' => 'customer',
            'message' => 'Another pickup attempt has been scheduled.',
        ]);
    }

    public function test_delivery_attempted_leg_can_be_cancelled_and_records_internal_and_customer_events(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'delivery_attempted']);

        app(ShipmentLegService::class)->cancel($leg, 'Customer is unavailable');

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $leg->shipment_id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_cancelled',
            'visibility' => 'internal',
            'message' => 'Dispatcher cancelled the delivery.',
        ]);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $leg->shipment_id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_cancelled',
            'visibility' => 'customer',
            'message' => 'Delivery cancelled: Customer is unavailable.',
        ]);
    }

    #[DataProvider('nonCancellableStatuses')]
    public function test_only_delivery_attempted_legs_can_be_cancelled(string $status): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => $status]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->cancel($leg, 'Customer requested cancellation');
    }

    public static function nonCancellableStatuses(): array
    {
        return array_map(
            fn ($status) => [$status],
            ['pending', 'assigned', 'pickup_scheduled', 'picked_up', 'in_transit', 'awaiting_proof_approval', 'delivered', 'failed', 'cancelled'],
        );
    }

    public function test_cancelling_last_active_leg_cancels_shipment_when_all_legs_are_cancelled(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'cancelled']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivery_attempted']);

        app(ShipmentLegService::class)->cancel($leg, 'Customer cancelled');

        $this->assertSame('cancelled', $shipment->fresh()->status->value);
        $this->assertNull($shipment->fresh()->completed_at);
    }

    public function test_cancelling_last_active_leg_completes_shipment_when_another_leg_was_delivered(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivered']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivery_attempted']);

        app(ShipmentLegService::class)->cancel($leg, 'Customer cancelled');

        $this->assertSame('completed', $shipment->fresh()->status->value);
        $this->assertNotNull($shipment->fresh()->completed_at);
    }

    public function test_cancelling_leg_keeps_shipment_active_when_another_leg_is_in_transit(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'completed', 'completed_at' => now()]);
        ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'delivery_attempted']);

        app(ShipmentLegService::class)->cancel($leg, 'Customer cancelled');

        $this->assertSame('active', $shipment->fresh()->status->value);
        $this->assertNull($shipment->fresh()->completed_at);
    }

    private function standaloneLegFor(RiderProfile $rider, string $status = 'assigned'): ShipmentLeg
    {
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $rider->shop_owner_id])->id,
            'status' => $status,
            'delivery_batch_id' => null,
            'requires_pickup_proof' => false,
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        return $leg;
    }

    private function assignedRepairPickup(?DeliveryBatch $batch = null): array
    {
        $shop = $batch
            ? ShopOwner::query()->findOrFail($batch->shop_owner_id)
            : ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'source_id' => ((int) Shipment::query()->max('source_id')) + 1,
            'purpose' => 'repair_pickup',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'inbound',
            'status' => 'assigned',
            'delivery_batch_id' => $batch?->id,
            'stop_sequence' => $batch ? 1 : null,
            'requires_pickup_proof' => false,
        ]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'status' => 'accepted',
        ]);
        $leg->events()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'message' => 'Rider arrived for pickup.',
        ]);

        return [$leg, $assignment];
    }
}
