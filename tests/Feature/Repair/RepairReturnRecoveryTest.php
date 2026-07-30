<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\ShipmentLegService;
use App\Services\PaymentSettlementService;
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

    public function test_customer_chooses_a_scheduled_redelivery_for_their_returned_repair(): void
    {
        [$repair, $shop, , , $return, $proof] = $this->returnedRepairFixture();
        $customer = User::findOrFail($repair->user_id);
        $deliveryDate = now()->addDays(2)->toDateString();
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);

        $endpoint = "/api/customer/repairs/{$repair->id}/return-recovery";
        $this->actingAs($customer, 'user')
            ->postJson($endpoint, ['action' => 'schedule_redelivery'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_delivery_date', 'delivery_window']);

        $this->actingAs($customer, 'user')
            ->postJson($endpoint, [
                'action' => 'schedule_redelivery',
                'scheduled_delivery_date' => $deliveryDate,
                'delivery_window' => 'afternoon',
            ])
            ->assertOk()
            ->assertJsonPath('recovery.state', 'awaiting_payment');

        $entry = collect(data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []))
            ->firstWhere('recovery_key', "return-to-shop:{$return->id}");
        $this->assertSame(User::class, $entry['selected_by_type']);
        $this->assertSame($customer->id, $entry['selected_by_id']);
        $this->assertSame($deliveryDate, $entry['scheduled_delivery_date']);
        $this->assertSame('afternoon', $entry['delivery_window']);

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'user')
            ->postJson($endpoint, ['action' => 'shop_pickup'])
            ->assertNotFound();
    }

    public function test_customer_can_choose_free_shop_pickup_for_their_returned_repair(): void
    {
        [$repair, $shop, , , $return, $proof] = $this->returnedRepairFixture();
        $customer = User::findOrFail($repair->user_id);
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/return-recovery", [
                'action' => 'shop_pickup',
            ])
            ->assertOk()
            ->assertJsonPath('recovery.state', 'shop_pickup');

        $repair->refresh();
        $this->assertSame('walk_in', $repair->return_delivery_method);
        $this->assertSame(0.0, (float) $repair->return_delivery_fee);
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

    public function test_redelivery_payment_charges_only_the_new_fee_and_reopens_the_same_shipment(): void
    {
        [$repair, $shop, $shipment, $original, $return, $proof] = $this->returnedRepairFixture();
        $deliveryDate = now()->addDays(2)->toDateString();
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);
        app(RepairDeliveryService::class)->resolveReturnRecovery(
            $repair->fresh(),
            'schedule_redelivery',
            ShopOwner::class,
            $shop->id,
            $deliveryDate,
            'afternoon',
        );
        $scheduledEntry = collect(data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []))
            ->firstWhere('recovery_key', "return-to-shop:{$return->id}");
        $this->assertSame($deliveryDate, $scheduledEntry['scheduled_delivery_date']);
        $this->assertSame('afternoon', $scheduledEntry['delivery_window']);
        $fee = $this->confirmCoveredRedeliveryPlan($repair->fresh(), $shop);

        $payments = app(PaymentSettlementService::class);
        $breakdown = $payments->repairPaymentBreakdown($repair->fresh());

        $this->assertSame('redelivery', $breakdown['phase']);
        $this->assertSame('redelivery', $breakdown['due_type']);
        $this->assertSame(0.0, $breakdown['service_amount']);
        $this->assertSame($fee, $breakdown['delivery_amount']);

        RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_old_final',
            'phase' => 'final',
            'status' => 'paid',
            'service_amount' => 500,
            'delivery_amount' => 100,
        ]);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_redelivery',
            'phase' => 'redelivery',
            'status' => 'pending',
            'snapshot_version' => $breakdown['snapshot_version'],
            'delivery_method' => $breakdown['delivery_method'],
            'service_amount' => 0,
            'delivery_amount' => $breakdown['delivery_amount'],
            'quote' => [
                ...$breakdown['quote'],
                'payment_policy' => $breakdown['policy'],
                'payment_phase' => 'redelivery',
                'service_base_amount' => 0,
                'tax_mode' => $payments->repairTaxMode($repair),
                'recovery_key' => $breakdown['recovery_key'],
            ],
        ]);
        $beforePaid = (float) $repair->fresh()->total_paid_amount;
        $result = $payments->settleRepairPaid(
            $repair->fresh(),
            'pay_redelivery_1',
            false,
            $session,
        );
        $settled = $result['model'];
        $replay = $payments->settleRepairPaid(
            $repair->fresh(),
            'pay_redelivery_1',
            false,
            $session->fresh(),
        );

        $this->assertSame('settled', $result['result']);
        $this->assertSame('already_settled', $replay['result']);
        $this->assertSame('completed', $settled->payment_status);
        $this->assertSame($beforePaid + $fee, (float) $settled->total_paid_amount);
        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame('requested', $shipment->fresh()->status->value);
        $this->assertSame(3, $shipment->fresh()->legs()->count());
        $newLeg = $shipment->fresh()->legs()->where('sequence', 3)->firstOrFail();
        $this->assertSame(3, $newLeg->sequence);
        $this->assertSame('pending', $newLeg->status->value);
        $this->assertSame($deliveryDate, $newLeg->scheduled_delivery_date?->toDateString());
        $this->assertSame('afternoon', $newLeg->delivery_window);
        $this->assertSame('returned', $original->fresh()->resolution_type);
        $this->assertSame('delivered', $return->fresh()->status->value);
        $entry = collect(data_get($settled->logistics_payment_reconciliation, 'entries', []))
            ->firstWhere('recovery_key', "return-to-shop:{$return->id}");
        $this->assertSame('paid', $entry['status']);
        $this->assertSame(1, $this->recoveryNotificationCount($settled, 'ready_for_dispatch'));
    }

    public function test_stale_redelivery_session_reconciles_without_dispatch(): void
    {
        [$repair, $shop, $shipment, , $return, $proof] = $this->returnedRepairFixture();
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);
        app(RepairDeliveryService::class)->resolveReturnRecovery(
            $repair->fresh(),
            'schedule_redelivery',
            ShopOwner::class,
            $shop->id,
        );
        $this->confirmCoveredRedeliveryPlan($repair->fresh(), $shop);
        $payments = app(PaymentSettlementService::class);
        $breakdown = $payments->repairPaymentBreakdown($repair->fresh());
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_stale_redelivery',
            'phase' => 'redelivery',
            'status' => 'pending',
            'snapshot_version' => $breakdown['snapshot_version'],
            'delivery_method' => $breakdown['delivery_method'],
            'service_amount' => 0,
            'delivery_amount' => $breakdown['delivery_amount'],
            'quote' => [
                ...$breakdown['quote'],
                'payment_policy' => $breakdown['policy'],
                'service_base_amount' => 0,
                'tax_mode' => $payments->repairTaxMode($repair),
                'recovery_key' => 'return-to-shop:stale',
            ],
        ]);

        $result = $payments->settleRepairPaid(
            $repair->fresh(),
            'pay_stale_redelivery',
            false,
            $session,
        );

        $this->assertSame('reconciliation', $result['result']);
        $this->assertSame('reconciliation', $session->fresh()->status);
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
        $this->assertSame(2, $shipment->fresh()->legs()->count());
        $this->assertSame('completed', $repair->fresh()->payment_status);
    }

    public function test_warranty_redelivery_still_charges_the_new_delivery_fee(): void
    {
        [$repair, $shop, $shipment, , $return, $proof] = $this->returnedRepairFixture();
        $repair->update([
            'is_warranty_job' => true,
            'billing_mode' => 'warranty_no_charge',
            'total_paid_amount' => 0,
        ]);
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);
        app(RepairDeliveryService::class)->resolveReturnRecovery(
            $repair->fresh(),
            'schedule_redelivery',
            ShopOwner::class,
            $shop->id,
        );
        $fee = $this->confirmCoveredRedeliveryPlan($repair->fresh(), $shop);

        $breakdown = app(PaymentSettlementService::class)->repairPaymentBreakdown($repair->fresh());

        $this->assertSame('redelivery', $breakdown['phase']);
        $this->assertSame(0.0, $breakdown['service_amount']);
        $this->assertSame($fee, $breakdown['delivery_amount']);

        $settled = app(PaymentSettlementService::class)->settleRepairPhasePaid(
            $repair->fresh(),
            $breakdown,
            'pay_warranty_redelivery',
        );

        $this->assertSame($fee, (float) $settled->total_paid_amount);
        $this->assertSame('requested', $shipment->fresh()->status->value);
        $this->assertSame(3, $shipment->fresh()->legs()->count());
    }

    public function test_customer_payload_exposes_return_recovery_and_redelivery_payment_due(): void
    {
        [$repair, $shop, , , $return, $proof] = $this->returnedRepairFixture();
        $customer = User::findOrFail($repair->user_id);
        app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop);

        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.return_recovery.state', 'awaiting_arrangement')
            ->assertJsonPath('data.0.redelivery_payment_due', false);

        app(RepairDeliveryService::class)->resolveReturnRecovery(
            $repair->fresh(),
            'schedule_redelivery',
            ShopOwner::class,
            $shop->id,
        );

        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.return_recovery.state', 'awaiting_payment')
            ->assertJsonPath('data.0.redelivery_payment_due', true);
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

    private function confirmCoveredRedeliveryPlan(RepairRequest $repair, ShopOwner $shop): float
    {
        $shop->update([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 12,
            'lead_time_days' => 0,
        ]);
        $address = UserAddress::create([
            'user_id' => $repair->user_id,
            'name' => $repair->customer_name,
            'phone' => $repair->phone,
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'postal_code' => '1000',
            'latitude' => 14.6,
            'longitude' => 120.98,
        ]);
        $delivery = app(RepairDeliveryService::class);
        $snapshot = $delivery->snapshot($address, 'shop_delivery');
        $quote = $delivery->quote($shop->fresh(), $address);
        $quote['address_version'] = $snapshot['version'];
        $quote['method'] = 'shop_delivery';
        $repair->update([
            'return_address' => $snapshot,
            'return_delivery_fee' => $quote['fee'],
            'return_logistics_quote' => $quote,
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $snapshot['version'],
        ]);

        return (float) $quote['fee'];
    }
}
