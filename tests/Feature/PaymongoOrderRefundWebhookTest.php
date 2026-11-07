<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymongoOrderRefundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function refund(string $id, string $status = 'processing', ?Order $order = null): OrderRefund
    {
        $order ??= Order::factory()->create(['payment_method' => 'paymongo_card', 'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_exact', 'total_amount' => 1000, 'shipping_fee' => 0]);
        return OrderRefund::factory()->create(['order_id' => $order->id, 'shop_owner_id' => $order->shop_owner_id,
            'customer_id' => $order->customer_id, 'paymongo_payment_id' => $order->paymongo_payment_id,
            'paymongo_refund_id' => $id, 'status' => $status, 'amount' => 500, 'payment_gateway' => 'paymongo']);
    }

    private function event(string $id, string $status, string $payment = 'pay_exact'): void
    {
        config()->set('services.paymongo.webhook_secret', 'whsk_test_order_refund');
        $body = json_encode(['data' => ['id' => 'evt_test', 'type' => 'event', 'attributes' => [
            'type' => 'payment.refund.updated', 'livemode' => false, 'data' => ['id' => $id, 'type' => 'refund',
                'attributes' => ['status' => $status, 'payment_id' => $payment, 'amount' => 50000, 'currency' => 'PHP']]]]], JSON_THROW_ON_ERROR);
        $time = (string) time();
        $signature = hash_hmac('sha256', $time.'.'.$body, 'whsk_test_order_refund');
        $this->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$time},te={$signature},li="], $body)->assertOk();
    }

    public function test_exact_refund_id_wins_over_newer_refund_on_same_payment(): void
    {
        $first = $this->refund('re_first');
        $newer = $this->refund('re_newer', 'processing', $first->order);
        $this->event('re_first', 'succeeded');
        $this->assertSame('succeeded', $first->fresh()->status);
        $this->assertSame('processing', $newer->fresh()->status);
        $this->assertSame('re_newer', $newer->fresh()->paymongo_refund_id);
    }

    public function test_delayed_pending_and_failed_callbacks_do_not_regress_confirmed_success(): void
    {
        $refund = $this->refund('re_success', 'succeeded');
        $this->event('re_success', 'pending');
        $this->event('re_success', 'failed');
        $this->assertSame('succeeded', $refund->fresh()->status);
    }

    public function test_wrong_payment_and_unknown_transaction_do_not_mutate_a_guess(): void
    {
        $refund = $this->refund('re_known');
        $this->event('re_known', 'succeeded', 'pay_foreign');
        $this->event('re_unknown', 'succeeded');
        $this->assertSame('processing', $refund->fresh()->status);
        $this->assertSame('paid', $refund->order->fresh()->payment_status);
    }

    public function test_unrecognized_outcome_does_not_replace_failure_with_processing(): void
    {
        $refund = $this->refund('re_failed', 'failed');
        $this->event('re_failed', 'unknown');
        $this->assertSame('failed', $refund->fresh()->status);
    }

    public function test_reconciliation_uses_the_same_exact_result_application_without_submitting_money(): void
    {
        $refund = $this->refund('re_reconcile');
        $refund->order->shopOwner->update(['paymongo_secret_key' => 'sk_test_synthetic']);
        $this->mock(\App\Services\PaymongoRefundService::class, function ($mock) {
            $mock->shouldReceive('getRefundStatus')->once()->with('sk_test_synthetic', 're_reconcile')
                ->andReturn(['success' => true, 'refund_id' => 're_reconcile', 'status' => 'succeeded',
                    'raw' => ['data' => ['attributes' => ['payment_id' => 'pay_exact', 'currency' => 'PHP']]]]);
        });
        $service = app(\App\Services\OrderRefundService::class);
        $this->assertSame('succeeded', $service->reconcileOnlineRefund($refund)->status);
        $this->assertSame('Partially Refunded', $service->refundExecutionLabel($refund->fresh()));
    }

    public function test_amount_and_currency_mismatches_do_not_apply_success(): void
    {
        $refund = $this->refund('re_bound');
        $service = app(\App\Services\OrderRefundService::class);
        foreach ([['amount_in_centavos' => 1], ['currency' => 'USD']] as $mismatch) {
            $service->applyPaymongoRefundOutcome($refund, ['refund_id' => 're_bound', 'payment_id' => 'pay_exact',
                'status' => 'succeeded', ...$mismatch]);
            $this->assertSame('processing', $refund->fresh()->status);
        }
    }
}
