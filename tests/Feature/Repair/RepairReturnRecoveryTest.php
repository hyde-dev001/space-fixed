<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
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

    public function test_repairer_schedules_one_redelivery_requirement_without_creating_a_leg(): void
    {
        [$repair, $shop, $shipment, , $return, $proof] = $this->returnedRepairFixture();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        $repair->update(['assigned_repairer_id' => $repairer->id]);
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);

        $endpoint = "/api/repairer/repairs/{$repair->id}/return-recovery";
        $this->actingAs($repairer, 'user')
            ->postJson($endpoint, ['action' => 'schedule_redelivery'])
            ->assertOk()
            ->assertJsonPath('recovery.state', 'awaiting_payment');
        $this->actingAs($repairer, 'user')
            ->postJson($endpoint, ['action' => 'schedule_redelivery'])
            ->assertOk()
            ->assertJsonPath('recovery.key', "return-to-shop:{$return->id}");

        $entries = collect(data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []))
            ->where('type', 'return_recovery')
            ->where('recovery_key', "return-to-shop:{$return->id}");
        $this->assertCount(1, $entries);
        $this->assertSame('awaiting_payment', $entries->first()['status']);
        $this->assertSame(2, $shipment->fresh()->legs()->count());
        $this->assertSame(1, $this->recoveryNotificationCount($repair, 'awaiting_arrangement'));
        $this->assertSame(1, $this->recoveryNotificationCount($repair, 'awaiting_payment'));
    }

    public function test_shop_pickup_invalidates_unpaid_redelivery_but_rejects_a_paid_one(): void
    {
        [$repair, $shop, , , $return, $proof] = $this->returnedRepairFixture();
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);

        $endpoint = "/api/shop-owner/repairs/{$repair->id}/return-recovery";
        $this->actingAs($shop, 'shop_owner')
            ->postJson($endpoint, ['action' => 'schedule_redelivery'])
            ->assertOk();

        $pending = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_pending_redelivery',
            'phase' => 'redelivery',
            'status' => 'pending',
            'service_amount' => 0,
            'delivery_amount' => 100,
            'quote' => ['recovery_key' => "return-to-shop:{$return->id}"],
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson($endpoint, ['action' => 'shop_pickup'])
            ->assertOk()
            ->assertJsonPath('recovery.state', 'shop_pickup');

        $repair->refresh();
        $this->assertSame('walk_in', $repair->return_delivery_method);
        $this->assertSame('0.00', number_format((float) $repair->return_delivery_fee, 2, '.', ''));
        $this->assertNull($repair->return_logistics_locked_at);
        $this->assertSame('invalidated', $pending->fresh()->status);
        $this->assertSame(1, $this->recoveryNotificationCount($repair, 'shop_pickup'));

        [$paidRepair, $paidShop, , , $paidReturn, $paidProof] = $this->returnedRepairFixture();
        app(ShipmentLegService::class)->confirmReturnReceipt($paidReturn, $paidProof, $paidShop);
        RepairPaymentSession::create([
            'repair_request_id' => $paidRepair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_paid_redelivery',
            'phase' => 'redelivery',
            'status' => 'paid',
            'service_amount' => 0,
            'delivery_amount' => 100,
            'quote' => ['recovery_key' => "return-to-shop:{$paidReturn->id}"],
        ]);

        $this->actingAs($paidShop, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$paidRepair->id}/return-recovery", [
                'action' => 'shop_pickup',
            ])
            ->assertUnprocessable();
    }

    public function test_recovery_action_rejects_cross_shop_repairer(): void
    {
        [$repair, $shop, , , $return, $proof] = $this->returnedRepairFixture();
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);
        $outsider = User::factory()->create([
            'shop_owner_id' => ShopOwner::factory(),
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        $repair->update(['assigned_repairer_id' => $outsider->id]);

        $this->actingAs($outsider, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/return-recovery", [
                'action' => 'schedule_redelivery',
            ])
            ->assertForbidden();
    }

    private function returnedRepairFixture(): array
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'repair']);
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

    private function recoveryNotificationCount(RepairRequest $repair, string $state): int
    {
        return Notification::query()
            ->where('user_id', $repair->user_id)
            ->get()
            ->filter(fn (Notification $notification): bool => data_get($notification->data, 'recovery_state') === $state)
            ->count();
    }
}
