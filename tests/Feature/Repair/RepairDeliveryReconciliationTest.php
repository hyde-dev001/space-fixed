<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\Notification;
use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\PaymentSettlementService;
use App\Services\RepairDeliveryService;
use App\Services\NotificationService;
use App\Services\ShippingEstimateService;
use App\Services\Logistics\DeliveryScheduleService;
use App\Services\Logistics\DeliveryEventService;
use App\Services\Logistics\ShipmentLegService;
use App\Services\Logistics\SourceShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RepairDeliveryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_loss_creates_one_shop_scoped_finance_item_and_notifies_once(): void
    {
        [$repair, $customer, , $finance, $settings] = $this->paidIntakeRepair();
        $settings->update(['coverage_radius_km' => 0.01]);
        $delivery = app(RepairDeliveryService::class);

        $this->assertNull($delivery->tryCreateIntakeShipment($repair->fresh()));
        $this->assertNull($delivery->tryCreateIntakeShipment($repair->fresh()));

        $reconciliation = $repair->fresh()->logistics_payment_reconciliation;
        $this->assertSame('pending', data_get($reconciliation, 'status'));
        $this->assertCount(1, data_get($reconciliation, 'entries', []));
        $this->assertSame(1, Notification::query()
            ->where('user_id', $customer->id)
            ->where('group_key', 'like', "repair-delivery-reconciliation-created-{$repair->id}-intake-%")
            ->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $finance->id)
            ->where('group_key', 'like', "repair-delivery-reconciliation-created-{$repair->id}-intake-%")
            ->count());

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/repair-delivery-reconciliations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.repair_id', $repair->id)
            ->assertJsonPath('data.0.phase', 'intake')
            ->assertJsonPath('data.0.can_credit_balance', true);

        [$otherRepair, , , $otherFinance] = $this->paidIntakeRepair();
        $otherRepair->update([
            'logistics_payment_reconciliation' => $reconciliation,
        ]);

        $this->actingAs($otherFinance, 'user')
            ->getJson('/api/finance/repair-delivery-reconciliations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.repair_id', $otherRepair->id);
    }

    public function test_same_shop_staff_can_cancel_only_an_unstarted_leg_and_replays_are_idempotent(): void
    {
        [$repair, , $repairer] = $this->paidIntakeRepair();
        $shipment = app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh());
        $leg = $shipment->legs->first();
        $cancellation = $this->cancellationPayload(
            $repair->fresh(),
            'intake',
            'Customer requested a different pickup address.',
        );
        $this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.intake_handoff.cancellation_target.shipment_leg_id', $leg->id)
            ->assertJsonPath('data.0.intake_handoff.cancellation_target.plan_token', $cancellation['plan_token']);

        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            ['leg' => 'intake', 'reason' => 'Missing cancellation target.'],
        )->assertUnprocessable();
        $this->assertSame('pending', $leg->fresh()->status->value);

        $first = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $cancellation,
        );
        $first->assertOk()->assertJsonPath('data.reconciliation.status', 'pending');

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
        $this->assertNotNull($repair->fresh()->intake_logistics_locked_at);
        $this->assertCount(1, data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []));

        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $cancellation,
        )->assertOk();
        $this->assertCount(1, data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []));

        foreach (['picked_up', 'in_transit', 'awaiting_proof_approval', 'delivered'] as $status) {
            [$lateRepair, , $lateRepairer] = $this->paidIntakeRepair();
            $lateShipment = app(RepairDeliveryService::class)->tryCreateIntakeShipment($lateRepair->fresh());
            $lateShipment->legs->first()->update(['status' => $status]);

            $this->actingAs($lateRepairer, 'user')->postJson(
                "/api/repairer/repairs/{$lateRepair->id}/cancel-delivery-leg",
                $this->cancellationPayload($lateRepair->fresh(), 'intake', 'Too late.'),
            )->assertUnprocessable();
            $this->assertNull($lateRepair->fresh()->logistics_payment_reconciliation);
        }
    }

    public function test_dispatcher_can_cancel_a_failed_repair_pickup_once(): void
    {
        [$repair, , $dispatcher] = $this->paidIntakeRepair();
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $dispatcher->givePermissionTo('assign-logistics-deliveries');
        $shipment = app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh());
        $leg = $shipment->legs->first();
        $leg->update([
            'status' => 'needs_resolution',
            'resolution_type' => 'pickup_failed',
            'resolution_reason' => 'customer_unavailable',
        ]);

        $endpoint = "/api/logistics/legs/{$leg->id}/cancel";
        $this->actingAs($dispatcher, 'user')
            ->postJson($endpoint, ['reason' => 'Customer cancelled the pickup.'])
            ->assertOk();

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
        $this->assertCount(1, data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []));
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'pickup_cancelled',
            'visibility' => 'customer',
            'message' => 'The scheduled pickup was cancelled.',
        ]);

        $this->actingAs($dispatcher, 'user')
            ->postJson($endpoint, ['reason' => 'Customer cancelled the pickup.'])
            ->assertOk();
        $this->assertCount(1, data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []));
        $this->assertSame(1, $leg->events()->where('event_type', 'pickup_cancelled')->count());
    }

    public function test_failed_pickup_cancellation_rejects_stale_retry_and_post_custody_states(): void
    {
        [$repair, , $dispatcher] = $this->paidIntakeRepair();
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $dispatcher->givePermissionTo('assign-logistics-deliveries');
        $shipment = app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh());
        $leg = $shipment->legs->first();
        $leg->update([
            'status' => 'needs_resolution',
            'resolution_type' => 'pickup_failed',
            'resolution_reason' => 'customer_requested_reschedule',
        ]);
        $target = app(RepairDeliveryService::class)
            ->intakeHandoff($repair->fresh(), true)['cancellation_target'];
        app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Retry approved.');
        $shipmentStatus = $shipment->fresh()->status->value;

        try {
            app(RepairDeliveryService::class)->cancelPaidDeliveryLeg(
                $repair->fresh(),
                'intake',
                'Stale cancellation.',
                $dispatcher->id,
                $target['shipment_leg_id'],
                $target['plan_token'],
                requireFailedPickup: true,
            );
            $this->fail('A retry must win over a stale failed-pickup cancellation.');
        } catch (ValidationException) {
            $this->assertSame('pending', $leg->fresh()->status->value);
            $this->assertSame($shipmentStatus, $shipment->fresh()->status->value);
            $this->assertNull($repair->fresh()->logistics_payment_reconciliation);
            $this->assertFalse($leg->events()->where('event_type', 'pickup_cancelled')->exists());
        }

        foreach ([
            ['status' => 'picked_up', 'picked_up_at' => now()],
            ['status' => 'in_transit', 'picked_up_at' => null],
            ['status' => 'needs_resolution', 'picked_up_at' => now()],
        ] as $state) {
            [$lateRepair, , $lateDispatcher] = $this->paidIntakeRepair();
            $lateDispatcher->givePermissionTo('assign-logistics-deliveries');
            $lateShipment = app(RepairDeliveryService::class)->tryCreateIntakeShipment($lateRepair->fresh());
            $lateLeg = $lateShipment->legs->first();
            $lateLeg->update([
                ...$state,
                'resolution_type' => 'pickup_failed',
            ]);

            $this->actingAs($lateDispatcher, 'user')
                ->postJson("/api/logistics/legs/{$lateLeg->id}/cancel", ['reason' => 'Too late.'])
                ->assertUnprocessable();
            $this->assertNull($lateRepair->fresh()->logistics_payment_reconciliation);
        }
    }

    public function test_wrong_shop_staff_and_non_finance_users_cannot_act(): void
    {
        [$repair] = $this->paidIntakeRepair();
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $otherStaff = User::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);

        $this->actingAs($otherStaff, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $this->cancellationPayload($repair->fresh(), 'intake', 'Wrong shop.'),
        )->assertForbidden();

        $this->actingAs($otherStaff, 'user')
            ->getJson('/api/finance/repair-delivery-reconciliations')
            ->assertForbidden();
    }

    public function test_finance_can_credit_the_exact_fee_then_the_replacement_payment_is_delivery_only(): void
    {
        [$repair, $customer, , $finance] = $this->paidIntakeRepair();
        $fee = (float) $repair->intake_delivery_fee;
        app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh());

        $this->actingAs($repair->repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $this->cancellationPayload($repair->fresh(), 'intake', 'Pickup plan changed.'),
        )->assertOk();
        $key = (string) data_get($repair->fresh()->logistics_payment_reconciliation, 'entries.0.compensation_key');
        $paidBefore = (float) $repair->fresh()->total_paid_amount;

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/repair-delivery-reconciliations/{$repair->id}/resolve",
            ['compensation_key' => $key, 'action' => 'credit_balance'],
        )->assertOk()->assertJsonPath('data.status', 'resolved');

        $resolved = $repair->fresh();
        $this->assertNull($resolved->intake_logistics_locked_at);
        $this->assertSame('0.00', number_format((float) $resolved->intake_delivery_fee, 2, '.', ''));
        $this->assertSame(number_format($paidBefore, 2, '.', ''), number_format((float) $resolved->total_paid_amount, 2, '.', ''));
        $this->assertSame(number_format($fee, 2, '.', ''), number_format(
            (float) data_get($resolved->logistics_payment_reconciliation, 'entries.0.credited_amount'),
            2,
            '.',
            '',
        ));

        $resolved->update([
            'intake_delivery_method' => 'customer_delivery',
            'intake_delivery_fee' => 0,
        ]);
        $breakdown = app(PaymentSettlementService::class)
            ->repairPaymentBreakdown($resolved->fresh(), 'deposit');
        $this->assertSame('0.00', number_format((float) $breakdown['service_amount'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $breakdown['delivery_amount'], 2, '.', ''));

        $cancelledShipment = Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $repair->id)
            ->where('purpose', 'repair_pickup')
            ->firstOrFail();
        $resolved->update([
            'intake_delivery_method' => 'shop_pickup',
            'intake_delivery_fee' => $fee,
            'intake_logistics_quote' => ['available' => true, 'fee' => $fee],
            'intake_logistics_locked_at' => now()->addMinute(),
        ]);
        $reactivated = app(RepairDeliveryService::class)->tryCreateIntakeShipment($resolved->fresh());
        $this->assertSame($cancelledShipment->id, $reactivated?->id);
        $this->assertCount(2, $reactivated?->fresh('legs')->legs ?? []);
        $this->assertSame('cancelled', $reactivated?->fresh('legs')->legs->first()->status->value);
        $this->assertSame('pending', $reactivated?->fresh('legs')->legs->last()->status->value);

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/repair-delivery-reconciliations/{$repair->id}/resolve",
            ['compensation_key' => $key, 'action' => 'credit_balance'],
        )->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertSame(1, Notification::query()
            ->where('user_id', $customer->id)
            ->where('group_key', 'like', "repair-delivery-reconciliation-resolved-{$repair->id}-intake-%")
            ->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $finance->id)
            ->where('group_key', 'like', "repair-delivery-reconciliation-resolved-{$repair->id}-intake-%")
            ->count());
    }

    public function test_finance_can_refund_the_original_channel_without_refunding_service(): void
    {
        [$repair, , , $finance] = $this->paidIntakeRepair();
        $fee = (float) $repair->intake_delivery_fee;
        $source = PosTransaction::create([
            'transaction_no' => 'POS-DELIVERY-COMP-'.$repair->id,
            'shop_owner_id' => $repair->shop_owner_id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $repair->user_id,
            'due_type' => 'deposit',
            'subtotal' => 500 + $fee,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500 + $fee,
            'paid_amount' => 500 + $fee,
            'status' => 'paid',
            'paid_at' => now(),
            'metadata' => ['service_amount' => 500, 'delivery_amount' => $fee],
        ]);
        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 500 + $fee,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh());
        $this->actingAs($repair->repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $this->cancellationPayload($repair->fresh(), 'intake', 'Refund delivery fee.'),
        )->assertOk();
        $key = (string) data_get($repair->fresh()->logistics_payment_reconciliation, 'entries.0.compensation_key');

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/repair-delivery-reconciliations/{$repair->id}/resolve",
            ['compensation_key' => $key, 'action' => 'refund_original'],
        )->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('pos_refunds', [
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'workflow_source' => 'delivery_reconciliation',
            'status' => 'succeeded',
            'approved_amount' => $fee,
        ]);
        $resolved = $repair->fresh();
        $this->assertSame('paid', $resolved->payment_status);
        $this->assertSame('500.00', number_format((float) $resolved->total_paid_amount, 2, '.', ''));
        $this->assertNull($resolved->intake_logistics_locked_at);
    }

    public function test_credit_is_rejected_when_the_service_balance_is_lower_than_the_fee(): void
    {
        [$repair, , $repairer, $finance] = $this->paidIntakeRepair();
        $repair->update([
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'total_paid_amount' => 1000 + (float) $repair->intake_delivery_fee,
        ]);
        app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh());
        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $this->cancellationPayload($repair->fresh(), 'intake', 'No service balance remains.'),
        )->assertOk();
        $key = (string) data_get($repair->fresh()->logistics_payment_reconciliation, 'entries.0.compensation_key');

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/repair-delivery-reconciliations/{$repair->id}/resolve",
            ['compensation_key' => $key, 'action' => 'credit_balance'],
        )->assertUnprocessable();

        $this->assertSame('pending', data_get($repair->fresh()->logistics_payment_reconciliation, 'entries.0.status'));
        $this->assertNotNull($repair->fresh()->intake_logistics_locked_at);
    }

    public function test_return_cancellation_restores_ready_state_and_keeps_the_plan_locked_for_finance(): void
    {
        [$repair, , $repairer] = $this->paidIntakeRepair();
        $fee = (float) $repair->intake_delivery_fee;
        $repair->update([
            'status' => 'ready_for_pickup',
            'intake_delivery_method' => 'customer_delivery',
            'intake_delivery_fee' => 0,
            'intake_logistics_quote' => null,
            'intake_logistics_locked_at' => now()->subHour(),
            'return_delivery_method' => 'shop_delivery',
            'return_delivery_fee' => $fee,
            'return_logistics_quote' => ['available' => true, 'fee' => $fee],
            'return_logistics_locked_at' => now(),
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => data_get($repair->return_address, 'version'),
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'total_paid_amount' => 1000 + $fee,
        ]);
        $shipment = app(RepairDeliveryService::class)->tryCreateReturnShipment($repair->fresh());
        $this->assertSame('shipped', $repair->fresh()->status);

        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repair->id}/cancel-delivery-leg",
            $this->cancellationPayload($repair->fresh(), 'return', 'Customer changed the return address.'),
        )->assertOk();

        $this->assertSame('ready_for_pickup', $repair->fresh()->status);
        $this->assertSame('cancelled', $shipment?->fresh()->status->value);
        $this->assertSame('return', data_get($repair->fresh()->logistics_payment_reconciliation, 'entries.0.phase'));
        $this->assertNotNull($repair->fresh()->return_logistics_locked_at);
        $this->assertNotNull($repair->fresh()->return_address_confirmed_version);
    }

    public function test_temporary_shipment_creation_failure_does_not_create_compensation_and_can_retry(): void
    {
        [$repair] = $this->paidIntakeRepair();
        $sourceShipments = \Mockery::mock(SourceShipmentService::class);
        $sourceShipments->shouldReceive('ensureRepairInboundShipment')
            ->once()
            ->andThrow(new \RuntimeException('Temporary database outage.'));
        $delivery = new RepairDeliveryService(
            app(DeliveryScheduleService::class),
            app(ShippingEstimateService::class),
            $sourceShipments,
            app(NotificationService::class),
            app(DeliveryEventService::class),
        );

        try {
            $delivery->tryCreateIntakeShipment($repair->fresh());
            $this->fail('The temporary shipment failure should bubble to the retrying caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Temporary database outage.', $exception->getMessage());
        }

        $this->assertNull($repair->fresh()->logistics_payment_reconciliation);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertNotNull(app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh()));
        $this->assertDatabaseCount('shipments', 1);
    }

    private function paidIntakeRepair(): array
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $settings = LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 12,
            'lead_time_days' => 0,
        ]);
        $customer = User::factory()->create();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        Permission::findOrCreate('access-refund-approval', 'user');
        $finance = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'Finance',
            'status' => 'active',
        ]);
        $finance->givePermissionTo('access-refund-approval');
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'latitude' => 14.6,
            'longitude' => 120.98,
        ]);
        $delivery = app(RepairDeliveryService::class);
        $quote = $delivery->quote($shop, $address);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'repairer_accepted',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'final_total' => 1000,
            'total' => 1000,
            'intake_delivery_method' => 'shop_pickup',
            'intake_address' => $delivery->snapshot($address, 'shop_pickup'),
            'intake_delivery_fee' => $quote['fee'],
            'intake_logistics_quote' => $quote,
            'return_delivery_method' => 'customer_pickup',
            'return_address' => $delivery->snapshot($address, 'customer_pickup'),
            'return_delivery_fee' => 0,
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 500 + (float) $quote['fee'],
            'intake_logistics_locked_at' => now(),
            'payment_enabled' => true,
        ]);

        return [$repair, $customer, $repairer, $finance, $settings, $shop];
    }

    private function cancellationPayload(RepairRequest $repair, string $phase, string $reason): array
    {
        $delivery = app(RepairDeliveryService::class);
        $handoff = $phase === 'intake'
            ? $delivery->intakeHandoff($repair, true)
            : $delivery->returnHandoff($repair, true);

        return [
            'leg' => $phase,
            'reason' => $reason,
            ...$handoff['cancellation_target'],
        ];
    }
}
