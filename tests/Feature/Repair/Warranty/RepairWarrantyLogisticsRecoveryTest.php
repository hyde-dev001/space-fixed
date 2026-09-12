<?php

namespace Tests\Feature\Repair\Warranty;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepairWarrantyLogisticsRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_warranty_pickup_records_one_customer_recovery_without_refund(): void
    {
        [$repair, , , $shipment, $leg, $attempt, $replayed] = $this->terminalPickupRecovery();
        $entries = collect(data_get($repair->fresh()->logistics_payment_reconciliation, 'entries', []));
        $recovery = $entries->firstWhere('type', 'pickup_recovery');

        $this->assertSame($attempt->id, $replayed->id);
        $this->assertSame('cancelled', $repair->fresh()->status);
        $this->assertSame('awaiting_arrangement', $recovery['status']);
        $this->assertSame($shipment->id, $recovery['shipment_id']);
        $this->assertSame($leg->id, $recovery['failed_leg_id']);
        $this->assertSame(1, $entries->where('type', 'pickup_recovery')->count());
        $this->assertSame(1, $leg->attempts()->count());
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
    }

    public function test_customer_can_stage_and_change_an_unpaid_shop_pickup_without_duplicate_recovery(): void
    {
        [$repair, $customer, $address, $shipment] = $this->terminalPickupRecovery();
        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.pickup_recovery.state', 'awaiting_arrangement')
            ->assertJsonPath('data.0.pickup_retry_payment_due', false);
        $firstPlan = [
            'method' => 'shop_pickup',
            'address_id' => $address->id,
            'delivery_date' => now()->addDay()->toDateString(),
            'delivery_window' => 'morning',
        ];

        $first = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", $firstPlan)
            ->assertOk()
            ->assertJsonPath('recovery.status', 'awaiting_payment');
        $replayed = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", $firstPlan)
            ->assertOk();

        $this->assertSame($first->json('recovery.plan_key'), $replayed->json('recovery.plan_key'));
        $repair->refresh();
        $this->assertSame('cancelled', $repair->status);
        $this->assertSame('shop_pickup', $repair->intake_delivery_method);
        $this->assertNull($repair->intake_logistics_locked_at);
        $this->assertGreaterThan(0, (float) $repair->intake_delivery_fee);
        $this->assertSame(1, $shipment->legs()->count());
        $this->assertSame(1, collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->where('type', 'pickup_recovery')->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $customer->id)
            ->where('group_key', 'like', "repair-pickup-recovery-%-{$repair->id}-%")
            ->count());

        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_old_pickup_retry',
            'phase' => 'pickup_retry',
            'status' => 'pending',
            'snapshot_version' => data_get($repair->intake_address, 'version'),
            'delivery_method' => 'shop_pickup',
            'service_amount' => 0,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => ['recovery_key' => $first->json('recovery.recovery_key')],
        ]);
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", [
                ...$firstPlan,
                'delivery_date' => now()->addDays(2)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('recovery.status', 'awaiting_payment');

        $this->assertSame('invalidated', $session->fresh()->status);
        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.pickup_recovery.state', 'awaiting_payment')
            ->assertJsonPath('data.0.pickup_retry_payment_due', true);
    }

    public function test_walk_in_reopens_same_warranty_job_for_free_and_is_idempotent(): void
    {
        [$repair, $customer, $address, $shipment] = $this->terminalPickupRecovery();

        $first = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", ['method' => 'walk_in'])
            ->assertOk()
            ->assertJsonPath('recovery.status', 'resolved');
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", ['method' => 'walk_in'])
            ->assertOk()
            ->assertJsonPath('recovery.plan_key', $first->json('recovery.plan_key'));

        $repair->refresh();
        $this->assertSame('repairer_accepted', $repair->status);
        $this->assertSame('walk_in', $repair->intake_delivery_method);
        $this->assertNull($repair->intake_address);
        $this->assertNull($repair->pickup_address);
        $this->assertSame(0.0, (float) $repair->intake_delivery_fee);
        $this->assertSame(1, $shipment->legs()->count());

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", [
                'method' => 'shop_pickup',
                'address_id' => $address->id,
                'delivery_date' => now()->addDay()->toDateString(),
                'delivery_window' => 'morning',
            ])
            ->assertStatus(409);
    }

    public function test_customer_delivery_reopens_for_free_but_cross_customer_and_invalid_addresses_are_rejected(): void
    {
        [$repair, $customer, $address, $shipment] = $this->terminalPickupRecovery();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", [
                'method' => 'customer_delivery',
                'address_id' => $address->id,
            ])
            ->assertNotFound();
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", ['method' => 'customer_delivery'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", [
                'method' => 'customer_delivery',
                'address_id' => $address->id,
            ])
            ->assertOk()
            ->assertJsonPath('recovery.status', 'resolved');

        $repair->refresh();
        $this->assertSame('repairer_accepted', $repair->status);
        $this->assertSame('customer_delivery', $repair->intake_delivery_method);
        $this->assertSame($address->id, data_get($repair->intake_address, 'address_id'));
        $this->assertSame($address->id, data_get($repair->pickup_address, 'address_id'));
        $this->assertSame(0.0, (float) $repair->intake_delivery_fee);
        $this->assertSame(1, $shipment->legs()->count());
    }

    public function test_shop_pickup_recovery_rejects_an_address_outside_coverage(): void
    {
        [$repair, $customer] = $this->terminalPickupRecovery();
        $outside = $this->addressFor($customer, ['latitude' => 15.0, 'longitude' => 121.5]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", [
                'method' => 'shop_pickup',
                'address_id' => $outside->id,
                'delivery_date' => now()->addDay()->toDateString(),
                'delivery_window' => 'morning',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');
    }

    public function test_paid_pickup_retry_reopens_the_same_shipment_once(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair, $customer, $address, $shipment, $failedLeg] = $this->terminalPickupRecovery();
        $plan = [
            'method' => 'shop_pickup',
            'address_id' => $address->id,
            'delivery_date' => now()->addDay()->toDateString(),
            'delivery_window' => 'morning',
        ];
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", $plan)
            ->assertOk();

        $repair->refresh();
        $breakdown = app(PaymentSettlementService::class)->repairPaymentBreakdown($repair);
        $this->assertSame('pickup_retry', $breakdown['phase']);
        $this->assertSame(0.0, (float) $breakdown['service_amount']);
        $this->assertSame((float) $repair->intake_delivery_fee, (float) $breakdown['delivery_amount']);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_warranty_pickup_retry',
                    'attributes' => ['checkout_url' => 'https://checkout.test/warranty-pickup-retry'],
                ],
            ]),
        ]);
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session")
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://checkout.test/warranty-pickup-retry')
            ->assertJsonPath('total_amount', (int) $repair->intake_delivery_fee);
        $session = RepairPaymentSession::query()
            ->where('provider_link_id', 'cs_warranty_pickup_retry')
            ->firstOrFail();
        $this->assertSame('pickup_retry', $session->phase);
        $this->assertSame(0.0, (float) $session->service_amount);

        $payload = $this->paidWebhookPayload('cs_warranty_pickup_retry', 'pay_warranty_pickup_retry');
        $this->postJson('/api/webhooks/paymongo', $payload)->assertOk();
        $this->postJson('/api/webhooks/paymongo', $payload)->assertOk();

        $repair->refresh();
        $shipment->refresh();
        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame('repairer_accepted', $repair->status);
        $this->assertNotNull($repair->intake_logistics_locked_at);
        $this->assertSame(2, $shipment->legs()->count());
        $this->assertSame(1, $failedLeg->attempts()->count());
        $this->assertSame(0, $shipment->legs->sortByDesc('sequence')->first()->attempts()->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $customer->id)
            ->where('group_key', 'like', "repair-pickup-recovery-ready_for_dispatch-{$repair->id}-%")
            ->count());
    }

    public function test_changed_pickup_address_reconciles_paid_session_without_reopening_shipment(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair, $customer, $address, $shipment] = $this->terminalPickupRecovery();
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/pickup-recovery", [
                'method' => 'shop_pickup',
                'address_id' => $address->id,
                'delivery_date' => now()->addDay()->toDateString(),
                'delivery_window' => 'morning',
            ])->assertOk();
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_stale_warranty_pickup_retry',
                    'attributes' => ['checkout_url' => 'https://checkout.test/stale-warranty-pickup-retry'],
                ],
            ]),
        ]);
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session")
            ->assertOk();
        $address->update(['address_line' => 'Changed after checkout']);

        $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload(
            'cs_stale_warranty_pickup_retry',
            'pay_stale_warranty_pickup_retry',
        ))->assertOk();

        $this->assertSame('reconciliation', RepairPaymentSession::query()
            ->where('provider_link_id', 'cs_stale_warranty_pickup_retry')->value('status'));
        $this->assertSame(1, $shipment->legs()->count());
        $this->assertSame('cancelled', $repair->fresh()->status);
    }

    public function test_unrelated_cancelled_repair_still_cannot_start_checkout(): void
    {
        $customer = User::factory()->create();
        $shop = ShopOwner::factory()->create(['paymongo_secret_key' => 'sk_test_cancelled_repair']);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'status' => 'cancelled',
            'payment_enabled' => true,
            'payment_status' => 'pending',
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This repair request is cancelled and cannot be paid.');
    }

    private function terminalPickupRecovery(): array
    {
        $customer = User::factory()->create();
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
            'paymongo_secret_key' => 'sk_test_warranty_pickup_recovery',
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'max_delivery_attempts' => 1,
            'coverage_radius_km' => 12,
        ]);
        $address = $this->addressFor($customer);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'status' => 'pending',
            'is_warranty_job' => true,
            'billing_mode' => 'warranty_no_charge',
            'intake_delivery_method' => 'shop_pickup',
            'logistics_payment_reconciliation' => ['status' => 'resolved', 'entries' => []],
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'inbound',
            'status' => 'assigned',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $assignment = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        $leg->events()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'message' => 'Rider arrived for pickup.',
            'metadata' => ['delivery_assignment_id' => $assignment->id],
        ]);
        $payload = [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $assignment->id,
            'idempotency_key' => 'warranty-terminal-pickup-recovery',
            'reason_code' => 'customer_unavailable',
            'file_path' => 'delivery-attempts/warranty-terminal-pickup.jpg',
        ];
        $service = app(ShipmentLegService::class);
        $attempt = $service->recordFailedAttempt($leg, $payload);
        $replayed = $service->recordFailedAttempt($leg->fresh(), $payload);

        return [$repair->fresh(), $customer, $address, $shipment, $leg, $attempt, $replayed];
    }

    private function addressFor(User $customer, array $overrides = []): UserAddress
    {
        return UserAddress::create(array_merge([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => '09171234567',
            'region' => 'CALABARZON',
            'province' => 'Cavite',
            'city' => 'General Trias City',
            'barangay' => 'Buenavista II',
            'postal_code' => '4107',
            'address_line' => '126 Ilang-ilang Street',
            'latitude' => 14.6000,
            'longitude' => 120.9800,
            'delivery_instructions' => 'Blue gate',
        ], $overrides));
    }

    private function paidWebhookPayload(string $linkId, string $paymentId): array
    {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'link.payment.paid',
                    'data' => [
                        'id' => $paymentId,
                        'attributes' => ['payment_link_id' => $linkId],
                    ],
                ],
            ],
        ];
    }
}
