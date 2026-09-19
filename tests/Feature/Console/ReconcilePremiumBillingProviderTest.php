<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ReconcilePremiumBillingProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_bounded_command_reconciles_known_provider_refund_success(): void
    {
        [$subscription, $payment, $attempt] = $this->attempt('processing', 'ref_command_success');
        Http::fake([
            'https://api.paymongo.com/v1/refunds/ref_command_success' => Http::response([
                'data' => [
                    'id' => 'ref_command_success',
                    'type' => 'refund',
                    'attributes' => [
                        'status' => 'succeeded',
                        'amount' => 24900,
                        'currency' => 'PHP',
                        'payment_id' => $payment->paymongo_payment_id,
                    ],
                ],
            ]),
        ]);

        $this->artisan('premium-billing:reconcile-provider', ['--limit' => 10])
            ->assertExitCode(0)
            ->expectsOutputToContain('succeeded: 1')
            ->expectsOutputToContain('Processed: 1');

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_unknown_attempt_is_reconciled_from_payment_refund_list_without_a_second_post(): void
    {
        [$subscription, $payment, $attempt] = $this->attempt('unknown', null);
        Http::fake([
            'https://api.paymongo.com/v1/refunds*' => Http::response([
                'data' => [[
                    'id' => 'ref_command_list',
                    'type' => 'refund',
                    'attributes' => [
                        'status' => 'succeeded',
                        'amount' => 24900,
                        'currency' => 'PHP',
                        'payment_id' => $payment->paymongo_payment_id,
                    ],
                ]],
            ]),
        ]);

        $this->artisan('premium-billing:reconcile-provider', ['--limit' => 1])
            ->assertExitCode(0)
            ->expectsOutputToContain('Processed: 1');

        Http::assertSentCount(1);
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('cancelled', $subscription->fresh()->status);
    }

    public function test_provider_failure_and_mismatch_are_safe_and_missing_object_stays_unknown(): void
    {
        [, $failurePayment, $failureAttempt] = $this->attempt('processing', 'ref_command_failure');
        [, $mismatchPayment, $mismatchAttempt] = $this->attempt('processing', 'ref_command_mismatch');
        [, $missingPayment, $missingAttempt] = $this->attempt('unknown', null);
        [, $orphanPayment, $orphanAttempt] = $this->attempt('pending', null);

        Http::fake(function ($request) use ($failurePayment, $mismatchPayment) {
            if (str_contains($request->url(), 'ref_command_failure')) {
                return Http::response([
                    'data' => [
                        'id' => 'ref_command_failure',
                        'type' => 'refund',
                        'attributes' => [
                            'status' => 'failed',
                            'amount' => 24900,
                            'currency' => 'PHP',
                            'payment_id' => $failurePayment->paymongo_payment_id,
                        ],
                    ],
                ]);
            }

            if (str_contains($request->url(), 'ref_command_mismatch')) {
                return Http::response([
                    'data' => [
                        'id' => 'ref_command_mismatch',
                        'type' => 'refund',
                        'attributes' => [
                            'status' => 'succeeded',
                            'amount' => 10000,
                            'currency' => 'PHP',
                            'payment_id' => $mismatchPayment->paymongo_payment_id,
                        ],
                    ],
                ]);
            }

            return Http::response(['data' => []]);
        });

        $this->artisan('premium-billing:reconcile-provider', ['--limit' => 10])
            ->assertExitCode(0)
            ->expectsOutputToContain('Processed: 4');

        $this->assertSame('failed', $failureAttempt->fresh()->status);
        $this->assertSame('failed', $mismatchAttempt->fresh()->status);
        $this->assertSame('unknown', $missingAttempt->fresh()->status);
        $this->assertSame('unknown', $orphanAttempt->fresh()->status);
    }

    public function test_limit_is_bounded_and_rerun_does_not_reprocess_terminal_attempt(): void
    {
        [, $firstPayment] = $this->attempt('unknown', null);
        $this->attempt('unknown', null);
        Http::fake(['https://api.paymongo.com/v1/refunds*' => Http::response([
            'data' => [[
                'id' => 'ref_first_reconciled',
                'type' => 'refund',
                'attributes' => [
                    'status' => 'succeeded',
                    'amount' => 24900,
                    'currency' => 'PHP',
                    'payment_id' => $firstPayment->paymongo_payment_id,
                ],
            ]],
        ])]);

        $this->artisan('premium-billing:reconcile-provider', ['--limit' => 1])
            ->assertExitCode(0)
            ->expectsOutputToContain('Processed: 1');
        Http::assertSentCount(1);

        Http::fake(['https://api.paymongo.com/v1/refunds*' => Http::response(['data' => []])]);
        $this->artisan('premium-billing:reconcile-provider', ['--limit' => 10000])
            ->assertExitCode(0)
            ->expectsOutputToContain('Processed: 1');
        Http::assertSentCount(1);
    }

    /** @return array{0: ShopOwnerSubscription, 1: ShopOwnerSubscriptionPayment, 2: ShopOwnerSubscriptionRefund} */
    private function attempt(string $status, ?string $providerRefundId): array
    {
        $owner = ShopOwner::factory()->approved()->create();
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'provider-reconcile-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Provider Reconcile Plan',
            'description' => 'Provider reconciliation test plan',
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
        $payment = ShopOwnerSubscriptionPayment::query()->create([
            'shop_owner_id' => $owner->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'new_subscription',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'paymongo_payment_id' => 'pay_provider_'.fake()->unique()->numberBetween(1, 999999),
            'plan_price' => 249,
            'amount_due' => 249,
            'amount_paid' => 249,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);
        $attempt = ShopOwnerSubscriptionRefund::query()->create([
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'local_reference' => (string) fake()->uuid(),
            'provider_refund_id' => $providerRefundId,
            'amount' => 249,
            'currency' => 'PHP',
            'business_reason' => 'Provider reconciliation test.',
            'provider_reason' => 'others',
            'status' => $status,
            'initiated_at' => now()->subMinute(),
        ]);

        return [$subscription, $payment, $attempt];
    }
}
