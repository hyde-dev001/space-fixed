<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\SuperAdmin;
use App\Services\PremiumSubscriptionRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PremiumBillingLedgerTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_premium_success_return_rejects_unsigned_requests(): void
    {
        $this->get(route('shop-owner.premium-success-return', ['subscription_id' => 1]))
            ->assertForbidden();
    }

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

    public function test_paid_renewal_webhook_settles_its_child_ledger_without_ending_the_source(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-paid-renewal', 399);
        $source = $this->createActiveSubscription($owner, $plan, [
            'ends_at' => now()->addDay(),
        ]);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_ledger_paid_renewal',
                'attributes' => ['checkout_url' => 'https://paymongo.test/cs_ledger_paid_renewal'],
            ],
        ])]);

        $result = app(PremiumSubscriptionRenewalService::class)->createRenewalCheckout($source);
        $renewal = $result['renewal_subscription'];
        $payment = ShopOwnerSubscriptionPayment::query()->where('subscription_id', $renewal->id)->sole();

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_paid_renewal',
            subscription: $renewal,
            paymentId: 'pay_ledger_paid_renewal',
            paymentRecordId: $payment->id,
            amountInCentavos: 39900,
        ))->assertOk();

        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('active', $renewal->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('399.00', (string) $payment->fresh()->amount_paid);
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

    public function test_paid_webhook_settles_and_failed_attempt_leaves_the_checkout_retryable(): void
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
        $this->assertSame('249.00', (string) $paidPayment->fresh()->amount_paid);

        $failedSubscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_failed');
        $failedPayment = $this->createPayment($owner, $failedSubscription, 'new_subscription', 249, 'cs_ledger_failed');

        $this->postJson('/api/webhooks/paymongo', $this->checkoutFailedPayload(
            sessionId: 'cs_ledger_failed',
            subscription: $failedSubscription,
            paymentRecordId: $failedPayment->id,
        ))->assertOk();

        $this->assertSame('pending', $failedSubscription->fresh()->status);
        $this->assertSame('pending', $failedPayment->fresh()->status);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_failed',
            subscription: $failedSubscription,
            paymentId: 'pay_ledger_retry_succeeded',
            paymentRecordId: $failedPayment->id,
            amountInCentavos: 24900,
            paymentAttempts: [
                ['id' => 'pay_ledger_retry_failed', 'attributes' => [
                    'status' => 'failed', 'amount' => 24900, 'currency' => 'PHP',
                ]],
                ['id' => 'pay_ledger_retry_succeeded', 'attributes' => [
                    'status' => 'paid', 'amount' => 24900, 'currency' => 'PHP',
                ]],
            ],
        ))->assertOk();

        $this->assertSame('active', $failedSubscription->fresh()->status);
        $this->assertSame('paid', $failedPayment->fresh()->status);
        $this->assertSame('pay_ledger_retry_succeeded', $failedPayment->fresh()->paymongo_payment_id);
    }

    public function test_paid_webhook_repairs_an_active_subscription_with_pending_ledger(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-active-webhook-repair', 249);
        $subscription = $this->createActiveSubscription($owner, $plan, [
            'paymongo_session_id' => 'cs_ledger_active_webhook_repair',
            'paymongo_payment_id' => 'pay_ledger_active_webhook_repair',
            'paid_amount' => 0,
        ]);
        $startsAt = $subscription->starts_at;
        $endsAt = $subscription->ends_at;
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_active_webhook_repair');
        $mismatchedPayload = $this->checkoutPayload(
            sessionId: 'cs_ledger_active_webhook_repair',
            subscription: $subscription,
            paymentId: 'pay_different_payment',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        );
        $payload = $this->checkoutPayload(
            sessionId: 'cs_ledger_active_webhook_repair',
            subscription: $subscription,
            paymentId: 'pay_ledger_active_webhook_repair',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        );

        $this->postJson('/api/webhooks/paymongo', $mismatchedPayload)->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('0.00', (string) $subscription->fresh()->paid_amount);

        $this->postJson('/api/webhooks/paymongo', $payload)->assertOk();

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('249.00', (string) $subscription->fresh()->paid_amount);
        $this->assertEquals($startsAt, $subscription->fresh()->starts_at);
        $this->assertEquals($endsAt, $subscription->fresh()->ends_at);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_ledger_active_webhook_repair', $payment->fresh()->paymongo_payment_id);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
    }

    public function test_paid_checkout_return_settles_the_subscription_and_payment_ledger(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-success-return', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_success_return');
        $subscription->update(['paid_amount' => 0]);
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_success_return');
        $this->fakeCheckoutSession('cs_ledger_success_return', 'pay_ledger_success_return', 'paid');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-success', ['subscription_id' => $subscription->id]))
            ->assertRedirect(route('shop-owner.premium-benefits'));

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('249.00', (string) $subscription->fresh()->paid_amount);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_ledger_success_return', $payment->fresh()->paymongo_payment_id);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_checkout_return_does_not_activate_for_a_pending_payment_entry(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-pending-return', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_pending_return');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_pending_return');
        $this->fakeCheckoutSession('cs_ledger_pending_return', 'pay_ledger_pending_return', 'pending');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-success', ['subscription_id' => $subscription->id]));

        $this->assertSame('pending', $subscription->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->amount_paid);
        $this->assertNull($payment->fresh()->paymongo_payment_id);
    }

    public function test_paid_checkout_return_repairs_an_active_subscription_with_pending_ledger(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-active-repair', 249);
        $subscription = $this->createActiveSubscription($owner, $plan, [
            'paymongo_session_id' => 'cs_ledger_active_repair',
            'paid_amount' => 0,
        ]);
        $startsAt = $subscription->starts_at;
        $endsAt = $subscription->ends_at;
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_active_repair');
        $this->fakeCheckoutSession('cs_ledger_active_repair', 'pay_ledger_active_repair', 'paid');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-success', ['subscription_id' => $subscription->id]));

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('249.00', (string) $subscription->fresh()->paid_amount);
        $this->assertEquals($startsAt, $subscription->fresh()->starts_at);
        $this->assertEquals($endsAt, $subscription->fresh()->ends_at);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
    }

    public function test_checkout_return_rejects_amount_or_currency_mismatches(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-mismatch-return', 249);

        foreach ([
            ['cs_ledger_wrong_amount', 'pay_ledger_wrong_amount', 24800, 'PHP'],
            ['cs_ledger_wrong_currency', 'pay_ledger_wrong_currency', 24900, 'USD'],
        ] as [$sessionId, $providerPaymentId, $amountInCentavos, $currency]) {
            $subscription = $this->createPendingSubscription($owner, $plan, $sessionId);
            $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, $sessionId);
            $this->fakeCheckoutSession($sessionId, $providerPaymentId, 'paid', $amountInCentavos, $currency);

            $this->actingAs($owner, 'shop_owner')
                ->get(route('shop-owner.premium-success', ['subscription_id' => $subscription->id]));

            $this->assertSame('pending', $subscription->fresh()->status);
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->amount_paid);
        }
    }

    public function test_pending_payment_reconciliation_is_dry_run_by_default_and_can_apply_a_verified_payment(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-reconciliation', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_reconciliation');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_reconciliation');
        $this->fakeCheckoutSession('cs_ledger_reconciliation', 'pay_ledger_reconciliation', 'paid');

        $this->artisan('premium-payments:reconcile-pending')
            ->expectsOutputToContain('would_settle')
            ->assertExitCode(0);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $subscription->fresh()->status);

        $this->artisan('premium-payments:reconcile-pending', ['--apply' => true])
            ->expectsOutputToContain('settled')
            ->assertExitCode(0);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('pay_ledger_reconciliation', $payment->fresh()->paymongo_payment_id);

        $unpaidSubscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_reconciliation_unpaid');
        $unpaidPayment = $this->createPayment($owner, $unpaidSubscription, 'new_subscription', 249, 'cs_ledger_reconciliation_unpaid');
        $this->fakeCheckoutSession('cs_ledger_reconciliation_unpaid', 'pay_ledger_reconciliation_unpaid', 'pending');

        $this->artisan('premium-payments:reconcile-pending', ['--apply' => true])
            ->expectsOutputToContain('unpaid')
            ->assertExitCode(0);
        $this->assertSame('pending', $unpaidPayment->fresh()->status);
        $this->assertSame('pending', $unpaidSubscription->fresh()->status);
    }

    public function test_duplicate_webhook_after_success_return_does_not_double_collect_or_reset_period(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-return-webhook-order', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_return_webhook_order');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_return_webhook_order');
        $this->fakeCheckoutSession('cs_ledger_return_webhook_order', 'pay_ledger_return_webhook_order', 'paid');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-success', ['subscription_id' => $subscription->id]));
        $settledStart = $subscription->fresh()->starts_at;
        $settledEnd = $subscription->fresh()->ends_at;

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_return_webhook_order',
            subscription: $subscription,
            paymentId: 'pay_ledger_return_webhook_order',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
        ))->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
        $this->assertEquals($settledStart, $subscription->fresh()->starts_at);
        $this->assertEquals($settledEnd, $subscription->fresh()->ends_at);
    }

    public function test_success_return_after_webhook_uses_the_paid_retry_attempt_and_is_idempotent(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-webhook-return-order', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_webhook_return_order');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_webhook_return_order');
        $attempts = [
            ['id' => 'pay_ledger_first_failed', 'attributes' => [
                'status' => 'failed', 'amount' => 24900, 'currency' => 'PHP',
            ]],
            ['id' => 'pay_ledger_retry_paid', 'attributes' => [
                'status' => 'paid', 'amount' => 24900, 'currency' => 'PHP',
            ]],
        ];
        $this->fakeCheckoutSession(
            'cs_ledger_webhook_return_order',
            'pay_ledger_retry_paid',
            'paid',
            paymentAttempts: $attempts,
        );

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_webhook_return_order',
            subscription: $subscription,
            paymentId: 'pay_ledger_retry_paid',
            paymentRecordId: $payment->id,
            amountInCentavos: 24900,
            paymentAttempts: $attempts,
        ))->assertOk();
        $settledEnd = $subscription->fresh()->ends_at;

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-success', ['subscription_id' => $subscription->id]))
            ->assertRedirect(route('shop-owner.premium-benefits'));

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_ledger_retry_paid', $payment->fresh()->paymongo_payment_id);
        $this->assertEquals($settledEnd, $subscription->fresh()->ends_at);
    }

    public function test_browser_cancel_return_does_not_expire_a_retryable_checkout(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-browser-cancel', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_browser_cancel');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_browser_cancel');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-cancel', ['subscription_id' => $subscription->id]))
            ->assertRedirect(route('shop-owner.premium-benefits'));

        $this->assertSame('pending', $subscription->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_paymongo_checkout_expiration_is_still_terminal(): void
    {
        $owner = $this->createOwner();
        $plan = $this->createPlan('ledger-expired-checkout', 249);
        $subscription = $this->createPendingSubscription($owner, $plan, 'cs_ledger_expired_checkout');
        $payment = $this->createPayment($owner, $subscription, 'new_subscription', 249, 'cs_ledger_expired_checkout');

        $this->postJson('/api/webhooks/paymongo', $this->checkoutFailedPayload(
            sessionId: 'cs_ledger_expired_checkout',
            subscription: $subscription,
            paymentRecordId: $payment->id,
            eventType: 'checkout_session.expired',
        ))->assertOk();

        $this->assertSame('failed', $subscription->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
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

    public function test_repeated_pending_upgrade_reuses_the_same_checkout_and_blocks_a_different_target(): void
    {
        $owner = $this->createOwner();
        $currentPlan = $this->createPlan('ledger-upgrade-reuse-current', 249);
        $targetPlan = $this->createPlan('ledger-upgrade-reuse-target', 499);
        $otherPlan = $this->createPlan('ledger-upgrade-reuse-other', 699);
        $current = $this->createActiveSubscription($owner, $currentPlan, [
            'ends_at' => now()->addDays(10),
        ]);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_ledger_upgrade_reused',
                'attributes' => ['checkout_url' => 'https://paymongo.test/cs_ledger_upgrade_reused'],
            ],
        ])]);

        $first = $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/confirm-upgrade', ['new_plan_id' => $targetPlan->id])
            ->assertOk();
        $second = $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/confirm-upgrade', ['new_plan_id' => $targetPlan->id])
            ->assertOk();

        $this->assertSame($first->json('session_id'), $second->json('session_id'));
        $this->assertSame($first->json('checkout_url'), $second->json('checkout_url'));
        $this->assertSame(1, ShopOwnerSubscription::query()->where('replaces_subscription_id', $current->id)->count());
        $this->assertSame(1, ShopOwnerSubscriptionPayment::query()->where('payment_type', 'upgrade')->count());
        Http::assertSentCount(1);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/confirm-upgrade', ['new_plan_id' => $otherPlan->id])
            ->assertStatus(409);
    }

    public function test_paid_upgrade_webhook_then_success_return_is_idempotent_and_uses_the_locked_quote(): void
    {
        $paidAt = now()->startOfSecond();
        $this->travelTo($paidAt);
        $owner = $this->createOwner();
        $currentPlan = $this->createPlan('ledger-upgrade-settle-current', 249);
        $targetPlan = $this->createPlan('ledger-upgrade-settle-target', 499);
        $source = $this->createActiveSubscription($owner, $currentPlan, [
            'ends_at' => now()->addDays(10),
        ]);
        $upgrade = $this->createPendingSubscription($owner, $targetPlan, 'cs_ledger_upgrade_settle');
        $upgrade->update(['replaces_subscription_id' => $source->id]);
        $payment = $this->createPayment($owner, $upgrade, 'upgrade', 250, 'cs_ledger_upgrade_settle');
        $payment->update([
            'source_subscription_id' => $source->id,
            'from_premium_plan_id' => $currentPlan->id,
            'to_premium_plan_id' => $targetPlan->id,
            'metadata' => [
                'payment_record_id' => (string) $payment->id,
                'ledger_key' => $payment->ledger_key,
                'source_subscription_id' => (string) $source->id,
            ],
        ]);
        $targetPlan->update(['price' => 899]);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_ledger_upgrade_settle',
            subscription: $upgrade,
            paymentId: 'pay_ledger_upgrade_settle',
            paymentRecordId: $payment->id,
            amountInCentavos: 25000,
        ))->assertOk();

        $this->assertSame('cancelled', $source->fresh()->status);
        $this->assertSame('active', $upgrade->fresh()->status);
        $this->assertEquals($paidAt, $upgrade->fresh()->starts_at);
        $this->assertEquals($paidAt->copy()->addDays(30), $upgrade->fresh()->ends_at);
        $this->assertSame('250.00', (string) $payment->fresh()->amount_due);
        $this->assertSame('250.00', (string) $payment->fresh()->amount_paid);
        $this->assertSame('pay_ledger_upgrade_settle', $payment->fresh()->paymongo_payment_id);

        $settledStartsAt = $upgrade->fresh()->starts_at;
        $settledEndsAt = $upgrade->fresh()->ends_at;
        $this->fakeCheckoutSession(
            'cs_ledger_upgrade_settle',
            'pay_ledger_upgrade_settle',
            'paid',
            amountInCentavos: 25000,
        );
        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.premium-success', ['subscription_id' => $upgrade->id]))
            ->assertRedirect(route('shop-owner.premium-benefits'));

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('cancelled', $source->fresh()->status);
        $this->assertEquals($settledStartsAt, $upgrade->fresh()->starts_at);
        $this->assertEquals($settledEndsAt, $upgrade->fresh()->ends_at);
        $this->assertSame('250.00', (string) $payment->fresh()->amount_paid);
    }

    public function test_paid_upgrade_success_return_settles_the_checkout_once_and_updates_admin_reporting(): void
    {
        $paidAt = now()->startOfSecond();
        $this->travelTo($paidAt);
        $owner = $this->createOwner();
        $currentPlan = $this->createPlan('basic', 249);
        $currentPlan->update(['duration_days' => 15, 'showroom_slot_limit' => 48]);
        $targetPlan = $this->createPlan('pro', 500.19);
        $targetPlan->update(['showroom_slot_limit' => 60]);
        $source = $this->createActiveSubscription($owner, $currentPlan, [
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(10),
        ]);
        $providerMetadata = [];
        $providerSuccessUrl = '';
        config()->set('services.paymongo.secret_key', 'sk_test_upgrade_return');
        Http::fake(function ($request) use (&$providerMetadata, &$providerSuccessUrl) {
            if ($request->method() === 'POST') {
                $providerMetadata = $request['data']['attributes']['metadata'];
                $providerSuccessUrl = $request['data']['attributes']['success_url'];

                return Http::response(['data' => [
                    'id' => 'cs_paid_upgrade_return',
                    'attributes' => ['checkout_url' => 'https://paymongo.test/cs_paid_upgrade_return'],
                ]]);
            }

            return Http::response(['data' => [
                'id' => 'cs_paid_upgrade_return',
                'attributes' => [
                    'payment_status' => 'paid',
                    'metadata' => $providerMetadata,
                    'payments' => [
                        ['id' => 'pay_upgrade_first_failed', 'attributes' => [
                            'status' => 'failed', 'amount' => 33419, 'currency' => 'PHP',
                        ]],
                        ['id' => 'pay_upgrade_retry_paid', 'attributes' => [
                            'status' => 'paid', 'amount' => 33419, 'currency' => 'PHP',
                        ]],
                    ],
                ],
            ]]);
        });

        $checkout = $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/premium/confirm-upgrade', ['new_plan_id' => $targetPlan->id])
            ->assertOk()
            ->assertJsonPath('final_price', 334.19);
        $target = ShopOwnerSubscription::query()
            ->where('replaces_subscription_id', $source->id)
            ->where('status', 'pending')
            ->sole();
        $payment = ShopOwnerSubscriptionPayment::query()
            ->where('subscription_id', $target->id)
            ->sole();

        Auth::guard('shop_owner')->logout();
        $this->get($providerSuccessUrl)
            ->assertRedirect(route('shop-owner.login.form'));

        self::assertSame((string) $owner->id, $providerMetadata['shop_owner_id'] ?? null);
        self::assertSame($target->paymongo_session_id, $checkout->json('session_id'));
        self::assertSame('cancelled', $source->fresh()->status);
        self::assertSame('active', $target->fresh()->status);
        self::assertSame('paid', $payment->fresh()->status);
        self::assertSame('334.19', (string) $payment->fresh()->amount_due);
        self::assertSame('334.19', (string) $payment->fresh()->amount_paid);
        self::assertSame('pay_upgrade_retry_paid', $payment->fresh()->paymongo_payment_id);
        self::assertEquals($paidAt, $payment->fresh()->paid_at);
        self::assertEquals($paidAt, $target->fresh()->starts_at);
        self::assertEquals($paidAt->copy()->addDays(30), $target->fresh()->ends_at);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPayload(
            sessionId: 'cs_paid_upgrade_return',
            subscription: $target,
            paymentId: 'pay_upgrade_retry_paid',
            paymentRecordId: $payment->id,
            amountInCentavos: 33419,
            paymentAttempts: [
                ['id' => 'pay_upgrade_first_failed', 'attributes' => [
                    'status' => 'failed', 'amount' => 33419, 'currency' => 'PHP',
                ]],
                ['id' => 'pay_upgrade_retry_paid', 'attributes' => [
                    'status' => 'paid', 'amount' => 33419, 'currency' => 'PHP',
                ]],
            ],
        ))->assertOk();
        $this->actingAs($owner, 'shop_owner')
            ->get($providerSuccessUrl)
            ->assertRedirect(route('shop-owner.premium-benefits'));
        self::assertEquals($paidAt, $payment->fresh()->paid_at);
        self::assertEquals($paidAt, $target->fresh()->starts_at);

        $this->travel(2)->seconds();
        $entitled = ShopOwnerSubscription::query()
            ->where('shop_owner_id', $owner->id)
            ->showroomEntitled()
            ->sole();
        self::assertSame($target->id, $entitled->id);
        self::assertSame(60, (int) $entitled->showroom_slot_limit);

        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.subscriptions.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($target): void {
                $props = $page->toArray()['props'];
                $row = collect($props['subscriptions']['data'])->firstWhere('id', $target->id);

                self::assertSame(334.19, (float) $props['stats']['gross_collected']);
                self::assertSame(334.19, (float) $props['stats']['net_collected']);
                self::assertSame(334.19, (float) $row['amount_paid']);
                self::assertSame('active', $row['status']);
                self::assertNotNull($row['starts_at']);
                self::assertNotNull($row['ends_at']);
                self::assertNotNull($row['next_billing_at']);
            });
        $this->get(route('admin.subscriptions.history', ['subscription' => $target]))
            ->assertOk()
            ->assertJsonPath('payments.data.0.payment_type', 'upgrade')
            ->assertJsonPath('payments.data.0.amount_paid', 334.19)
            ->assertJsonPath('payments.data.0.status', 'paid');
    }

    public function test_reconciliation_settles_legacy_upgrade_metadata_without_provider_owner_id(): void
    {
        $owner = $this->createOwner();
        $sourcePlan = $this->createPlan('legacy-upgrade-basic', 249);
        $targetPlan = $this->createPlan('legacy-upgrade-pro', 500.19);
        $source = $this->createActiveSubscription($owner, $sourcePlan, [
            'ends_at' => now()->addDays(10),
        ]);
        $sessionId = 'cs_legacy_upgrade_without_owner';
        $target = $this->createPendingSubscription($owner, $targetPlan, $sessionId);
        $target->update(['replaces_subscription_id' => $source->id]);
        $payment = $this->createPayment($owner, $target, 'upgrade', 334.19, $sessionId);
        $ledgerKey = ShopOwnerSubscriptionPayment::ledgerKeyFor($target->id, 'upgrade');
        $payment->update([
            'ledger_key' => $ledgerKey,
            'source_subscription_id' => $source->id,
            'from_premium_plan_id' => $sourcePlan->id,
            'to_premium_plan_id' => $targetPlan->id,
        ]);
        $metadata = [
            'type' => 'premium_subscription_upgrade',
            'subscription_id' => (string) $target->id,
            'source_subscription_id' => (string) $source->id,
            'plan_code' => $targetPlan->plan_code,
            'payment_record_id' => (string) $payment->id,
            'ledger_key' => $ledgerKey,
        ];
        config()->set('services.paymongo.secret_key', 'sk_test_legacy_upgrade');
        Http::fake([
            "https://api.paymongo.com/v1/checkout_sessions/{$sessionId}" => Http::response(['data' => [
                'id' => $sessionId,
                'attributes' => [
                    'payment_status' => 'paid',
                    'metadata' => $metadata,
                    'payments' => [[
                        'id' => 'pay_legacy_upgrade_paid',
                        'attributes' => ['status' => 'paid', 'amount' => 33419, 'currency' => 'PHP'],
                    ]],
                ],
            ]]),
        ]);

        $this->artisan('premium-payments:reconcile-pending', [
            '--apply' => true,
            '--shop-owner' => (string) $owner->id,
        ])->expectsOutputToContain('settled')->assertExitCode(0);

        self::assertSame('paid', $payment->fresh()->status);
        self::assertSame('334.19', (string) $payment->fresh()->amount_paid);
        self::assertNotNull($payment->fresh()->paid_at);
        self::assertSame('active', $target->fresh()->status);
        self::assertSame('cancelled', $source->fresh()->status);
        self::assertNotNull($target->fresh()->starts_at);
        self::assertNotNull($target->fresh()->ends_at);
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
        $this->assertNull($subscription->fresh()->starts_at);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_ledger_late', $payment->fresh()->paymongo_payment_id);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
        $this->assertTrue((bool) data_get($payment->fresh()->metadata, 'settlement_review_required'));
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

    private function fakeCheckoutSession(
        string $sessionId,
        string $paymentId,
        string $status,
        int $amountInCentavos = 24900,
        string $currency = 'PHP',
        ?array $paymentAttempts = null,
    ): void {
        config()->set('services.paymongo.secret_key', 'sk_test_ledger');
        $payment = ShopOwnerSubscriptionPayment::query()
            ->where('paymongo_session_id', $sessionId)
            ->firstOrFail();
        $subscription = $payment->subscription;
        $metadata = [
            'type' => match ($payment->payment_type) {
                'upgrade' => 'premium_subscription_upgrade',
                'renewal' => 'premium_subscription_renewal',
                default => 'premium_subscription',
            },
            'payment_record_id' => (string) $payment->id,
            'subscription_id' => (string) $subscription->id,
            'shop_owner_id' => (string) $subscription->shop_owner_id,
            'plan_code' => $subscription->plan_code,
            'ledger_key' => $payment->ledger_key,
        ];
        if ($subscription->replaces_subscription_id) {
            $metadata['source_subscription_id'] = (string) $subscription->replaces_subscription_id;
        }
        if ($subscription->renewal_of_subscription_id) {
            $metadata['renewal_of_subscription_id'] = (string) $subscription->renewal_of_subscription_id;
        }

        Http::fake(["https://api.paymongo.com/v1/checkout_sessions/{$sessionId}" => Http::response([
            'data' => [
                'id' => $sessionId,
                'attributes' => [
                    'metadata' => $metadata,
                    'payment_status' => $status,
                    'payments' => $paymentAttempts ?? [[
                        'id' => $paymentId,
                        'attributes' => [
                            'status' => $status,
                            'amount' => $amountInCentavos,
                            'currency' => $currency,
                        ],
                    ]],
                ],
            ],
        ])]);
    }

    private function checkoutPayload(
        string $sessionId,
        ShopOwnerSubscription $subscription,
        string $paymentId,
        int $paymentRecordId,
        int $amountInCentavos,
        ?array $paymentAttempts = null,
    ): array {
        $payment = ShopOwnerSubscriptionPayment::query()->findOrFail($paymentRecordId);
        $metadata = [
            'type' => match ($payment->payment_type) {
                'upgrade' => 'premium_subscription_upgrade',
                'renewal' => 'premium_subscription_renewal',
                default => 'premium_subscription',
            },
            'subscription_id' => (string) $subscription->id,
            'shop_owner_id' => (string) $subscription->shop_owner_id,
            'plan_code' => $subscription->plan_code,
            'payment_record_id' => (string) $paymentRecordId,
            'ledger_key' => $payment->ledger_key,
        ];
        if ($subscription->replaces_subscription_id) {
            $metadata['source_subscription_id'] = (string) $subscription->replaces_subscription_id;
        }
        if ($subscription->renewal_of_subscription_id) {
            $metadata['renewal_of_subscription_id'] = (string) $subscription->renewal_of_subscription_id;
        }

        return [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $sessionId,
                        'attributes' => [
                            'metadata' => $metadata,
                            'payment_status' => 'paid',
                            'payments' => $paymentAttempts ?? [[
                                'id' => $paymentId,
                                'attributes' => [
                                    'status' => 'paid',
                                    'amount' => $amountInCentavos,
                                    'currency' => 'PHP',
                                ],
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
        string $eventType = 'checkout_session.payment.failed',
    ): array {
        return [
            'data' => [
                'attributes' => [
                    'type' => $eventType,
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
