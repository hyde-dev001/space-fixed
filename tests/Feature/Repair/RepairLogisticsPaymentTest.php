<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\PosTransaction;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\PaymentSettlementService;
use App\Services\RepairDeliveryService;
use App\Services\RepairPosPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RepairLogisticsPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_policy_allocates_intake_and_return_fees_to_their_matching_pos_phases(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('deposit_50', 1000);
        $intakeDue = 500 + (float) $repair->intake_delivery_fee;

        $deposit = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-logistics-deposit-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $intakeDue]],
        ]);

        $deposit->assertOk()->assertJsonPath('success', true);
        $depositTransaction = PosTransaction::findOrFail((int) $deposit->json('transaction_id'));
        $this->assertSame(number_format($intakeDue, 2, '.', ''), number_format((float) $depositTransaction->total_amount, 2, '.', ''));
        $this->assertSame('500.00', number_format((float) data_get($depositTransaction->metadata, 'service_amount'), 2, '.', ''));
        $this->assertSame(
            number_format((float) $repair->intake_delivery_fee, 2, '.', ''),
            number_format((float) data_get($depositTransaction->metadata, 'delivery_amount'), 2, '.', '')
        );

        $repair->refresh();
        $this->assertNotNull($repair->intake_logistics_locked_at);
        $repair->update(['status' => 'ready_for_pickup']);
        $returnDue = 500 + (float) $repair->return_delivery_fee;

        $balance = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-logistics-balance-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $returnDue]],
        ]);

        $balance->assertOk()->assertJsonPath('success', true);
        $balanceTransaction = PosTransaction::findOrFail((int) $balance->json('transaction_id'));
        $this->assertSame(number_format($returnDue, 2, '.', ''), number_format((float) $balanceTransaction->total_amount, 2, '.', ''));
        $this->assertSame('500.00', number_format((float) data_get($balanceTransaction->metadata, 'service_amount'), 2, '.', ''));
        $this->assertSame(
            number_format((float) $repair->return_delivery_fee, 2, '.', ''),
            number_format((float) data_get($balanceTransaction->metadata, 'delivery_amount'), 2, '.', '')
        );

        $repair->refresh();
        $this->assertNotNull($repair->return_logistics_locked_at);
        $this->assertSame('completed', $repair->payment_status);
        $this->assertSame(
            number_format(1000 + (float) $repair->intake_delivery_fee + (float) $repair->return_delivery_fee, 2, '.', ''),
            number_format((float) $repair->total_paid_amount, 2, '.', '')
        );
    }

    public function test_paymongo_session_persists_exact_initial_components_before_returning_checkout_url(): void
    {
        [$repair, $customer] = $this->coveredRepair('deposit_50', 1000);
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_repair_logistics_initial',
                    'attributes' => ['checkout_url' => 'https://checkout.test/repair-logistics'],
                ],
            ]),
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('checkout_url', 'https://checkout.test/repair-logistics');
        $this->assertSame(
            number_format(500 + (float) $repair->intake_delivery_fee, 2, '.', ''),
            number_format((float) $response->json('total_amount'), 2, '.', '')
        );

        $session = RepairPaymentSession::query()
            ->where('provider_link_id', 'cs_repair_logistics_initial')
            ->firstOrFail();
        $this->assertSame('paymongo', $session->provider);
        $this->assertSame('initial', $session->phase);
        $this->assertSame('pending', $session->status);
        $this->assertSame('500.00', number_format((float) $session->service_amount, 2, '.', ''));
        $this->assertSame(
            number_format((float) $repair->intake_delivery_fee, 2, '.', ''),
            number_format((float) $session->delivery_amount, 2, '.', '')
        );
        $this->assertSame($repair->intake_address['version'], $session->snapshot_version);
        $this->assertSame('shop_pickup', $session->delivery_method);
        $this->assertSame((float) $repair->intake_delivery_fee, (float) data_get($session->quote, 'fee'));
        $this->assertSame('deposit_50', data_get($session->quote, 'payment_policy'));
        $this->assertSame('initial', data_get($session->quote, 'payment_phase'));
        $this->assertSame('500.00', number_format((float) data_get($session->quote, 'service_base_amount'), 2, '.', ''));
        $this->assertSame('cs_repair_logistics_initial', $repair->fresh()->paymongo_link_id);
    }

    public function test_late_paid_invalidated_session_is_resolved_by_its_link_and_reconciles_delivery_once(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['paymongo_link_id' => 'cs_current_repair_session']);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_invalidated_repair_session',
            'phase' => 'initial',
            'status' => 'invalidated',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
            'invalidated_at' => now(),
        ]);

        $payload = $this->paidWebhookPayload('cs_invalidated_repair_session', 'pay_invalidated_repair');
        $this->postJson('/api/webhooks/paymongo', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Repair payment requires reconciliation');

        $session->refresh();
        $repair->refresh();
        $this->assertSame('reconciliation', $session->status);
        $this->assertNotNull($session->resolved_at);
        $this->assertSame('500.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
        $this->assertSame('paid', $repair->payment_status);
        $this->assertSame('pending', data_get($repair->logistics_payment_reconciliation, 'status'));
        $this->assertSame(
            number_format((float) $session->delivery_amount, 2, '.', ''),
            number_format((float) data_get($repair->logistics_payment_reconciliation, 'delivery_amount'), 2, '.', '')
        );

        $this->postJson('/api/webhooks/paymongo', $payload)->assertOk();
        $this->assertSame('500.00', number_format((float) $repair->fresh()->total_paid_amount, 2, '.', ''));
    }

    public function test_full_upfront_charges_service_and_intake_first_then_exposes_return_fee_only_at_ready(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('full_upfront', 1000);
        $initialDue = 1000 + (float) $repair->intake_delivery_fee;

        $initial = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-logistics-full-initial-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $initialDue]],
        ]);
        $initial->assertOk();

        $repair->refresh();
        $this->assertSame('paid', $repair->payment_status);
        $this->assertSame(number_format($initialDue, 2, '.', ''), number_format((float) $repair->total_paid_amount, 2, '.', ''));
        $repair->update(['status' => 'ready_for_pickup']);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_repair_return_fee_only',
                    'attributes' => ['checkout_url' => 'https://checkout.test/repair-return'],
                ],
            ]),
        ]);

        $returnPayment = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session");
        $returnPayment->assertOk()->assertJsonPath('success', true);
        $this->assertSame(
            number_format((float) $repair->return_delivery_fee, 2, '.', ''),
            number_format((float) $returnPayment->json('total_amount'), 2, '.', '')
        );

        $session = RepairPaymentSession::query()
            ->where('provider_link_id', 'cs_repair_return_fee_only')
            ->firstOrFail();
        $this->assertSame('final', $session->phase);
        $this->assertSame('0.00', number_format((float) $session->service_amount, 2, '.', ''));
        $this->assertSame(
            number_format((float) $repair->return_delivery_fee, 2, '.', ''),
            number_format((float) $session->delivery_amount, 2, '.', '')
        );

        config()->set('services.paymongo.webhook_secret', '');
        $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload(
            'cs_repair_return_fee_only',
            'pay_repair_return_fee_only',
        ))->assertOk()->assertJsonPath('message', 'Repair payment processed');

        $repair->refresh();
        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame('completed', $repair->payment_status);
        $this->assertNotNull($repair->return_logistics_locked_at);
        $this->assertSame(
            number_format(1000 + (float) $repair->intake_delivery_fee + (float) $repair->return_delivery_fee, 2, '.', ''),
            number_format((float) $repair->total_paid_amount, 2, '.', '')
        );
    }

    public function test_zero_amount_phase_settles_without_calling_a_payment_provider(): void
    {
        [$repair, $customer] = $this->coveredRepair('full_upfront', 0);
        $repair->update([
            'intake_delivery_method' => 'walk_in',
            'return_delivery_method' => 'walk_in',
            'intake_address' => null,
            'return_address' => null,
            'intake_delivery_fee' => 0,
            'return_delivery_fee' => 0,
            'intake_logistics_quote' => null,
            'return_logistics_quote' => null,
        ]);
        Http::fake();

        $response = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('zero_amount_settled', true)
            ->assertJsonPath('checkout_url', null);
        Http::assertNothingSent();

        $repair->refresh();
        $this->assertSame('completed', $repair->payment_status);
        $this->assertSame('0.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
        $this->assertNotNull($repair->intake_logistics_locked_at);
        $this->assertSame(0, RepairPaymentSession::query()->where('repair_request_id', $repair->id)->count());
    }

    public function test_legacy_paymongo_proxy_uses_the_same_persisted_repair_session_flow(): void
    {
        [$repair, $customer] = $this->coveredRepair('deposit_50', 1000);
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_repair_proxy_session',
                    'attributes' => ['checkout_url' => 'https://checkout.test/repair-proxy'],
                ],
            ]),
        ]);

        $this->actingAs($customer, 'user')->postJson('/api/paymongo-proxy', [
            'repair_request_id' => $repair->id,
        ])->assertOk()->assertJsonPath('checkout_url', 'https://checkout.test/repair-proxy');

        $this->assertDatabaseHas('repair_payment_sessions', [
            'repair_request_id' => $repair->id,
            'provider_link_id' => 'cs_repair_proxy_session',
            'phase' => 'initial',
            'status' => 'pending',
        ]);
        $this->assertSame('cs_repair_proxy_session', $repair->fresh()->paymongo_link_id);
    }

    public function test_changed_pinned_address_is_rejected_before_paymongo_side_effects(): void
    {
        [$repair, $customer] = $this->coveredRepair('deposit_50', 1000);
        UserAddress::query()->findOrFail((int) $repair->intake_address['address_id'])
            ->update(['address_line' => 'Changed after quote']);
        Http::fake();

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('intake_address');

        Http::assertNothingSent();
        $this->assertSame(0, RepairPaymentSession::query()->where('repair_request_id', $repair->id)->count());
    }

    public function test_customer_cannot_attach_an_unpersisted_paymongo_link_to_a_repair(): void
    {
        [$repair, $customer] = $this->coveredRepair('deposit_50', 1000);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/update-payment-link", [
                'paymongo_link_id' => 'cs_forged_client_link',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paymongo_link_id');

        $this->assertNull($repair->fresh()->paymongo_link_id);
    }

    public function test_direct_payment_verification_resolves_the_current_persisted_session(): void
    {
        [$repair] = $this->coveredRepair('deposit_50', 1000);
        $repair->update([
            'status' => 'repairer_accepted',
            'paymongo_link_id' => 'cs_current_verified_session',
        ]);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_current_verified_session',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
        ]);

        $result = app(PaymentSettlementService::class)
            ->settleRepairPaid($repair->fresh(), 'pay_current_verified_session', true);

        $this->assertSame('settled', $result['result']);
        $this->assertSame('paid', $session->fresh()->status);
        $repair->refresh();
        $this->assertSame(
            number_format(500 + (float) $repair->intake_delivery_fee, 2, '.', ''),
            number_format((float) $repair->total_paid_amount, 2, '.', '')
        );
        $this->assertNotNull($repair->intake_logistics_locked_at);
        $this->assertSame('pending', $repair->status);
    }

    public function test_paymongo_checkout_session_webhook_settles_a_repair_session_instead_of_subscription_flow(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['paymongo_link_id' => 'cs_repair_checkout_webhook']);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_repair_checkout_webhook',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
        ]);

        $this->postJson('/api/webhooks/paymongo', [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_repair_checkout_webhook',
                        'attributes' => [
                            'payments' => [[
                                'id' => 'pay_repair_checkout_webhook',
                                'attributes' => ['status' => 'paid'],
                            ]],
                        ],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('message', 'Repair payment processed');

        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame('paid', $repair->fresh()->payment_status);
    }

    public function test_failed_checkout_session_marks_the_repair_session_failed(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['paymongo_link_id' => 'cs_repair_checkout_failed']);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_repair_checkout_failed',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
        ]);

        $this->postJson('/api/webhooks/paymongo', [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.failed',
                    'data' => [
                        'id' => 'cs_repair_checkout_failed',
                        'attributes' => [],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('message', 'Repair payment failure recorded');

        $this->assertSame('failed', $session->fresh()->status);
        $this->assertSame('failed', $repair->fresh()->payment_status);
    }

    public function test_is_warranty_job_keeps_service_free_but_charges_each_shop_owned_leg(): void
    {
        $this->assertWarrantyDeliveryFeeIsCollected([
            'is_warranty_job' => true,
            'billing_mode' => 'warranty',
        ], 'repair-warranty-job-delivery-fee-001');
    }

    public function test_warranty_no_charge_mode_keeps_service_free_but_charges_each_shop_owned_leg(): void
    {
        $this->assertWarrantyDeliveryFeeIsCollected([
            'is_warranty_job' => false,
            'billing_mode' => 'warranty_no_charge',
        ], 'repair-warranty-mode-delivery-fee-001');
    }

    public function test_zero_tender_warranty_payment_cannot_create_pos_transaction(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('full_upfront', 0);
        $repair->update([
            'is_warranty_job' => true,
            'billing_mode' => 'warranty',
            'intake_delivery_method' => 'walk_in',
            'intake_delivery_fee' => 0,
            'intake_logistics_quote' => null,
        ]);

        $exception = null;
        try {
            app(RepairPosPaymentService::class)->checkout($repair->fresh(), [
                'due_type' => 'full',
                'customer_type' => 'registered',
                'customer_id' => $customer->id,
                'idempotency_key' => 'repair-warranty-zero-tender-001',
                'payment_lines' => [['tender_type' => 'cash', 'amount' => 0]],
            ], $actor->id);
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertArrayHasKey('payment_lines', $exception->errors());
        $this->assertDatabaseMissing('pos_transactions', [
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
        ]);
        $this->assertSame(0.0, (float) $repair->fresh()->total_paid_amount);
    }

    private function assertWarrantyDeliveryFeeIsCollected(array $markers, string $idempotencyKey): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('full_upfront', 0);
        $repair->update([...$markers, 'status' => 'repairer_accepted']);
        $due = (float) $repair->intake_delivery_fee;
        $settlement = app(PaymentSettlementService::class);

        $initial = $settlement->repairPaymentBreakdown($repair->fresh(), 'full');
        $this->assertSame('0.00', number_format((float) $initial['service_amount'], 2, '.', ''));
        $this->assertSame(number_format($due, 2, '.', ''), number_format((float) $initial['delivery_amount'], 2, '.', ''));
        $this->assertSame(number_format($due, 2, '.', ''), number_format((float) $initial['total_amount'], 2, '.', ''));
        $this->assertNull($repair->fresh()->intake_logistics_locked_at);
        $this->assertNull($repair->fresh()->return_logistics_locked_at);
        $this->assertSame(0, Shipment::query()->where('source_type', 'repair_request')->where('source_id', $repair->id)->count());

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => $idempotencyKey,
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $due]],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $freshRepair = $repair->fresh();
        $this->assertSame('paid', $freshRepair->payment_status);
        $this->assertNotNull($freshRepair->intake_logistics_locked_at);
        $this->assertNull($freshRepair->return_logistics_locked_at);
        $this->assertSame(1, Shipment::query()->where('source_type', 'repair_request')->where('source_id', $repair->id)->where('purpose', 'repair_pickup')->count());
        $this->assertSame(number_format($due, 2, '.', ''), number_format((float) $freshRepair->total_paid_amount, 2, '.', ''));

        $freshRepair->update([
            'status' => 'ready_for_pickup',
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => data_get($freshRepair->return_address, 'version'),
        ]);
        $final = $settlement->repairPaymentBreakdown($freshRepair->fresh(), 'balance');
        $returnDue = (float) $freshRepair->return_delivery_fee;
        $this->assertSame('0.00', number_format((float) $final['service_amount'], 2, '.', ''));
        $this->assertSame(number_format($returnDue, 2, '.', ''), number_format((float) $final['delivery_amount'], 2, '.', ''));
        $this->assertSame(number_format($returnDue, 2, '.', ''), number_format((float) $final['total_amount'], 2, '.', ''));
        $this->assertSame(0, Shipment::query()->where('source_type', 'repair_request')->where('source_id', $repair->id)->where('purpose', 'repair_return')->count());

        $finalPayload = [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => $idempotencyKey.'-return',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $returnDue]],
        ];
        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $finalPayload)
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $finalPayload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $freshRepair = $repair->fresh();
        $this->assertSame('completed', $freshRepair->payment_status);
        $this->assertNotNull($freshRepair->return_logistics_locked_at);
        $this->assertSame(1, Shipment::query()->where('source_type', 'repair_request')->where('source_id', $repair->id)->where('purpose', 'repair_return')->count());
        $this->assertSame(
            number_format($due + $returnDue, 2, '.', ''),
            number_format((float) $freshRepair->total_paid_amount, 2, '.', ''),
        );
    }

    public function test_late_online_callback_after_pos_phase_does_not_apply_service_twice(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair, $customer, $actor] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['paymongo_link_id' => 'cs_online_then_pos']);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_online_then_pos',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
        ]);
        $due = 500 + (float) $repair->intake_delivery_fee;

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-online-then-pos-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $due]],
        ])->assertOk();

        $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload(
            'cs_online_then_pos',
            'pay_online_after_pos',
        ))->assertOk()->assertJsonPath('message', 'Repair payment requires reconciliation');

        $repair->refresh();
        $this->assertSame(number_format($due, 2, '.', ''), number_format((float) $repair->total_paid_amount, 2, '.', ''));
        $this->assertSame('reconciliation', $session->fresh()->status);
        $this->assertSame('0.00', number_format((float) data_get($repair->logistics_payment_reconciliation, 'service_amount_applied'), 2, '.', ''));
        $this->assertSame('500.00', number_format((float) data_get($repair->logistics_payment_reconciliation, 'duplicate_service_amount'), 2, '.', ''));
    }

    public function test_pos_rejects_a_phase_already_settled_by_paymongo(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('deposit_50', 1000);
        $due = 500 + (float) $repair->intake_delivery_fee;
        $repair->update([
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => $due,
            'intake_logistics_locked_at' => now(),
        ]);
        RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_paymongo_already_paid',
            'phase' => 'initial',
            'status' => 'paid',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
            'resolved_at' => now(),
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-paymongo-then-pos-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => $due]],
        ])->assertUnprocessable()->assertJsonValidationErrors('due_type');

        $this->assertSame(0, PosTransaction::query()->where('module_reference_id', $repair->id)->count());
        $this->assertSame(number_format($due, 2, '.', ''), number_format((float) $repair->fresh()->total_paid_amount, 2, '.', ''));
    }

    public function test_balance_cannot_settle_before_the_initial_phase_is_paid(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['status' => 'ready_for_pickup']);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-balance-before-initial-001',
            'payment_lines' => [[
                'tender_type' => 'cash',
                'amount' => 500 + (float) $repair->return_delivery_fee,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('due_type');

        $this->assertSame(0, PosTransaction::query()->where('module_reference_id', $repair->id)->count());
        $this->assertSame('pending', $repair->fresh()->payment_status);
    }

    public function test_paymongo_session_is_not_persisted_when_pos_settles_during_provider_call(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('deposit_50', 1000);
        $due = 500 + (float) $repair->intake_delivery_fee;

        Http::fake(function () use ($repair, $customer, $actor, $due) {
            app(RepairPosPaymentService::class)->checkout($repair->fresh(), [
                'repair_request_id' => $repair->id,
                'due_type' => 'deposit',
                'customer_type' => 'registered',
                'customer_id' => $customer->id,
                'idempotency_key' => 'repair-pos-wins-provider-race-001',
                'payment_lines' => [['tender_type' => 'cash', 'amount' => $due]],
            ], $actor->id);

            return Http::response([
                'data' => [
                    'id' => 'cs_lost_to_pos_race',
                    'attributes' => ['checkout_url' => 'https://checkout.test/lost-race'],
                ],
            ]);
        });

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/retry-payment-session")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertDatabaseMissing('repair_payment_sessions', [
            'provider_link_id' => 'cs_lost_to_pos_race',
        ]);
        $this->assertNull($repair->fresh()->paymongo_link_id);
        $this->assertSame(1, PosTransaction::query()->where('module_reference_id', $repair->id)->count());
    }

    public function test_changed_service_price_reconciles_a_persisted_session_instead_of_settling_the_old_amount(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['paymongo_link_id' => 'cs_stale_service_amount']);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_stale_service_amount',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => [
                ...$repair->intake_logistics_quote,
                'payment_policy' => 'deposit_50',
                'service_base_amount' => 500,
                'tax_mode' => 'vat_inclusive',
            ],
        ]);
        $repair->update([
            'total' => 1200,
            'final_total' => 1200,
            'pricing_breakdown' => [
                'mode' => 'services',
                'base_total' => 1200,
                'final_total' => 1200,
                'tax_mode' => 'vat_inclusive',
            ],
        ]);

        $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload(
            'cs_stale_service_amount',
            'pay_stale_service_amount',
        ))->assertOk()->assertJsonPath('message', 'Repair payment requires reconciliation');

        $this->assertSame('reconciliation', $session->fresh()->status);
        $repair->refresh();
        $this->assertSame('pending', data_get($repair->logistics_payment_reconciliation, 'status'));
        $this->assertSame('payment_plan_changed', data_get($repair->logistics_payment_reconciliation, 'reason'));
        $this->assertNotSame('completed', $repair->payment_status);

        $reconciliation = $repair->logistics_payment_reconciliation;
        $reconciliation['status'] = 'resolved';
        $repair->update([
            'status' => 'ready_for_pickup',
            'logistics_payment_reconciliation' => $reconciliation,
        ]);
        $finalServiceDue = 700.0;

        $this->actingAs(User::factory()->create(['shop_owner_id' => $repair->shop_owner_id]), 'user')
            ->postJson('/api/repair-pos/checkout', [
                'repair_request_id' => $repair->id,
                'due_type' => 'balance',
                'customer_type' => 'registered',
                'customer_id' => $repair->user_id,
                'idempotency_key' => 'repair-stale-price-final-001',
                'payment_lines' => [[
                    'tender_type' => 'cash',
                    'amount' => $finalServiceDue + (float) $repair->return_delivery_fee,
                ]],
            ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', $repair->payment_status);
        $this->assertSame(
            number_format(1200 + (float) $repair->return_delivery_fee, 2, '.', ''),
            number_format((float) $repair->total_paid_amount, 2, '.', ''),
        );
    }

    public function test_multiple_late_paid_sessions_preserve_every_reconciliation_entry(): void
    {
        config()->set('services.paymongo.webhook_secret', '');
        [$repair] = $this->coveredRepair('deposit_50', 1000);
        $fee = (float) $repair->intake_delivery_fee;

        foreach (['cs_late_reconciliation_one', 'cs_late_reconciliation_two'] as $linkId) {
            RepairPaymentSession::create([
                'repair_request_id' => $repair->id,
                'provider' => 'paymongo',
                'provider_link_id' => $linkId,
                'phase' => 'initial',
                'status' => 'invalidated',
                'snapshot_version' => $repair->intake_address['version'],
                'delivery_method' => 'shop_pickup',
                'service_amount' => 500,
                'delivery_amount' => $fee,
                'quote' => $repair->intake_logistics_quote,
                'invalidated_at' => now(),
            ]);
        }

        $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload(
            'cs_late_reconciliation_one',
            'pay_late_reconciliation_one',
        ))->assertOk();
        $this->postJson('/api/webhooks/paymongo', $this->paidWebhookPayload(
            'cs_late_reconciliation_two',
            'pay_late_reconciliation_two',
        ))->assertOk();

        $reconciliation = $repair->fresh()->logistics_payment_reconciliation;
        $this->assertCount(2, data_get($reconciliation, 'entries', []));
        $this->assertEqualsCanonicalizing(
            ['pay_late_reconciliation_one', 'pay_late_reconciliation_two'],
            collect(data_get($reconciliation, 'entries', []))->pluck('payment_id')->all(),
        );
        $this->assertSame(
            number_format(500 + ($fee * 2), 2, '.', ''),
            number_format((float) data_get($reconciliation, 'total_reconciliation_amount'), 2, '.', ''),
        );
    }

    public function test_direct_verification_reports_reconciliation_instead_of_payment_verified(): void
    {
        [$repair, $customer] = $this->coveredRepair('deposit_50', 1000);
        $repair->update(['paymongo_link_id' => 'cs_verify_reconciliation']);
        RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'cs_verify_reconciliation',
            'phase' => 'initial',
            'status' => 'invalidated',
            'snapshot_version' => $repair->intake_address['version'],
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500,
            'delivery_amount' => $repair->intake_delivery_fee,
            'quote' => $repair->intake_logistics_quote,
            'invalidated_at' => now(),
        ]);
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions/*' => Http::response([
                'data' => [
                    'id' => 'cs_verify_reconciliation',
                    'attributes' => [
                        'payment_status' => 'paid',
                        'payments' => [[
                            'id' => 'pay_verify_reconciliation',
                            'attributes' => ['status' => 'paid'],
                        ]],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/verify-payment")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('payment_verified', false)
            ->assertJsonPath('requires_reconciliation', true);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/verify-payment")
            ->assertStatus(409)
            ->assertJsonPath('requires_reconciliation', true);
    }

    public function test_pos_is_blocked_while_payment_reconciliation_is_pending(): void
    {
        [$repair, $customer, $actor] = $this->coveredRepair('deposit_50', 1000);
        $repair->update([
            'status' => 'ready_for_pickup',
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 500 + (float) $repair->intake_delivery_fee,
            'intake_logistics_locked_at' => now(),
            'logistics_payment_reconciliation' => [
                'status' => 'pending',
                'reason' => 'invalidated_session',
                'entries' => [['payment_session_id' => 999, 'reconciliation_amount' => 100]],
            ],
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'repair-pos-during-reconciliation-001',
            'payment_lines' => [[
                'tender_type' => 'cash',
                'amount' => 500 + (float) $repair->return_delivery_fee,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->assertSame(0, PosTransaction::query()->where('module_reference_id', $repair->id)->count());
        $this->assertSame('paid', $repair->fresh()->payment_status);
    }

    private function coveredRepair(string $policy, float $serviceTotal): array
    {
        $customer = User::factory()->create();
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
            'paymongo_secret_key' => 'sk_test_repair_logistics',
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 12,
        ]);
        $actor = User::factory()->create(['shop_owner_id' => $shop->id]);
        $address = UserAddress::create([
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
        ]);

        $delivery = app(RepairDeliveryService::class);
        $quote = $delivery->quote($shop, $address);

        $repair = RepairRequest::create([
            'request_id' => 'REP-LOGISTICS-PAYMENT-'.strtoupper($policy),
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'description' => 'Repair logistics payment allocation test',
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'images' => [],
            'total' => $serviceTotal,
            'final_total' => $serviceTotal,
            'pricing_breakdown' => [
                'mode' => 'services',
                'base_total' => $serviceTotal,
                'final_total' => $serviceTotal,
                'tax_mode' => 'vat_inclusive',
            ],
            'status' => 'pending',
            'payment_enabled' => true,
            'payment_policy' => $policy,
            'payment_policy_snapshot' => $policy,
            'payment_status' => 'pending',
            'intake_delivery_method' => 'shop_pickup',
            'return_delivery_method' => 'shop_delivery',
            'intake_address' => $delivery->snapshot($address, 'shop_pickup'),
            'return_address' => $delivery->snapshot($address, 'shop_delivery'),
            'intake_delivery_fee' => $quote['fee'],
            'return_delivery_fee' => $quote['fee'],
            'intake_logistics_quote' => $quote,
            'return_logistics_quote' => $quote,
        ]);

        return [$repair, $customer, $actor];
    }

    private function paidWebhookPayload(string $linkId, string $paymentId): array
    {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'link.payment.paid',
                    'data' => [
                        'id' => $paymentId,
                        'attributes' => [
                            'payment_link_id' => $linkId,
                            'amount' => 1,
                        ],
                    ],
                ],
            ],
        ];
    }
}
