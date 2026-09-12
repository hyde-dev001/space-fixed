<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Services\PremiumSubscriptionRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PremiumBillingLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_checkout_creates_one_deterministic_pending_ledger_row(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-initial', 249);
        config()->set('services.paymongo.secret_key', 'sk_test_ledger');
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_ledger_initial',
                'attributes' => ['checkout_url' => 'https://paymongo.test/cs_ledger_initial'],
            ],
        ])]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/checkout', ['plan_code' => $plan->plan_code])
            ->assertOk();

        $subscription = ShopOwnerSubscription::query()->sole();
        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $subscription->id)->sole();

        $this->assertSame('pending', $payment->status);
        $this->assertSame('new_subscription', $payment->payment_type);
        $this->assertSame('PHP', $payment->currency);
        $this->assertSame('249.00', (string) $payment->amount_due);
        $this->assertSame('cs_ledger_initial', $payment->paymongo_session_id);
        $this->assertSame("subscription:{$subscription->id}:new_subscription", $payment->ledger_key);
        $this->assertSame((string) $payment->id, (string) data_get($payment->metadata, 'payment_record_id'));
        $this->assertSame($payment->ledger_key, data_get($payment->metadata, 'ledger_key'));
    }

    public function test_initial_checkout_provider_exception_fails_both_local_pending_rows(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-initial-exception', 249);
        Http::fake(function (): never {
            throw new ConnectionException('provider secret must not escape');
        });

        $response = $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/checkout', ['plan_code' => $plan->plan_code]);

        $response->assertStatus(502)
            ->assertJson(['success' => false]);
        $subscription = ShopOwnerSubscription::query()->sole();
        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $subscription->id)->sole();
        $this->assertSame('failed', $subscription->status);
        $this->assertSame('failed', $payment->status);
        $this->assertStringNotContainsString('provider secret must not escape', (string) $response->getContent());
    }

    public function test_renewal_checkout_creates_one_pending_renewal_ledger_row(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-renewal', 399);
        $source = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
            'starts_at' => now()->subDays(29),
            'ends_at' => now()->addDay(),
        ]);
        config()->set('services.paymongo.secret_key', 'sk_test_renewal');
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_ledger_renewal',
                'attributes' => ['checkout_url' => 'https://paymongo.test/cs_ledger_renewal'],
            ],
        ])]);

        $result = app(PremiumSubscriptionRenewalService::class)->createRenewalCheckout($source);

        $this->assertTrue($result['success']);
        $renewal = ShopOwnerSubscription::query()->where('renewal_of_subscription_id', $source->id)->sole();
        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $renewal->id)->sole();

        $this->assertSame('renewal', $payment->payment_type);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('399.00', (string) $payment->amount_due);
        $this->assertSame("subscription:{$renewal->id}:renewal", $payment->ledger_key);
        $this->assertSame((string) $payment->id, (string) data_get($payment->metadata, 'payment_record_id'));
        $this->assertSame('cs_ledger_renewal', $payment->paymongo_session_id);
    }

    public function test_renewal_provider_exception_fails_both_new_rows_without_exposing_provider_error(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-renewal-exception', 399);
        $source = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
            'starts_at' => now()->subDays(29),
            'ends_at' => now()->addDay(),
        ]);
        Http::fake(function (): never {
            throw new ConnectionException('renewal provider secret must not escape');
        });

        $result = app(PremiumSubscriptionRenewalService::class)->createRenewalCheckout($source);

        $this->assertFalse($result['success']);
        $renewal = ShopOwnerSubscription::query()->where('renewal_of_subscription_id', $source->id)->sole();
        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $renewal->id)->sole();
        $this->assertSame('failed', $renewal->status);
        $this->assertSame('failed', $payment->status);
        $this->assertStringNotContainsString('renewal provider secret must not escape', json_encode($result));
    }

    public function test_paid_and_failed_webhooks_finalize_their_existing_ledger_rows(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-webhooks', 249);
        $paidSubscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_paid');
        $paidPayment = $this->createPayment($owner, $paidSubscription, 'new_subscription', 249, 'cs_ledger_paid');

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_paid',
            subscription: $paidSubscription,
            paymentId: 'pay_ledger_paid',
            paymentRecordId: $paidPayment->id,
            amountInCentavos: 24900,
        ))->assertOk();

        $this->assertSame('active', $paidSubscription->fresh()->status);
        $this->assertSame('paid', $paidPayment->fresh()->status);
        $this->assertSame('pay_ledger_paid', $paidPayment->fresh()->paymongo_payment_id);

        $failedSubscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_failed');
        $failedPayment = $this->createPayment($owner, $failedSubscription, 'new_subscription', 249, 'cs_ledger_failed');

        $this->postJson('/api/webhooks/paymongo', $this->checkoutFailedPayload(
            sessionId: 'cs_ledger_failed',
            subscription: $failedSubscription,
            paymentRecordId: $failedPayment->id,
        ))->assertOk();

        $this->assertSame('failed', $failedSubscription->fresh()->status);
        $this->assertSame('failed', $failedPayment->fresh()->status);
    }

    public function test_paid_webhook_rejects_metadata_bound_to_a_different_subscription(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-metadata-binding', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_metadata_binding');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_metadata_binding');

        $payload = $this->checkoutPayload(
            sessionId: 'cs_ledger_metadata_binding',
            subscription: $subscription,
            paymentId: 'pay_ledger_metadata_binding',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        );
        $payload['data']['attributes']['data']['attributes']['metadata']['subscription_id'] = '999999';

        $this->postJson('/api/webhooks/paymongo', $payload)->assertOk();

        $this->assertSame('pending', $subscription->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paymongo_payment_id);
    }

    public function test_paid_webhook_requires_provider_payment_identity_and_currency(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-paid-identity', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_paid_identity');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_paid_identity');

        $payload = $this->checkoutPayload(
            sessionId: 'cs_ledger_paid_identity',
            subscription: $subscription,
            paymentId: 'pay_ledger_paid_identity',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        );
        unset($payload['data']['attributes']['data']['attributes']['payments'][0]['id']);
        $payload['data']['attributes']['data']['attributes']['payments'][0]['attributes']['currency'] = '';

        $this->postJson('/api/webhooks/paymongo', $payload)->assertOk();

        $this->assertSame('pending', $subscription->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paymongo_payment_id);
    }

    public function test_paid_webhook_rejects_a_payment_record_bound_to_another_shop_owner(): void
    {
        $owner = $this->createOwner();
        $otherOwner = $this->createOwner();
        $plan = $this->createPlan('ledger-owner-binding', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_owner_binding');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_owner_binding');
        $payment->update(['shop_owner_id' => $otherOwner->id]);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_owner_binding',
            subscription: $subscription,
            paymentId: 'pay_ledger_owner_binding',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        ))->assertOk();

        $this->assertSame('pending', $subscription->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paymongo_payment_id);
    }

    public function test_charged_upgrade_uses_one_deterministic_upgrade_ledger_row(): void
    {
        $owner = $this->createOwner();
        $currentPlan = $this->createPlan('ledger-upgrade-current', 249);
        $targetPlan = $this->createPlan('ledger-upgrade-target', 499);
        $current = $this->createActiveSubscription($owner, $currentPlan, [
            'ends_at' => now()->addDays(10),
        ]);
        config()->set('services.paymongo.secret_key', 'sk_test_upgrade');
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_ledger_upgrade',
                'attributes' => ['checkout_url' => 'https://paymongo.test/cs_ledger_upgrade'],
            ],
        ])]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/confirm-upgrade', [
                'new_plan_id' => $targetPlan->id,
            ])
            ->assertOk();

        $pending = ShopOwnerSubscription::query()
            ->where('replaces_subscription_id', $current->id)
            ->where('status', 'pending')
            ->sole();
        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $pending->id)->sole();

        $this->assertSame('upgrade', $payment->payment_type);
        $this->assertSame('pending', $payment->status);
        $this->assertGreaterThan(0, (float) $payment->amount_due);
        $this->assertSame("subscription:{$pending->id}:upgrade", $payment->ledger_key);
        $this->assertSame((string) $payment->id, (string) data_get($payment->metadata, 'payment_record_id'));
    }

    public function test_zero_charge_upgrade_is_an_explicit_settled_zero_value_ledger_event(): void
    {
        $owner = $this->createOwner();
        $currentPlan = $this->createPlan('ledger-zero-current', 249);
        $targetPlan = $this->createPlan('ledger-zero-target', 400);
        $this->createActiveSubscription($owner, $currentPlan, [
            'ends_at' => now()->addDays(60),
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/confirm-upgrade', [
                'new_plan_id' => $targetPlan->id,
            ])
            ->assertOk()
            ->assertJsonPath('payment_required', false);

        $payment = ShopOwnerSubscriptionPayment::query()->where('payment_type', 'upgrade')->sole();

        $this->assertSame('paid', $payment->status);
        $this->assertSame('0.00', (string) $payment->amount_due);
        $this->assertSame('0.00', (string) $payment->amount_paid);
        $this->assertSame("subscription:{$payment->subscription_id}:upgrade", $payment->ledger_key);
    }

    public function test_late_paid_webhook_cannot_reactivate_a_failed_checkout(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-late-webhook', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_late');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_late', 'failed');
        $subscription->update(['status' => 'failed']);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_late',
            subscription: $subscription,
            paymentId: 'pay_ledger_late',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        ))->assertOk();

        $this->assertSame('failed', $subscription->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paymongo_payment_id);
    }

    public function test_shop_owner_cannot_cancel_an_unpaid_pending_checkout_as_paid_cancellation(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-pending-cancel', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_pending_cancel');

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/cancel', [
                'subscription_id' => $subscription->id,
                'cancellation_reason' => 'abandoned checkout',
            ])
            ->assertStatus(409);

        $this->assertSame('pending', $subscription->fresh()->status);
    }

    private function createOwner(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);
    }

    private function createPlan(string $code, float $price): PremiumPlan
    {
        return PremiumPlan::query()->create([
            'plan_code' => $code,
            'name' => ucfirst($code),
            'description' => 'Ledger test plan',
            'price' => $price,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
    }

    private function createPendingSubscription(ShopOwner $owner, PremiumPlan $plan, string $sessionId): ShopOwnerSubscription
    {
        return ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'pending',
            'paymongo_session_id' => $sessionId,
            'paid_amount' => $plan->price,
        ]);
    }

    private function createActiveSubscription(ShopOwner $owner, PremiumPlan $plan, array $overrides = []): ShopOwnerSubscription
    {
        return ShopOwnerSubscription::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status' => 'active',
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ], $overrides));
    }

    private function createPayment(
        ShopOwner $owner,
        ShopOwnerSubscription $subscription,
        string $paymentType,
        float $amount,
        string $sessionId,
        string $status = 'pending',
    ): ShopOwnerSubscriptionPayment {
        return ShopOwnerSubscriptionPayment::query()->create([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => $paymentType,
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_session_id' => $sessionId,
            'plan_price' => $amount,
            'amount_due' => $amount,
            'amount_paid' => $status === 'paid' ? $amount : null,
            'status' => $status,
            'metadata' => ['payment_record_id' => null],
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    private function checkoutPayload(
        string $sessionId,
        ShopOwnerSubscription $subscription,
        string $paymentId,
        int $paymentRecordId,
        int $amountInCentavos,
    ): array {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $sessionId,
                        'attributes' => [
                            'metadata' => [
                                'subscription_id' => (string) $subscription->id,
                                'shop_owner_id' => (string) $subscription->shop_owner_id,
                                'plan_code' => $subscription->plan_code,
                                'payment_record_id' => (string) $paymentRecordId,
                            ],
                            'payments' => [[
                                'id' => $paymentId,
                                'attributes' => ['amount' => $amountInCentavos, 'currency' => 'PHP'],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function checkoutFailedPayload(
        string $sessionId,
        ShopOwnerSubscription $subscription,
        int $paymentRecordId,
    ): array {
        return [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.failed',
                    'data' => [
                        'id' => $sessionId,
                        'attributes' => [
                            'metadata' => [
                                'subscription_id' => (string) $subscription->id,
                                'shop_owner_id' => (string) $subscription->shop_owner_id,
                                'plan_code' => $subscription->plan_code,
                                'payment_record_id' => (string) $paymentRecordId,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
