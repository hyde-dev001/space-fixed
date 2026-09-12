<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\PaymentSettlementService;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairLogisticsIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_shipment_waits_for_acceptance_and_initial_settlement_in_either_event_order(): void
    {
        [$acceptedFirst] = $this->coveredRepair();
        $acceptedFirst->update(['status' => 'repairer_accepted']);

        $this->assertNull(app(RepairDeliveryService::class)->tryCreateIntakeShipment($acceptedFirst->fresh()));
        $this->assertSame(0, $this->intakeShipments($acceptedFirst)->count());

        $this->settleInitialPhase($acceptedFirst);
        $this->assertSame(1, $this->intakeShipments($acceptedFirst)->count());

        [$paidFirst, , $repairer] = $this->coveredRepair();
        $this->settleInitialPhase($paidFirst);
        $this->assertSame(0, $this->intakeShipments($paidFirst)->count());

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$paidFirst->id}/accept")
            ->assertOk();

        $this->assertSame(1, $this->intakeShipments($paidFirst)->count());
    }

    public function test_ready_intake_creation_is_idempotent_and_copies_the_accepted_snapshot_for_dispatcher_scheduling(): void
    {
        [$repair] = $this->coveredRepair();
        $repair->update([
            'status' => 'repairer_accepted',
            'payment_status' => 'paid',
            'total_paid_amount' => 500 + (float) $repair->intake_delivery_fee,
            'intake_logistics_locked_at' => now(),
        ]);
        $service = app(RepairDeliveryService::class);

        $first = $service->tryCreateIntakeShipment($repair->fresh());
        $second = $service->tryCreateIntakeShipment($repair->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, $this->intakeShipments($repair)->count());
        $this->assertCount(1, $first->fresh('legs')->legs);

        $leg = $first->fresh('legs')->legs->first();
        $this->assertSame('inbound', $leg->leg_type);
        $this->assertSame('unscheduled', $leg->schedule_status);
        $this->assertNull($leg->scheduled_delivery_date);
        $this->assertNull($leg->delivery_window);
        $this->assertNull($leg->estimated_at);
        $this->assertSame($repair->intake_address['version'], data_get($leg->origin_snapshot, 'version'));
        $this->assertSame(14.6, data_get($leg->origin_snapshot, 'latitude'));
        $this->assertSame(120.98, data_get($leg->origin_snapshot, 'longitude'));
        $this->assertSame('Blue gate', data_get($leg->origin_snapshot, 'delivery_instructions'));
        $this->assertSame(
            number_format((float) $repair->intake_delivery_fee, 2, '.', ''),
            number_format((float) data_get($leg->origin_snapshot, 'accepted_delivery_fee'), 2, '.', ''),
        );
        $this->assertTrue((bool) data_get($leg->origin_snapshot, 'coverage.available'));
        $this->assertSame(12.0, (float) data_get($leg->origin_snapshot, 'coverage.coverage_radius_km'));
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $first->id,
            'event_type' => 'delivery_schedule_attention',
            'visibility' => 'internal',
        ]);
        $this->assertDatabaseMissing('delivery_events', [
            'shipment_id' => $first->id,
            'event_type' => 'delivery_estimated',
        ]);
    }

    public function test_current_outside_coverage_creates_no_shipment_and_starts_delivery_fee_compensation_once(): void
    {
        [$repair, , , $settings] = $this->coveredRepair();
        $repair->update([
            'status' => 'repairer_accepted',
            'payment_status' => 'paid',
            'total_paid_amount' => 500 + (float) $repair->intake_delivery_fee,
            'intake_logistics_locked_at' => now(),
        ]);
        $settings->update(['coverage_radius_km' => 0.01]);
        $service = app(RepairDeliveryService::class);

        $this->assertNull($service->tryCreateIntakeShipment($repair->fresh()));
        $this->assertNull($service->tryCreateIntakeShipment($repair->fresh()));
        $this->assertSame(0, $this->intakeShipments($repair)->count());

        $reconciliation = $repair->fresh()->logistics_payment_reconciliation;
        $this->assertSame('pending', data_get($reconciliation, 'status'));
        $this->assertSame('intake_outside_coverage', data_get($reconciliation, 'reason'));
        $this->assertSame('intake', data_get($reconciliation, 'phase'));
        $this->assertSame('refund_delivery_fee', data_get($reconciliation, 'action'));
        $this->assertSame(
            number_format((float) $repair->intake_delivery_fee, 2, '.', ''),
            number_format((float) data_get($reconciliation, 'reconciliation_amount'), 2, '.', ''),
        );
        $this->assertCount(1, data_get($reconciliation, 'entries', []));

        $compensatedAt = data_get($reconciliation, 'entries.0.created_at');
        $settings->update(['coverage_radius_km' => 12]);
        $repair->update([
            'logistics_payment_reconciliation' => [
                ...$reconciliation,
                'status' => 'resolved',
                'resolved_at' => now()->toISOString(),
            ],
        ]);

        $this->assertNull($service->tryCreateIntakeShipment($repair->fresh()));
        $this->assertSame(0, $this->intakeShipments($repair)->count());

        $repair->update([
            'intake_logistics_locked_at' => \Carbon\CarbonImmutable::parse($compensatedAt)->addMinute(),
        ]);

        $this->assertNotNull($service->tryCreateIntakeShipment($repair->fresh()));
        $this->assertSame(1, $this->intakeShipments($repair)->count());
    }

    public function test_walk_in_and_customer_arranged_intake_never_create_dispatcher_shipments(): void
    {
        foreach (['walk_in', 'customer_delivery'] as $method) {
            [$repair] = $this->coveredRepair();
            $repair->update([
                'status' => 'repairer_accepted',
                'intake_delivery_method' => $method,
                'intake_delivery_fee' => 0,
                'intake_logistics_quote' => null,
                'intake_address' => $method === 'walk_in' ? null : $repair->intake_address,
                'payment_status' => 'paid',
                'total_paid_amount' => 500,
                'intake_logistics_locked_at' => now(),
            ]);

            $this->assertNull(app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair->fresh()));
            $this->assertSame(0, $this->intakeShipments($repair)->count());
            $this->assertNull($repair->fresh()->logistics_payment_reconciliation);
        }
    }

    public function test_compensated_cancelled_pickup_reuses_the_shipment_and_preserves_cancelled_leg_history(): void
    {
        [$repair, , , $settings] = $this->coveredRepair();
        $settings->update(['daily_rider_capacity' => 1]);
        $repair->update([
            'status' => 'repairer_accepted',
            'payment_status' => 'paid',
            'total_paid_amount' => 500 + (float) $repair->intake_delivery_fee,
            'intake_logistics_locked_at' => now(),
        ]);
        $service = app(RepairDeliveryService::class);
        $shipment = $service->tryCreateIntakeShipment($repair->fresh());
        $cancelledLeg = $shipment->legs->first();
        $originalSchedule = $cancelledLeg->scheduled_delivery_date?->toDateString();
        $cancelledLeg->update(['status' => 'cancelled']);
        $cancelledAt = now();
        $shipment->update(['status' => 'cancelled', 'cancelled_at' => $cancelledAt]);
        $repair->update([
            'logistics_payment_reconciliation' => [
                'status' => 'resolved',
                'phase' => 'intake',
                'reason' => 'cancelled_pickup_compensated',
            ],
        ]);

        $this->assertNull($service->tryCreateIntakeShipment($repair->fresh()));
        $this->assertCount(1, $shipment->fresh('legs')->legs);

        $repair->update(['intake_logistics_locked_at' => $cancelledAt->copy()->addMinute()]);

        $reactivated = $service->tryCreateIntakeShipment($repair->fresh());

        $this->assertSame($shipment->id, $reactivated?->id);
        $reactivated->refresh()->load('legs');
        $this->assertSame('requested', $reactivated->status->value);
        $this->assertNull($reactivated->cancelled_at);
        $this->assertCount(2, $reactivated->legs);
        $this->assertSame('cancelled', $reactivated->legs->first()->status->value);
        $this->assertSame('pending', $reactivated->legs->last()->status->value);
        $this->assertSame(2, $reactivated->legs->last()->sequence);
        $this->assertSame('unscheduled', $reactivated->legs->last()->schedule_status);
        $this->assertSame($originalSchedule, $reactivated->legs->last()->scheduled_delivery_date?->toDateString());
        $this->assertNull($reactivated->legs->last()->delivery_window);
        $this->assertNull($reactivated->legs->last()->estimated_at);
    }

    private function coveredRepair(): array
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
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        $customer = User::factory()->create();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
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
            'delivery_instructions' => 'Blue gate',
        ]);
        $delivery = app(RepairDeliveryService::class);
        $quote = $delivery->quote($shop, $address);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'assigned_to_repairer',
            'delivery_method' => 'pickup',
            'intake_delivery_method' => 'shop_pickup',
            'intake_address' => $delivery->snapshot($address, 'shop_pickup'),
            'intake_delivery_fee' => $quote['fee'],
            'intake_logistics_quote' => $quote,
            'return_delivery_method' => 'customer_pickup',
            'return_address' => $delivery->snapshot($address, 'customer_pickup'),
            'return_delivery_fee' => 0,
            'payment_enabled' => true,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
            'total' => 1000,
            'final_total' => 1000,
            'pricing_breakdown' => [
                'mode' => 'services',
                'base_total' => 1000,
                'final_total' => 1000,
                'tax_mode' => 'vat_inclusive',
            ],
        ]);

        return [$repair, $customer, $repairer, $settings];
    }

    private function settleInitialPhase(RepairRequest $repair): void
    {
        $payments = app(PaymentSettlementService::class);
        $payments->settleRepairPhasePaid(
            $repair->fresh(),
            $payments->repairPaymentBreakdown($repair->fresh(), 'deposit'),
            'pay_repair_intake_'.(string) $repair->id,
        );
    }

    private function intakeShipments(RepairRequest $repair)
    {
        return Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $repair->id)
            ->where('purpose', 'repair_pickup');
    }
}
