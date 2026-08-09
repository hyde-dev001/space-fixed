<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryIncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_rider_reports_and_dispatcher_confirms_loss(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'picked_up']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(DeliveryIncidentService::class);

        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing during route', 'photo_paths' => ['incident-evidence/leg-1/evidence.jpg']]);
        $resolved = $service->resolve($incident, $shop, 'loss_confirmed', 'Search and investigation completed', ['incident-evidence/incident-1/investigation.jpg']);

        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('loss_confirmed', $leg->fresh()->resolution_type);
        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $leg->assignments()->firstOrFail()->fresh()->status);
        $this->assertSame('cancelled', $leg->shipment->fresh()->status->value);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'loss_confirmed',
            'visibility' => 'customer',
        ]);
    }

    public function test_confirmed_loss_creates_an_idempotent_refund_claim_for_a_paid_retail_order(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = \App\Models\User::factory()->create();
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Lost parcel shoe',
            'slug' => 'lost-parcel-shoe-'.$customer->id,
            'price' => 500,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '42',
            'color' => 'Black',
            'quantity' => 1,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'total_amount' => 500,
            'shipping_fee' => 100,
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'loss-payment-'.$customer->id,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => 'Lost parcel shoe',
            'product_slug' => $product->slug,
            'price' => 500,
            'quantity' => 1,
            'subtotal' => 500,
            'size' => '42',
            'color' => 'Black',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, [
            'type' => 'lost',
            'notes' => 'Missing during route',
            'photo_paths' => ['incident-evidence/leg-loss/report.png'],
        ]);

        $service->resolve($incident, $shop, 'loss_confirmed', 'Investigation completed', ['incident-evidence/incident-loss/investigation.png']);
        $service->resolve($incident->fresh(), $shop, 'loss_confirmed', 'Investigation completed', ['incident-evidence/incident-loss/duplicate.png']);

        $claim = OrderRefund::query()->where('reason_code', 'delivery_loss_confirmed')->firstOrFail();
        $this->assertSame('not_required', $claim->return_status);
        $this->assertSame('pending', $claim->finance_status);
        $this->assertSame(1, OrderRefund::query()->where('reason_code', 'delivery_loss_confirmed')->count());
    }

    public function test_service_rejects_client_supplied_paths_outside_the_incident_evidence_prefix(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'picked_up',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        $this->expectException(ValidationException::class);

        app(DeliveryIncidentService::class)->report($leg, $rider, [
            'type' => 'lost',
            'notes' => 'Missing during route',
            'photo_paths' => ['../../private/secrets.txt'],
        ]);
    }
}
