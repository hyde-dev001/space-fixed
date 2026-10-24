<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentAllocation;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformFeeRefundTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unpaid_marketplace_fee_gets_an_append_only_reversal(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $order = $this->createMarketplaceOrder($shop, 1000);

        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'refund-unpaid-1',
        ]);

        $this->assertDatabaseHas('platform_fee_adjustments', [
            'shop_id' => $shop->id,
            'adjustment_type' => 'refund_reversal',
            'total_delta' => '-56.00',
        ]);
        $this->assertDatabaseCount('platform_credit_applications', 0);
        $this->assertSame('0.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['net_payable']);
    }

    #[Test]
    public function a_paid_marketplace_fee_becomes_a_future_platform_credit(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $order = $this->createMarketplaceOrder($shop, 1000);
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id,
            'amount' => 56,
            'balance_snapshot' => 56,
            'credit_snapshot' => 0,
            'net_payable_snapshot' => 56,
            'status' => 'paid',
            'idempotency_key' => 'payment-refund-1',
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id,
            'platform_fee_charge_id' => $order->platformFeeCharge->id,
            'allocation_type' => 'payment',
            'amount' => 56,
            'idempotency_key' => 'payment-refund-1-allocation',
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'refund-paid-1',
        ]);

        $this->assertDatabaseHas('platform_credit_applications', [
            'shop_id' => $shop->id,
            'source_type' => 'order_refund',
            'source_id' => $order->refunds()->latest('id')->value('id'),
            'credit_amount' => '56.00',
            'status' => 'available',
        ]);
        $this->assertSame('56.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['available_credits']);
        $this->assertSame('0.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['net_payable']);

        $nextOrder = $this->createMarketplaceOrder($shop, 1000);
        $this->assertDatabaseHas('platform_fee_payment_allocations', [
            'platform_fee_charge_id' => $nextOrder->platformFeeCharge->id,
            'allocation_type' => 'credit',
            'amount' => '56.00',
        ]);
        $this->assertSame('0.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['available_credits']);
        $this->assertSame('0.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['net_payable']);
    }

    #[Test]
    public function partial_refunds_use_the_original_fee_and_vat_snapshots(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $order = $this->createMarketplaceOrder($shop, 2000);

        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 500,
            'idempotency_key' => 'refund-partial-1',
        ]);

        $this->assertDatabaseHas('platform_fee_adjustments', [
            'adjustment_type' => 'refund_reversal',
            'fee_base_delta' => '-500.00',
            'platform_fee_delta' => '-25.00',
            'vat_delta' => '-3.00',
            'total_delta' => '-28.00',
        ]);
    }

    #[Test]
    public function order_refund_shipping_does_not_reverse_the_platform_fee(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1000,
            'shipping_fee' => 200,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);
        $order->update(['status' => 'delivered']);

        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1200,
            'idempotency_key' => 'refund-shipping-excluded-1',
        ]);

        $this->assertDatabaseHas('platform_fee_adjustments', [
            'adjustment_type' => 'refund_reversal',
            'total_delta' => '-56.00',
        ]);
    }

    #[Test]
    public function partially_applied_platform_credit_keeps_its_remaining_balance(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $paidOrder = $this->createMarketplaceOrder($shop, 1000);
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id,
            'amount' => 56,
            'balance_snapshot' => 56,
            'credit_snapshot' => 0,
            'net_payable_snapshot' => 56,
            'status' => 'paid',
            'idempotency_key' => 'payment-refund-partial-credit-1',
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id,
            'platform_fee_charge_id' => $paidOrder->platformFeeCharge->id,
            'allocation_type' => 'payment',
            'amount' => 56,
            'idempotency_key' => 'payment-refund-partial-credit-1-allocation',
        ]);

        OrderRefund::create([
            'order_id' => $paidOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'refund-partial-credit-1',
        ]);

        $this->createMarketplaceOrder($shop, 500);

        $this->assertSame('28.00', app(\App\Services\PlatformBalanceService::class)->summary($shop->id)['available_credits']);
    }

    #[Test]
    public function multiple_partial_refunds_do_not_create_more_credit_than_the_paid_fee(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $order = $this->createMarketplaceOrder($shop, 2000);
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id,
            'amount' => 28,
            'balance_snapshot' => 112,
            'credit_snapshot' => 0,
            'net_payable_snapshot' => 112,
            'status' => 'paid',
            'idempotency_key' => 'payment-refund-partial-paid-fee',
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id,
            'platform_fee_charge_id' => $order->platformFeeCharge->id,
            'allocation_type' => 'payment',
            'amount' => 28,
            'idempotency_key' => 'payment-refund-partial-paid-fee-allocation',
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'refund-partial-paid-fee-1',
        ]);
        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'refund-partial-paid-fee-2',
        ]);

        $this->assertSame('28', (string) \App\Models\PlatformCreditApplication::query()->sum('credit_amount'));
        $this->assertSame('-84', (string) \App\Models\PlatformFeeAdjustment::query()->sum('total_delta'));
    }

    private function createMarketplaceOrder(ShopOwner $shop, int $total): Order
    {
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => $total,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);
        $order->update(['status' => 'delivered']);

        return $order->fresh('platformFeeCharge');
    }
}
