<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_dispatcher_can_cancel_reported_delivery_and_customer_sees_reason(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivery_attempted',
        ]);
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'recipient_unavailable',
            'attempted_at' => now(),
        ]);
        $dispatcher = User::factory()->create();

        app(ShipmentLegService::class)->cancel($leg, $dispatcher);

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_cancelled',
            'visibility' => 'internal',
            'message' => 'Delivery cancelled by dispatcher.',
            'created_by_type' => User::class,
            'created_by_id' => $dispatcher->id,
        ]);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_cancelled',
            'visibility' => 'customer',
            'message' => 'Delivery cancelled: recipient unavailable.',
        ]);
    }

    public function test_cancelling_a_leg_completes_a_shipment_with_other_delivered_legs(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivered',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivery_attempted',
        ]);
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'recipient_refused',
            'attempted_at' => now(),
        ]);

        app(ShipmentLegService::class)->cancel($leg, User::factory()->create());

        $this->assertSame('completed', $shipment->fresh()->status->value);
    }

    public function test_cancelling_a_leg_keeps_shipment_active_when_work_remains(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivery_attempted',
        ]);
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'other',
            'attempted_at' => now(),
        ]);

        app(ShipmentLegService::class)->cancel($leg, User::factory()->create());

        $this->assertSame('active', $shipment->fresh()->status->value);
    }

    public function test_only_reported_delivery_can_be_cancelled(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'in_transit']);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->cancel($leg, User::factory()->create());
    }
}
