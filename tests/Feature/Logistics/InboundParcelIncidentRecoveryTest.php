<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InboundParcelIncidentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public static function sources(): array
    {
        return ['repair intake' => ['repair_request', 'repair_pickup'], 'refund collection' => ['order_refund', 'refund_return']];
    }

    public function test_new_repair_return_leg_cannot_bypass_prior_incident_inspection(): void
    {
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company', 'business_type' => 'both']);
        $repair = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'shipped', 'return_delivery_method' => 'shop_delivery']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'repair_request', 'source_id' => $repair->id, 'purpose' => 'repair_return']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $old = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit', 'custody_state' => 'rider',
            'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $old->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($old, $rider, ['type' => 'damaged', 'notes' => 'Condition requires shop assessment', 'photo_paths' => ['incident-evidence/damaged.jpg']]);
        $service->resolve($incident, $shop, 'return_required', 'Safe containment to shop', [], null, [], null, ['safe_for_transport' => true]);
        $return = $old->returnLeg()->firstOrFail();
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed']);
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);
        $new = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'sequence' => 3, 'status' => 'assigned', 'custody_state' => 'unknown', 'requires_pickup_proof' => false]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $new->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        try {
            app(ShipmentLegService::class)->markPickedUp($new, $rider);
            $this->fail('A new source leg bypassed the prior damaged-parcel inspection.');
        } catch (\Illuminate\Validation\ValidationException $exception) { $this->assertArrayHasKey('inspection', $exception->errors()); }
        $this->assertSame('assigned', $new->fresh()->status->value);
        $this->assertSame('unknown', $new->fresh()->custody_state);
    }

    #[DataProvider('sources')]
    public function test_precollection_incident_reschedules_pickup_without_returning_customer_held_goods(string $type, string $purpose): void
    {
        $this->travelTo(now(config('app.shop_timezone', 'Asia/Manila'))->setDate(2026, 10, 27)->setTime(9, 0));
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company', 'business_type' => 'both']);
        $source = $type === 'repair_request' ? RepairRequest::factory()->create(['shop_owner_id' => $shop->id])
            : OrderRefund::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => $type, 'source_id' => $source->id, 'purpose' => $purpose]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'leg_type' => 'inbound', 'status' => 'assigned',
            'picked_up_at' => null, 'custody_state' => 'customer', 'custody_confirmed_at' => now()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'vehicle_problem', 'notes' => 'Vehicle unavailable before collection', 'photo_paths' => ['incident-evidence/before.jpg']]);
        $this->assertFalse($service->eligibility($incident, ['safe_for_transport' => true])['return_required']['allowed']);
        try {
            $service->resolve($incident, $shop, 'retry', 'Cannot route inbound goods out of shop again', [], $dispatcher,
                ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning',
                    'rider_profile_id' => $rider->id, 'handoff_route' => 'via_shop'], null, ['obstacle_resolved' => true]);
            $this->fail('An inbound collection created an outbound via-shop retry.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('handoff_route', $exception->errors());
        }
        $this->assertSame(1, $leg->assignments()->count());
        $this->assertNull($incident->fresh()->decided_at);
        $service->resolve($incident, $shop, 'retry', 'Reschedule real customer collection', [], $dispatcher,
            ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'rider_profile_id' => $rider->id],
            null, ['obstacle_resolved' => true]);
        $this->assertSame('assigned', $leg->fresh()->status->value);
        $this->assertSame('customer', $leg->fresh()->custody_state);
        $this->assertNull($leg->fresh()->picked_up_at);
        $this->assertSame(0, $leg->custodyTransfers()->count());
        $this->assertFalse($leg->returnLeg()->exists());
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $rider, true);
        $this->assertSame('accepted', $leg->assignments()->latest('id')->value('status'));
    }

    #[DataProvider('sources')]
    public function test_collected_inbound_goods_reach_shop_without_reversed_customer_destination(string $type, string $purpose): void
    {
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company', 'business_type' => 'both']);
        $source = $type === 'repair_request'
            ? RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'pending', 'intake_delivery_method' => 'shop_pickup'])
            : OrderRefund::factory()->create(['shop_owner_id' => $shop->id, 'order_id' => Order::factory()->create(['shop_owner_id' => $shop->id])->id,
                'return_status' => 'pending_staff_pickup', 'return_source' => 'staff', 'staff_return_carrier' => 'Shop-owned logistics']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => $type, 'source_id' => $source->id, 'purpose' => $purpose]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'leg_type' => 'inbound', 'status' => 'in_transit',
            'origin_snapshot' => ['type' => 'customer', 'name' => 'Customer collection'], 'destination_snapshot' => ['type' => 'shop', 'name' => 'Shop intake'],
            'custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $incidents = app(DeliveryIncidentService::class);
        $incident = $incidents->report($leg, $rider, ['type' => 'vehicle_problem', 'notes' => 'Route interrupted after collection', 'photo_paths' => ['incident-evidence/collected.jpg']]);
        $incidents->resolve($incident, $shop, 'return_required', 'Safely complete inbound custody', [], null, [], null, ['safe_for_transport' => true]);
        $return = $leg->returnLeg()->firstOrFail();
        $this->assertSame('shop', $return->destination_snapshot['type']);
        $this->assertSame($shop->id, $return->destination_snapshot['shop_owner_id']);
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed',
            'confirmed_by_type' => User::class, 'confirmed_by_id' => $rider->linked_id]);
        $receiver = User::factory()->create(['shop_owner_id' => $shop->id]);
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop, $receiver);
        $this->assertSame('completed', $shipment->fresh()->status->value);
        $this->assertSame('shop', $leg->fresh()->custody_state);
        if ($type === 'repair_request') {
            $this->assertSame('pending', $source->fresh()->status);
            $this->assertFalse(app(\App\Services\RepairDeliveryService::class)->hasApprovedProof($source->fresh(), 'repair_pickup'));
        } else {
            $this->assertSame('in_transit', $source->fresh()->return_status);
            $this->assertSame(1, OrderRefund::where('order_id', $source->order_id)->count());
        }
    }
}
