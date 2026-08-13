<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PaymongoSubscriptionRefundWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_refund_webhook_finalizes_existing_attempt_and_replay_is_inert(): void
    {
        [$subscription, $payment, $attempt] = $this->pendingAttempt();

        $payload = $this->refundPayload(
            eventType: 'payment.refunded',
            refundId: 'ref_webhook_1',
            paymentId: $payment->paymongo_payment_id,
            status: 'succeeded',
        );

        $this->postJson('/api/webhooks/paymongo', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded');

        $auditCount = DB::table('activity_log')
            ->where('description', 'subscription_refund_succeeded')
            ->count();

        $this->postJson('/api/webhooks/paymongo', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded');

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('ref_webhook_1', $attempt->fresh()->provider_refund_id);
        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame($auditCount, DB::table('activity_log')
            ->where('description', 'subscription_refund_succeeded')
            ->count());
    }

    public function test_refund_updated_failure_preserves_subscription_and_payment(): void
    {
        [$subscription, $payment, $attempt] = $this->pendingAttempt();

        $this->postJson('/api/webhooks/paymongo', $this->refundPayload(
            eventType: 'payment.refund.updated',
            refundId: 'ref_webhook_failed',
            paymentId: $payment->paymongo_payment_id,
            status: 'failed',
        ))->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_refund_webhook_cannot_rebind_an_attempt_to_another_payment(): void
    {
        [$subscription, $payment, $attempt] = $this->pendingAttempt();
        $attempt->update(['provider_refund_id' => 'ref_webhook_binding']);

        $this->postJson('/api/webhooks/paymongo', $this->refundPayload(
            eventType: 'payment.refunded',
            refundId: 'ref_webhook_binding',
            paymentId: 'pay_not_this_subscription',
            status: 'succeeded',
        ))->assertOk()
            ->assertJsonPath('message', 'Refund event ignored');

        $this->assertSame('processing', $attempt->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_untracked_subscription_refund_webhook_does_not_invent_local_attempt(): void
    {
        [, $payment, $attempt] = $this->pendingAttempt();
        $attempt->delete();

        $this->postJson('/api/webhooks/paymongo', $this->refundPayload(
            eventType: 'payment.refunded',
            refundId: 'ref_untracked',
            paymentId: $payment->paymongo_payment_id,
            status: 'succeeded',
        ))->assertOk();

        $this->assertSame(0, ShopOwnerSubscriptionRefund::query()->count());
    }

    /** @return array{0: ShopOwnerSubscription, 1: ShopOwnerSubscriptionPayment, 2: ShopOwnerSubscriptionRefund} */
    private function pendingAttempt(): array
    {
        $owner = ShopOwner::factory()->approved()->create();
        $plan = PremiumPlan::query()->create([
            'plan_code' => 'webhook-refund-'.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Webhook Refund Plan',
            'description' => 'Webhook refund test plan',
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
            'paymongo_payment_id' => 'pay_webhook_'.fake()->unique()->numberBetween(1, 999999),
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
            'amount' => 249,
            'currency' => 'PHP',
            'business_reason' => 'Webhook fixture refund.',
            'provider_reason' => 'others',
            'status' => 'processing',
            'initiated_at' => now()->subMinute(),
        ]);

        return [$subscription, $payment, $attempt];
    }

    /** @return array<string, mixed> */
    private function refundPayload(string $eventType, string $refundId, string $paymentId, string $status): array
    {
        return [
            'data' => [
                'id' => 'evt_'.fake()->uuid(),
                'type' => 'event',
                'attributes' => [
                    'type' => $eventType,
                    'data' => [
                        'id' => $refundId,
                        'type' => 'refund',
                        'attributes' => [
                            'payment_id' => $paymentId,
                            'amount' => 24900,
                            'currency' => 'PHP',
                            'status' => $status,
                        ],
                    ],
                ],
            ],
        ];
    }
}
