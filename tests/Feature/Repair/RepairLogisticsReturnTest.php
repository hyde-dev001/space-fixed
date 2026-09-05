<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\PaymentSettlementService;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairLogisticsReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlocked_plan_edit_reversions_requotes_and_invalidates_only_the_changed_pending_phase(): void
    {
        [$repair, $customer, $shop, $oldAddress] = $this->coveredRepair();
        $delivery = app(RepairDeliveryService::class);
        $oldVersion = $repair->return_address['version'];
        $repair->update([
            'status' => 'in_progress',
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $oldVersion,
        ]);
        $newAddress = $this->address($customer, [
            'address_line' => '2 New Return Street',
            'latitude' => 14.601,
            'longitude' => 120.981,
        ]);
        $pendingFinal = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_return_old',
            'phase' => 'final',
            'status' => 'pending',
            'snapshot_version' => $oldVersion,
            'delivery_method' => 'shop_delivery',
            'delivery_amount' => $repair->return_delivery_fee,
        ]);
        $pendingInitial = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_intake_unchanged',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'delivery_amount' => $repair->intake_delivery_fee,
        ]);
        $paidFinal = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_return_paid_history',
            'phase' => 'final',
            'status' => 'paid',
            'snapshot_version' => $oldVersion,
            'delivery_method' => 'shop_delivery',
            'delivery_amount' => $repair->return_delivery_fee,
        ]);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'return_delivery_method' => 'shop_delivery',
                'return_address_id' => $newAddress->id,
                'same_as_intake_address' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $repair->refresh();
        $expectedSnapshot = $delivery->snapshot($newAddress, 'shop_delivery');
        $this->assertSame($expectedSnapshot['version'], $repair->return_address['version']);
        $this->assertSame($newAddress->id, $repair->return_address['address_id']);
        $this->assertSame($expectedSnapshot['version'], data_get($repair->return_logistics_quote, 'address_version'));
        $this->assertSame('shop_delivery', data_get($repair->return_logistics_quote, 'method'));
        $this->assertNotSame($oldVersion, $repair->return_address['version']);
        $this->assertNull($repair->return_address_confirmed_at);
        $this->assertNull($repair->return_address_confirmed_version);
        $this->assertFalse($repair->same_as_intake_address);
        $this->assertSame('invalidated', $pendingFinal->fresh()->status);
        $this->assertNotNull($pendingFinal->fresh()->invalidated_at);
        $this->assertSame('cs_return_old', $pendingFinal->fresh()->provider_link_id);
        $this->assertSame('pending', $pendingInitial->fresh()->status);
        $this->assertSame('paid', $paidFinal->fresh()->status);
        $this->assertSame($oldAddress->id, $repair->intake_address['address_id']);
        $this->assertGreaterThan(0, (float) $repair->return_delivery_fee);
        $this->assertSame($shop->id, $repair->shop_owner_id);
    }

    public function test_same_as_intake_tracks_until_detached_and_each_leg_lock_rejects_only_its_edits(): void
    {
        [$repair, $customer] = $this->coveredRepair();
        $firstNewAddress = $this->address($customer, [
            'address_line' => '10 Linked Street',
            'latitude' => 14.602,
            'longitude' => 120.982,
        ]);
        $repair->update([
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $repair->return_address['version'],
        ]);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'intake_delivery_method' => 'shop_pickup',
                'intake_address_id' => $firstNewAddress->id,
            ])
            ->assertOk();

        $repair->refresh();
        $this->assertSame($firstNewAddress->id, $repair->intake_address['address_id']);
        $this->assertSame($firstNewAddress->id, $repair->return_address['address_id']);
        $this->assertNotSame($repair->intake_address['version'], $repair->return_address['version']);
        $this->assertSame('shop_pickup', $repair->intake_address['method']);
        $this->assertSame('shop_delivery', $repair->return_address['method']);
        $this->assertNull($repair->return_address_confirmed_version);

        $detachedReturnVersion = $repair->return_address['version'];
        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'return_delivery_method' => 'shop_delivery',
                'return_address_id' => $firstNewAddress->id,
                'same_as_intake_address' => false,
            ])
            ->assertOk();

        $secondNewAddress = $this->address($customer, [
            'address_line' => '20 Detached Street',
            'latitude' => 14.603,
            'longitude' => 120.983,
        ]);
        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'intake_delivery_method' => 'shop_pickup',
                'intake_address_id' => $secondNewAddress->id,
            ])
            ->assertOk();

        $repair->refresh();
        $this->assertSame($secondNewAddress->id, $repair->intake_address['address_id']);
        $this->assertSame($detachedReturnVersion, $repair->return_address['version']);

        $lockedIntakeVersion = $repair->intake_address['version'];
        $repair->update(['intake_logistics_locked_at' => now()]);
        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'intake_delivery_method' => 'shop_pickup',
                'intake_address_id' => $firstNewAddress->id,
            ])
            ->assertStatus(422);
        $this->assertSame($lockedIntakeVersion, $repair->fresh()->intake_address['version']);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'return_delivery_method' => 'shop_delivery',
                'return_address_id' => $secondNewAddress->id,
                'same_as_intake_address' => false,
            ])
            ->assertOk();
        $repair->refresh();
        $this->assertSame($lockedIntakeVersion, $repair->intake_address['version']);
        $this->assertSame($secondNewAddress->id, $repair->return_address['address_id']);

        $lockedReturnVersion = $repair->return_address['version'];
        $repair->update(['return_logistics_locked_at' => now()]);
        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'return_delivery_method' => 'shop_delivery',
                'return_address_id' => $firstNewAddress->id,
                'same_as_intake_address' => false,
            ])
            ->assertStatus(422);
        $this->assertSame($lockedReturnVersion, $repair->fresh()->return_address['version']);
    }

    public function test_return_confirmation_binds_the_exact_current_or_paid_session_version(): void
    {
        [$repair, $customer] = $this->coveredRepair();
        $version = $repair->return_address['version'];

        $this->actingAs(User::factory()->create(), 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-return-address")
            ->assertNotFound();
        $this->assertNull($repair->fresh()->return_address_confirmed_at);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-return-address")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('return_address_confirmed_version', $version)
            ->assertJsonPath('shipment_id', null);
        $this->assertNotNull($repair->fresh()->return_address_confirmed_at);

        $repair->refresh()->update([
            'return_address_confirmed_at' => null,
            'return_address_confirmed_version' => null,
            'return_logistics_locked_at' => now(),
            'payment_status' => 'completed',
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-return-address")
            ->assertStatus(422);
        $this->assertNull($repair->fresh()->return_address_confirmed_at);

        $paid = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_paid_return_confirmation',
            'phase' => 'final',
            'status' => 'paid',
            'snapshot_version' => $version,
            'delivery_method' => 'shop_delivery',
            'delivery_amount' => $repair->return_delivery_fee,
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-return-address")
            ->assertOk()
            ->assertJsonPath('return_address_confirmed_version', $version);

        $repair->refresh()->update([
            'return_address_confirmed_at' => null,
            'return_address_confirmed_version' => null,
        ]);
        $paid->update(['snapshot_version' => str_repeat('0', 64)]);
        $this->assertNull($repair->fresh()->return_address_confirmed_at);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-return-address")
            ->assertStatus(422);
        $this->assertNull($repair->fresh()->return_address_confirmed_at);
    }

    public function test_ready_payment_and_confirmation_in_any_order_create_exactly_one_return_shipment(): void
    {
        foreach ([
            ['ready', 'payment', 'confirmation'],
            ['payment', 'confirmation', 'ready'],
            ['confirmation', 'ready', 'payment'],
        ] as $events) {
            [$repair, $customer, , , $repairer] = $this->coveredRepair();
            $repair->update(['status' => 'completed']);

            foreach ($events as $index => $event) {
                if ($event === 'ready') {
                    $this->actingAs($repairer, 'user')
                        ->postJson("/api/repairer/repairs/{$repair->id}/mark-ready")
                        ->assertOk();
                } elseif ($event === 'confirmation') {
                    $this->actingAs($customer, 'user')
                        ->postJson("/api/customer/repairs/{$repair->id}/confirm-return-address")
                        ->assertOk();
                } else {
                    $payments = app(PaymentSettlementService::class);
                    $breakdown = [
                        'policy' => 'deposit_50',
                        'phase' => 'final',
                        'service_total' => 1000,
                        'service_amount' => 500,
                        'delivery_amount' => (float) $repair->return_delivery_fee,
                        'total_amount' => 500 + (float) $repair->return_delivery_fee,
                        'snapshot_version' => data_get($repair->return_address, 'version'),
                        'delivery_method' => $repair->return_delivery_method,
                    ];
                    RepairPaymentSession::create([
                        'repair_request_id' => $repair->id,
                        'provider' => 'paymongo',
                        'provider_link_id' => 'cs_return_order_'.(string) $repair->id,
                        'phase' => 'final',
                        'status' => 'paid',
                        'snapshot_version' => $breakdown['snapshot_version'],
                        'delivery_method' => $breakdown['delivery_method'],
                        'delivery_amount' => $breakdown['delivery_amount'],
                    ]);

                    if ((string) $repair->fresh()->status === 'ready_for_pickup') {
                        $payments->settleRepairPhasePaid(
                            $repair->fresh(),
                            $breakdown,
                            'pay_return_'.(string) $repair->id,
                        );
                    } else {
                        $repair->update([
                            'payment_status' => 'completed',
                            'payment_status_derived' => 'completed',
                            'return_logistics_locked_at' => now(),
                            'total_paid_amount' => 1000 + (float) $repair->intake_delivery_fee + (float) $repair->return_delivery_fee,
                        ]);
                        app(RepairDeliveryService::class)->tryCreateReturnShipment($repair->fresh());
                    }
                }

                $count = $this->returnShipments($repair)->count();
                $this->assertSame($index === 2 ? 1 : 0, $count);
            }

            $shipment = $this->returnShipments($repair)->firstOrFail();
            $same = app(RepairDeliveryService::class)->tryCreateReturnShipment($repair->fresh());
            $this->assertSame($shipment->id, $same?->id);
            $this->assertSame(1, $this->returnShipments($repair)->count());
            $this->assertSame('shipped', $repair->fresh()->status);
            $this->assertNotNull($repair->fresh()->shipped_at);
        }
    }

    public function test_return_dispatch_preserves_the_accepted_fee_but_rechecks_and_audits_current_coverage(): void
    {
        [$repair, , $shop] = $this->coveredRepair();
        $acceptedFee = 321.45;
        $repair->update([
            'status' => 'ready_for_pickup',
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'return_delivery_fee' => $acceptedFee,
            'return_logistics_locked_at' => now(),
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $repair->return_address['version'],
        ]);

        $shipment = app(RepairDeliveryService::class)->tryCreateReturnShipment($repair->fresh());

        $this->assertNotNull($shipment);
        $leg = $shipment->fresh('legs')->legs->first();
        $this->assertSame('outbound', $leg->leg_type);
        $this->assertSame($repair->return_address['version'], data_get($leg->destination_snapshot, 'version'));
        $this->assertSame(14.6, data_get($leg->destination_snapshot, 'latitude'));
        $this->assertSame(120.98, data_get($leg->destination_snapshot, 'longitude'));
        $this->assertSame('Blue gate', data_get($leg->destination_snapshot, 'delivery_instructions'));
        $this->assertSame(
            number_format($acceptedFee, 2, '.', ''),
            number_format((float) data_get($leg->destination_snapshot, 'accepted_delivery_fee'), 2, '.', ''),
        );
        $this->assertTrue((bool) data_get($leg->destination_snapshot, 'coverage.available'));
        $this->assertSame(12.0, (float) data_get($leg->destination_snapshot, 'coverage.coverage_radius_km'));
        $this->assertSame(14.5995, data_get($leg->origin_snapshot, 'latitude'));
        $this->assertSame(120.9842, data_get($leg->origin_snapshot, 'longitude'));
        $this->assertSame('unscheduled', $leg->schedule_status);
        $this->assertNull($leg->scheduled_delivery_date);
        $this->assertNull($leg->delivery_window);
        $this->assertNull($leg->estimated_at);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'delivery_schedule_attention',
            'visibility' => 'internal',
        ]);

        [$outsideRepair, , $outsideShop] = $this->coveredRepair();
        $outsideRepair->update([
            'status' => 'ready_for_pickup',
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'return_logistics_locked_at' => now(),
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $outsideRepair->return_address['version'],
        ]);
        LogisticsSetting::query()
            ->where('shop_owner_id', $outsideShop->id)
            ->update(['coverage_radius_km' => 0.01]);
        $service = app(RepairDeliveryService::class);

        $this->assertNull($service->tryCreateReturnShipment($outsideRepair->fresh()));
        $this->assertNull($service->tryCreateReturnShipment($outsideRepair->fresh()));
        $this->assertSame(0, $this->returnShipments($outsideRepair)->count());
        $reconciliation = $outsideRepair->fresh()->logistics_payment_reconciliation;
        $this->assertSame('pending', data_get($reconciliation, 'status'));
        $this->assertSame('return_outside_coverage', data_get($reconciliation, 'reason'));
        $this->assertSame('return', data_get($reconciliation, 'phase'));
        $this->assertCount(1, data_get($reconciliation, 'entries', []));
        $this->assertSame($shop->id, $repair->shop_owner_id);
    }

    public function test_compensated_cancelled_return_reuses_the_shipment_and_appends_leg_history(): void
    {
        [$repair] = $this->coveredRepair();
        $repair->update([
            'status' => 'ready_for_pickup',
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'return_logistics_locked_at' => now(),
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $repair->return_address['version'],
        ]);
        $service = app(RepairDeliveryService::class);
        $shipment = $service->tryCreateReturnShipment($repair->fresh());
        $cancelledLeg = $shipment->legs->first();
        $cancelledLeg->update(['status' => 'cancelled']);
        $cancelledAt = now();
        $shipment->update(['status' => 'cancelled', 'cancelled_at' => $cancelledAt]);
        $repair->update([
            'status' => 'ready_for_pickup',
            'logistics_payment_reconciliation' => [
                'type' => 'delivery_compensation',
                'status' => 'resolved',
                'phase' => 'return',
                'reason' => 'cancelled_return_compensated',
                'created_at' => $cancelledAt->toISOString(),
            ],
        ]);

        $this->assertNull($service->tryCreateReturnShipment($repair->fresh()));
        $this->assertCount(1, $shipment->fresh('legs')->legs);

        $repair->update([
            'return_logistics_locked_at' => $cancelledAt->copy()->addMinute(),
            'logistics_payment_reconciliation' => [
                'type' => 'delivery_compensation',
                'status' => 'resolved',
                'phase' => 'intake',
                'reason' => 'unrelated_intake_compensation',
                'created_at' => $cancelledAt->toISOString(),
            ],
        ]);
        $this->assertNull($service->tryCreateReturnShipment($repair->fresh()));
        $this->assertCount(1, $shipment->fresh('legs')->legs);

        $repair->update([
            'logistics_payment_reconciliation' => [
                'type' => 'delivery_compensation',
                'status' => 'resolved',
                'phase' => 'return',
                'reason' => 'cancelled_return_compensated',
                'created_at' => $cancelledAt->toISOString(),
            ],
            'return_address_confirmed_at' => $cancelledAt->copy()->addMinute(),
            'return_address_confirmed_version' => $repair->return_address['version'],
        ]);
        $reactivated = $service->tryCreateReturnShipment($repair->fresh());

        $this->assertSame($shipment->id, $reactivated?->id);
        $reactivated->refresh()->load('legs');
        $this->assertSame('requested', $reactivated->status->value);
        $this->assertNull($reactivated->cancelled_at);
        $this->assertCount(2, $reactivated->legs);
        $this->assertSame('cancelled', $reactivated->legs->first()->status->value);
        $this->assertSame('pending', $reactivated->legs->last()->status->value);
        $this->assertSame(2, $reactivated->legs->last()->sequence);
        $this->assertSame('shipped', $repair->fresh()->status);
    }

    public function test_shop_delivery_rejects_manual_shipping_but_customer_owned_methods_never_create_dispatcher_shipments(): void
    {
        [$shopDelivery, , , , $repairer] = $this->coveredRepair();
        $shopDelivery->update([
            'status' => 'ready_for_pickup',
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'total_paid_amount' => 2000,
        ]);
        $manualTracking = [
            'tracking_number' => 'MANUAL-RETURN-1',
            'carrier_company' => 'Manual Carrier',
            'carrier_name' => 'Manual Rider',
            'carrier_phone' => '09171234567',
            'tracking_link' => 'https://tracker.example.com/MANUAL-RETURN-1',
            'estimated_delivery_date' => now()->addDay()->toDateString(),
        ];

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$shopDelivery->id}/ship", $manualTracking)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertSame('ready_for_pickup', $shopDelivery->fresh()->status);
        $this->assertNull($shopDelivery->fresh()->tracking_number);
        $this->assertSame(0, $this->returnShipments($shopDelivery)->count());

        [$customerPickup, , , , $pickupRepairer] = $this->coveredRepair();
        $customerPickup->update([
            'status' => 'ready_for_pickup',
            'return_delivery_method' => 'customer_pickup',
            'return_delivery_fee' => 0,
            'return_logistics_quote' => null,
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'total_paid_amount' => 2000,
        ]);

        $this->actingAs($pickupRepairer, 'user')
            ->postJson("/api/repairer/repairs/{$customerPickup->id}/ship", $manualTracking)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertSame('ready_for_pickup', $customerPickup->fresh()->status);
        $this->assertNull($customerPickup->fresh()->tracking_number);
        $this->assertSame(0, $this->returnShipments($customerPickup)->count());

        [$walkIn, , , , $walkInRepairer] = $this->coveredRepair();
        $walkIn->update([
            'status' => 'ready_for_pickup',
            'return_delivery_method' => 'walk_in',
            'return_address' => null,
            'return_delivery_fee' => 0,
            'return_logistics_quote' => null,
            'payment_status' => 'completed',
            'payment_status_derived' => 'completed',
            'total_paid_amount' => 2000,
        ]);

        $this->actingAs($walkInRepairer, 'user')
            ->postJson("/api/repairer/repairs/{$walkIn->id}/ship", $manualTracking)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertSame('ready_for_pickup', $walkIn->fresh()->status);
        $this->assertSame(0, $this->returnShipments($walkIn)->count());
    }

    public function test_customer_listing_exposes_both_delivery_plans_and_rejects_foreign_saved_addresses(): void
    {
        [$repair, $customer] = $this->coveredRepair();
        $repair->update([
            'intake_logistics_locked_at' => now()->subMinute(),
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => $repair->return_address['version'],
        ]);

        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.intake_delivery_method', 'shop_pickup')
            ->assertJsonPath('data.0.intake_address.version', $repair->intake_address['version'])
            ->assertJsonPath('data.0.intake_delivery_fee', fn ($fee) => (float) $fee === (float) $repair->intake_delivery_fee)
            ->assertJsonPath('data.0.intake_logistics_quote.method', 'shop_pickup')
            ->assertJsonPath('data.0.return_delivery_method', 'shop_delivery')
            ->assertJsonPath('data.0.return_address.version', $repair->return_address['version'])
            ->assertJsonPath('data.0.return_delivery_fee', fn ($fee) => (float) $fee === (float) $repair->return_delivery_fee)
            ->assertJsonPath('data.0.return_logistics_quote.method', 'shop_delivery')
            ->assertJsonPath('data.0.same_as_intake_address', true)
            ->assertJsonPath('data.0.return_address_confirmed_version', $repair->return_address['version'])
            ->assertJsonPath('data.0.return_logistics_locked_at', null);

        $foreignAddress = $this->address(User::factory()->create());
        $beforeVersion = $repair->return_address['version'];

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
                'return_delivery_method' => 'shop_delivery',
                'return_address_id' => $foreignAddress->id,
                'same_as_intake_address' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('return_address_id');
        $this->assertSame($beforeVersion, $repair->fresh()->return_address['version']);
    }

    private function coveredRepair(): array
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
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
        $address = $this->address($customer);
        $delivery = app(RepairDeliveryService::class);
        $intakeSnapshot = $delivery->snapshot($address, 'shop_pickup');
        $returnSnapshot = $delivery->snapshot($address, 'shop_delivery');
        $intakeQuote = $delivery->quote($shop, $address);
        $returnQuote = $delivery->quote($shop, $address);
        $intakeQuote['address_version'] = $intakeSnapshot['version'];
        $intakeQuote['method'] = 'shop_pickup';
        $returnQuote['address_version'] = $returnSnapshot['version'];
        $returnQuote['method'] = 'shop_delivery';
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'in_progress',
            'delivery_method' => 'pickup',
            'intake_delivery_method' => 'shop_pickup',
            'intake_address' => $intakeSnapshot,
            'intake_delivery_fee' => $intakeQuote['fee'],
            'intake_logistics_quote' => $intakeQuote,
            'return_delivery_method' => 'shop_delivery',
            'return_address' => $returnSnapshot,
            'return_delivery_fee' => $returnQuote['fee'],
            'return_logistics_quote' => $returnQuote,
            'same_as_intake_address' => true,
            'payment_enabled' => true,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'paid',
            'total_paid_amount' => 500 + (float) $intakeQuote['fee'],
            'total' => 1000,
            'final_total' => 1000,
            'pricing_breakdown' => [
                'mode' => 'services',
                'base_total' => 1000,
                'final_total' => 1000,
                'tax_mode' => 'vat_inclusive',
            ],
        ]);

        return [$repair, $customer, $shop, $address, $repairer];
    }

    private function address(User $customer, array $overrides = []): UserAddress
    {
        return UserAddress::create([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'postal_code' => '1000',
            'latitude' => 14.6,
            'longitude' => 120.98,
            'delivery_instructions' => 'Blue gate',
            ...$overrides,
        ]);
    }

    private function returnShipments(RepairRequest $repair)
    {
        return Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $repair->id)
            ->where('purpose', 'repair_return')
            ->get();
    }
}
