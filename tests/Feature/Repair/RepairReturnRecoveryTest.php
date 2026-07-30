<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentLegService;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairReturnRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_receipt_enters_return_recovery_without_refund_and_replay_is_idempotent(): void
    {
        [$repair, $shop, $shipment, $original, $return, $proof] = $this->returnedRepairFixture();

        $service = app(ShipmentLegService::class);
        $service->confirmReturnReceipt($return, $proof, $shop);
        $service->confirmReturnReceipt($return->fresh(), $proof->fresh(), $shop);

        $repair->refresh();
        $this->assertSame('ready_for_pickup', $repair->status);
        $this->assertNull($repair->shipped_at);
        $this->assertNull($repair->return_logistics_locked_at);
        $this->assertFalse((bool) $repair->pickup_enabled);
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
        $this->assertSame('returned', $original->fresh()->resolution_type);
        $this->assertSame('delivered', $return->fresh()->status->value);
        $this->assertDatabaseCount('pos_refunds', 0);

        $recovery = app(RepairDeliveryService::class)
            ->returnHandoff($repair, true)['recovery'];

        $this->assertSame('returned_to_shop_awaiting_arrangement', $recovery['code']);
        $this->assertSame('awaiting_arrangement', $recovery['state']);
        $this->assertSame("return-to-shop:{$return->id}", $recovery['key']);
        $this->assertDatabaseCount('shipment_legs', 2);
    }

    public function test_return_handoff_is_hidden_only_when_cancelled_before_intake(): void
    {
        $cancelled = RepairRequest::factory()->create([
            'status' => 'cancelled',
            'intake_delivery_method' => 'shop_pickup',
            'return_delivery_method' => 'shop_delivery',
            'received_at' => null,
        ]);

        $hidden = app(RepairDeliveryService::class)->returnHandoff($cancelled, true);

        $this->assertFalse($hidden['visible']);
        $this->assertNull($hidden['recovery']);

        $received = RepairRequest::factory()->create([
            'status' => 'received',
            'intake_delivery_method' => 'shop_pickup',
            'return_delivery_method' => 'shop_delivery',
            'received_at' => now(),
        ]);

        $this->assertTrue(app(RepairDeliveryService::class)->returnHandoff($received, true)['visible']);
    }

    private function returnedRepairFixture(): array
    {
        $shop = ShopOwner::factory()->create();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'shipped',
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'total_paid_amount' => 1000,
            'intake_delivery_method' => 'shop_pickup',
            'return_delivery_method' => 'shop_delivery',
            'return_delivery_fee' => 100,
            'return_logistics_locked_at' => now()->subHour(),
            'return_address_confirmed_at' => now()->subHour(),
            'return_address_confirmed_version' => 'return-v1',
            'pickup_enabled' => true,
            'pickup_enabled_at' => now()->subMinutes(30),
            'shipped_at' => now()->subHour(),
            'received_at' => now()->subDay(),
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
            'status' => 'active',
        ]);
        $original = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 1,
            'leg_type' => 'outbound',
            'status' => 'needs_resolution',
            'resolution_type' => 'return_required',
        ]);
        $return = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 2,
            'leg_type' => 'return_to_shop',
            'status' => 'in_transit',
            'return_for_leg_id' => $original->id,
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $return->id,
            'handoff_type' => 'receive',
            'review_status' => 'rider_confirmed',
        ]);

        return [$repair, $shop, $shipment, $original, $return, $proof];
    }
}
