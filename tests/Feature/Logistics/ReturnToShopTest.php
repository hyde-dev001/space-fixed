<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ProofService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReturnToShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_is_singleton_and_receipt_ends_custody(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'needs_resolution', 'resolution_type' => 'return_required']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(ShipmentLegService::class);

        $return = $service->createReturnToShop($leg);
        $this->assertSame($return->id, $service->createReturnToShop($leg)->id);
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'proof_type' => 'photo']);
        $service->confirmReturnHandoff($return, $proof, $rider);
        $service->confirmReturnReceipt($return->fresh(), $proof->fresh(), $shop);

        $this->assertSame('delivered', $return->fresh()->status->value);
        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('returned', $leg->fresh()->resolution_type);
    }

    public function test_return_leg_rejects_failed_delivery_attempt_without_mutating_custody(): void
    {
        $return = ShipmentLeg::factory()->create([
            'leg_type' => 'return_to_shop',
            'status' => 'in_transit',
        ]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $return->id,
            'status' => 'accepted',
        ]);

        try {
            app(ShipmentLegService::class)->recordFailedAttempt($return, [
                'delivery_assignment_id' => $assignment->id,
                'reason_code' => 'recipient_unavailable',
                'file_path' => 'attempt.jpg',
            ]);
            $this->fail('A return-to-shop leg accepted a failed delivery attempt.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Return-to-shop legs use the return handoff workflow.',
                $exception->errors()['leg'][0]
            );
        }

        $this->assertSame('in_transit', $return->fresh()->status->value);
        $this->assertSame('accepted', $assignment->fresh()->status);
        $this->assertSame(0, $return->attempts()->count());
    }

    public function test_return_leg_rejects_normal_customer_delivery_proof(): void
    {
        $return = ShipmentLeg::factory()->create([
            'leg_type' => 'return_to_shop',
            'status' => 'in_transit',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Return-to-shop legs require return handoff proof.');

        app(ProofService::class)->recordProof($return, [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'file_path' => 'delivery.jpg',
        ]);
    }

    public function test_rider_handoff_and_dispatcher_receipt_complete_return_through_api(): void
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $shop = ShopOwner::factory()->create();
        $order = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $original = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'needs_resolution',
            'resolution_type' => 'return_required',
        ]);
        $return = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'return_to_shop',
            'status' => 'in_transit',
            'return_for_leg_id' => $original->id,
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'staff_return_carrier' => 'Shop-owned logistics',
            'idempotency_key' => "delivery-attempts-exhausted:{$order->id}:{$original->id}",
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $return->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);

        $proofId = $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$return->id}/proof", [
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'file_path' => 'return.jpg',
        ])->assertCreated()->json('proof.id');
        $this->postJson("/api/logistics/legs/{$return->id}/return-proofs/{$proofId}/handoff")->assertOk();

        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo('assign-logistics-deliveries');
        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/legs/{$return->id}/return-proofs/{$proofId}/receipt")
            ->assertOk();

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$return->id}/return-proofs/{$proofId}/handoff")
            ->assertOk();
        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/legs/{$return->id}/return-proofs/{$proofId}/receipt")
            ->assertOk();

        $this->assertSame('delivered', $return->fresh()->status->value);
        $this->assertSame('returned', $original->fresh()->resolution_type);
        $this->assertSame('approved', HandoffProof::findOrFail($proofId)->review_status);
        $this->assertSame('in_transit', $refund->fresh()->return_status);
    }

    public function test_return_receipt_rejects_proof_from_another_leg(): void
    {
        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $original = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'needs_resolution']);
        $return = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'return_to_shop',
            'status' => 'in_transit',
            'return_for_leg_id' => $original->id,
        ]);
        $otherReturn = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'return_to_shop',
            'status' => 'in_transit',
        ]);
        $otherProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $otherReturn->id,
            'handoff_type' => 'receive',
            'review_status' => 'rider_confirmed',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(ShipmentLegService::class)->confirmReturnReceipt($return, $otherProof, $shop);
    }
}
