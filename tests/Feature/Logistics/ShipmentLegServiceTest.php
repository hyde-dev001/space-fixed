<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
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
}
