<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PremiumSubscriptionRefundTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_full_refund_uses_paymongo_and_ends_entitlement_without_rewriting_paid_history(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $admin = $this->superAdmin();
        $this->fakeRefundProvider($payment);

        $response = $this->postJson(route('admin.subscription-payments.refunds.store', $payment), [
            'business_reason' => 'Verified duplicate premium charge.',
            'provider_reason' => 'duplicate',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('attempt.status', 'succeeded')
            ->assertJsonMissingPath('attempt.raw');

        $attempt = $payment->refunds()->sole();
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('ref_subscription_1', $attempt->provider_refund_id);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('249.00', (string) $payment->fresh()->amount_paid);
        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertFalse((bool) $subscription->fresh()->auto_renew);
        $this->assertNotNull($subscription->fresh()->ends_at);
        $this->assertTrue($subscription->fresh()->ends_at->lessThanOrEqualTo(now()));
        $this->assertNotNull($admin->fresh());
    }

    public function test_local_eligibility_failures_do_not_call_paymongo_or_create_attempts(): void
    {
        [, $payment] = $this->paidPayment(['gateway' => 'stripe']);
        $this->superAdmin();

        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame(0, $payment->refunds()->count());
    }

    public function test_provider_amount_mismatch_blocks_refund_before_posting_money_movement(): void
    {
        [, $payment] = $this->paidPayment();
        $this->superAdmin();
        Http::fake([
            'https://api.paymongo.com/v1/payments/*' => Http::response([
                'data' => [
                    'id' => $payment->paymongo_payment_id,
                    'type' => 'payment',
                    'attributes' => ['status' => 'paid', 'amount' => 10000, 'currency' => 'PHP'],
                ],
            ]),
            'https://api.paymongo.com/v1/refunds*' => Http::response(['data' => []]),
        ]);

        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(502);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame('failed', $payment->refunds()->sole()->status);
    }

    public function test_provider_success_without_refund_binding_fields_does_not_end_entitlement(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $this->superAdmin();
        Http::fake(function ($request) use ($payment) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/payments/')) {
                return $this->paymentResponse($payment);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    'id' => 'ref_subscription_missing_fields',
                    'type' => 'refund',
                    'attributes' => ['status' => 'succeeded'],
                ],
            ]);
        });

        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('code', 'subscription_refund_failed');

        $this->assertSame('failed', $payment->refunds()->sole()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_known_provider_rejection_is_safe_and_preserves_entitlement_and_payment_history(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $this->superAdmin();
        Http::fake([
            'https://api.paymongo.com/v1/payments/*' => $this->paymentResponse($payment),
            'https://api.paymongo.com/v1/refunds*' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []]);
                }

                return Http::response([
                    'errors' => [[
                        'code' => 'payment_not_refundable',
                        'detail' => 'provider detail must not reach the browser',
                    ]],
                ], 422);
            },
        ]);

        $response = $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'subscription_refund_failed');
        $this->assertStringNotContainsString('provider detail must not reach the browser', (string) $response->getContent());

        $this->assertSame('failed', $payment->refunds()->sole()->status);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_existing_provider_refund_is_adopted_without_posting_a_second_refund(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $this->superAdmin();
        Http::fake(function ($request) use ($payment) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/payments/')) {
                return $this->paymentResponse($payment);
            }

            return Http::response([
                'data' => [[
                    'id' => 'ref_subscription_existing',
                    'type' => 'refund',
                    'attributes' => [
                        'amount' => 24900,
                        'currency' => 'PHP',
                        'payment_id' => $payment->paymongo_payment_id,
                        'status' => 'succeeded',
                    ],
                ]],
            ]);
        });

        $response = $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertOk()
            ->assertJsonPath('attempt.status', 'succeeded')
            ->assertJsonPath('attempt.provider_refund_id', 'ref_subscription_existing');

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame('succeeded', $payment->refunds()->sole()->status);
        $this->assertSame('cancelled', $subscription->fresh()->status);
    }

    public function test_provider_timeout_is_unknown_and_does_not_end_entitlement(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $this->superAdmin();
        Http::fake(function ($request) use ($payment) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/payments/')) {
                return $this->paymentResponse($payment);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            throw new ConnectionException('provider timeout secret');
        });

        $response = $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(202)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'subscription_refund_unknown');
        $this->assertStringNotContainsString('provider timeout secret', (string) $response->getContent());

        $this->assertSame('unknown', $payment->refunds()->sole()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_exact_duplicate_submission_replays_one_succeeded_attempt_without_a_second_provider_post(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $this->superAdmin();
        $this->fakeRefundProvider($payment);

        $payload = $this->validPayload();
        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $payload)
            ->assertOk();
        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $payload)
            ->assertOk()
            ->assertJsonPath('replayed', true);

        Http::assertSentCount(3);
        $this->assertSame(1, $payment->refunds()->count());
        $this->assertSame('cancelled', $subscription->fresh()->status);
    }

    public function test_regular_admin_and_missing_recent_reauthentication_are_denied_before_provider_calls(): void
    {
        [, $payment] = $this->paidPayment();
        $regularAdmin = SuperAdmin::factory()->admin()->create();
        $this->actingAsCompletedPrivileged($regularAdmin)
            ->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertForbidden();

        $superAdmin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($superAdmin)
            ->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(423);

        Http::assertNothingSent();
        $this->assertSame(0, $payment->refunds()->count());
    }

    public function test_provider_reason_is_allowlisted_and_invalid_input_is_rejected_without_provider_call(): void
    {
        [, $payment] = $this->paidPayment();
        $this->superAdmin();

        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), [
            'business_reason' => 'Refund reason',
            'provider_reason' => 'not-a-paymongo-reason',
        ])->assertUnprocessable();

        Http::assertNothingSent();
        $this->assertSame(0, $payment->refunds()->count());
    }

    public function test_payment_and_subscription_scope_mismatch_is_rejected(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        [, $payment] = $this->paidPayment();
        $payment->update(['shop_owner_id' => $owner->id]);
        $this->superAdmin();

        $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame(0, $payment->refunds()->count());
    }

    public function test_refund_initiation_audit_failure_rolls_back_attempt_before_provider_call(): void
    {
        [, $payment] = $this->paidPayment();
        $admin = $this->superAdmin();
        $audit = Mockery::mock(PrivilegedAudit::class)->makePartial();
        $audit->shouldReceive('premiumSubscriptionRefundInitiated')
            ->once()
            ->andThrow(new \RuntimeException('refund initiation audit secret'));
        $this->instance(PrivilegedAudit::class, $audit);

        $response = $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(500)
            ->assertJsonPath('code', 'subscription_refund_error');

        $this->assertStringNotContainsString('refund initiation audit secret', (string) $response->getContent());
        Http::assertNothingSent();
        $this->assertSame(0, $payment->refunds()->count());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNotNull($admin->fresh());
    }

    public function test_refund_outcome_audit_failure_rolls_back_only_local_finalization(): void
    {
        [$subscription, $payment] = $this->paidPayment();
        $this->superAdmin();
        $this->fakeRefundProvider($payment);
        $audit = Mockery::mock(PrivilegedAudit::class)->makePartial();
        $audit->shouldReceive('premiumSubscriptionRefundSucceeded')
            ->once()
            ->andThrow(new \RuntimeException('refund outcome audit secret'));
        $this->instance(PrivilegedAudit::class, $audit);

        $response = $this->postJson(route('admin.subscription-payments.refunds.store', $payment), $this->validPayload())
            ->assertStatus(500)
            ->assertJsonPath('code', 'subscription_refund_error');

        $this->assertStringNotContainsString('refund outcome audit secret', (string) $response->getContent());
        $this->assertSame('pending', $payment->refunds()->sole()->status);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function paidPayment(array $overrides = []): array
    {
        $owner = ShopOwner::factory()->approved()->create();
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'refund-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Refund Plan',
            'description' => 'Refund test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => 48,
            'benefits' => [],
            'status' => 'active',
        ]);
        $subscription = ShopOwnerSubscription::query()->create([
            'shop_owner_id' => $owner->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => 48,
            'status' => 'active',
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
        $payment = ShopOwnerSubscriptionPayment::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_payment_id' => 'pay_refund_'.fake()->unique()->numberBetween(1, 999999),
            'plan_price' => 249,
            'amount_due' => 249,
            'amount_paid' => 249,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ], $overrides));

        return [$subscription, $payment];
    }

    private function superAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => (int) $admin->security_version,
        ]);

        return $admin;
    }

    /** @return array{business_reason: string, provider_reason: string} */
    private function validPayload(): array
    {
        return [
            'business_reason' => 'Verified billing correction requiring a full refund.',
            'provider_reason' => 'others',
        ];
    }

    private function fakeRefundProvider(ShopOwnerSubscriptionPayment $payment): void
    {
        Http::fake(function ($request) use ($payment) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/payments/')) {
                return Http::response([
                    'data' => [
                        'id' => $payment->paymongo_payment_id,
                        'type' => 'payment',
                        'attributes' => ['status' => 'paid', 'amount' => 24900, 'currency' => 'PHP'],
                    ],
                ]);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    'id' => 'ref_subscription_1',
                    'type' => 'refund',
                    'attributes' => [
                        'amount' => 24900,
                        'currency' => 'PHP',
                        'payment_id' => $payment->paymongo_payment_id,
                        'reason' => 'others',
                        'status' => 'succeeded',
                    ],
                ],
            ]);
        });
    }

    private function paymentResponse(ShopOwnerSubscriptionPayment $payment)
    {
        return Http::response([
            'data' => [
                'id' => $payment->paymongo_payment_id,
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'amount' => 24900,
                    'currency' => 'PHP',
                ],
            ],
        ]);
    }
}
