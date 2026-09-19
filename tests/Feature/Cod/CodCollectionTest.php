<?php

namespace Tests\Feature\Cod;

use App\Models\CodCollection;
use App\Models\Logistics\HandoffProof;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\CodCollectionService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodCollectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assigned_rider_can_record_exact_cash_and_replay_the_same_request(): void
    {
        [$order, $rider] = $this->fixture();
        $service = app(CodCollectionService::class);

        $first = $service->cashCollected($order, $rider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-collection-1',
        ]);
        $replayed = $service->cashCollected($order->fresh(), $rider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-collection-1',
        ]);

        $this->assertSame($first->id, $replayed->id);
        $this->assertDatabaseCount('cod_collections', 1);
        $this->assertDatabaseHas('cod_collections', [
            'id' => $first->id,
            'order_id' => $order->id,
            'rider_user_id' => $rider->id,
            'collected_by_user_id' => $rider->id,
            'collected_amount' => '1170.00',
            'status' => CodCollection::STATUS_CASH_COLLECTED,
        ]);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    #[Test]
    public function rider_can_submit_cash_collected_through_the_logistics_route(): void
    {
        [$order, $rider] = $this->fixture();

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/cod/orders/{$order->id}/cash-collected", [
                'amount' => '1170.00',
                'idempotency_key' => 'cod-collection-route-1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('collection.status', CodCollection::STATUS_CASH_COLLECTED)
            ->assertJsonPath('collection.expected_amount', '1170.00');
    }

    #[Test]
    public function cod_delivery_cannot_be_marked_delivered_before_cash_is_collected(): void
    {
        [$order] = $this->fixture();
        $leg = ShipmentLeg::query()
            ->whereHas('shipment', fn ($query) => $query->where('source_id', $order->id))
            ->firstOrFail();
        $leg->update(['status' => 'awaiting_proof_approval']);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('COD payment must be collected before delivery can be completed.');

        app(ShipmentLegService::class)->markDelivered($leg->fresh());
    }

    #[Test]
    public function cod_delivery_can_be_marked_delivered_after_cash_is_collected(): void
    {
        [$order, $rider] = $this->fixture();
        $leg = ShipmentLeg::query()
            ->whereHas('shipment', fn ($query) => $query->where('source_id', $order->id))
            ->firstOrFail();
        $leg->update(['status' => 'awaiting_proof_approval']);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
        ]);
        app(CodCollectionService::class)->cashCollected($order, $rider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-delivery-collection-1',
        ]);

        $delivered = app(ShipmentLegService::class)->markDelivered($leg->fresh());

        $this->assertSame('delivered', $delivered->status->value);
    }

    #[Test]
    public function rider_can_view_held_cash_and_pending_remittance_collections(): void
    {
        [$order, $rider] = $this->fixture();
        app(CodCollectionService::class)->cashCollected($order, $rider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-collection-summary-1',
        ]);

        $this->actingAs($rider, 'user')
            ->getJson('/api/logistics/cod/collections')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.cash_currently_held', '1170.00')
            ->assertJsonCount(1, 'pending_remittance')
            ->assertJsonPath('pending_remittance.0.order_number', $order->order_number);
    }

    #[Test]
    public function rider_cannot_record_a_different_amount(): void
    {
        [$order, $rider] = $this->fixture();

        $this->expectException(ValidationException::class);

        app(CodCollectionService::class)->cashCollected($order, $rider, [
            'amount' => '1169.99',
            'idempotency_key' => 'cod-collection-wrong-amount',
        ]);
    }

    #[Test]
    public function a_different_rider_cannot_collect_the_assigned_order(): void
    {
        [$order, , $shopOwner] = $this->fixture();
        $otherRider = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $otherRider->givePermissionTo('operate-logistics-deliveries');

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(CodCollectionService::class)->cashCollected($order, $otherRider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-collection-wrong-rider',
        ]);
    }

    #[Test]
    public function non_cod_orders_cannot_create_a_cash_collection(): void
    {
        [$order, $rider] = $this->fixture();
        $order->update(['payment_method' => 'paymongo']);

        $this->expectException(ValidationException::class);

        app(CodCollectionService::class)->cashCollected($order, $rider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-collection-non-cod',
        ]);
    }

    #[Test]
    public function cancelled_delivery_stops_cannot_create_a_cash_collection(): void
    {
        [$order, $rider] = $this->fixture();
        ShipmentLeg::query()
            ->whereHas('shipment', fn ($query) => $query->where('source_id', $order->id))
            ->update(['status' => 'cancelled']);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(CodCollectionService::class)->cashCollected($order, $rider, [
            'amount' => '1170.00',
            'idempotency_key' => 'cod-collection-cancelled',
        ]);
    }

    /** @return array{0: Order, 1: User, 2: ShopOwner} */
    private function fixture(): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'company',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        $rider = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $rider->givePermissionTo('operate-logistics-deliveries');
        $this->clockInEmployee($rider);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);

        $order = Order::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'shipped',
            'total_amount' => 1000,
            'shipping_fee' => 50,
            'vat_amount' => 120,
            'paid_at' => null,
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);

        return [$order, $rider, $shopOwner];
    }
}
