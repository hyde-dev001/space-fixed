<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Orders;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Orders\OrderOwnerProjection;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderOwnerProjectionTest extends TestCase
{
    #[Test]
    public function delivered_and_completed_orders_are_closed_without_authoritative_blockers(): void
    {
        $projection = new OrderOwnerProjection();

        foreach (['delivered', 'completed'] as $status) {
            $result = $projection->project($this->order($status));

            $this->assertSame($status, $result['fulfillment_status']);
            $this->assertTrue($result['business_closed']);
            $this->assertSame([], $result['blockers']);
        }
    }

    #[Test]
    public function non_terminal_fulfillment_is_not_business_closed(): void
    {
        $result = (new OrderOwnerProjection())->project($this->order('shipped'));

        $this->assertFalse($result['business_closed']);
        $this->assertSame(['fulfillment'], $result['blockers']);
    }

    #[Test]
    public function an_open_refund_keeps_a_terminal_order_open(): void
    {
        $refund = new OrderRefund([
            'status' => 'approved',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'not_required',
        ]);

        $result = (new OrderOwnerProjection())->project($this->order('delivered', refunds: [$refund]));

        $this->assertFalse($result['business_closed']);
        $this->assertSame(['refund'], $result['blockers']);
    }

    #[Test]
    public function a_failed_refund_also_keeps_a_terminal_order_open_until_resolved(): void
    {
        $refund = new OrderRefund([
            'status' => 'failed',
            'return_status' => 'not_required',
        ]);

        $result = (new OrderOwnerProjection())->project($this->order('completed', refunds: [$refund]));

        $this->assertFalse($result['business_closed']);
        $this->assertSame(['refund'], $result['blockers']);
    }

    #[Test]
    public function an_open_return_keeps_a_terminal_order_open(): void
    {
        $refund = new OrderRefund([
            'status' => 'succeeded',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
        ]);

        $result = (new OrderOwnerProjection())->project($this->order('completed', refunds: [$refund]));

        $this->assertFalse($result['business_closed']);
        $this->assertSame(['return'], $result['blockers']);
    }

    #[Test]
    public function an_authoritative_open_payment_keeps_a_terminal_order_open(): void
    {
        $result = (new OrderOwnerProjection())->project($this->order('delivered', [
            'payment_status' => 'pending',
        ]));

        $this->assertFalse($result['business_closed']);
        $this->assertSame(['payment'], $result['blockers']);
    }

    #[Test]
    public function terminal_refund_and_return_states_do_not_block_closure(): void
    {
        $refund = new OrderRefund([
            'status' => 'rejected',
            'shop_owner_status' => 'rejected',
            'finance_status' => 'rejected',
            'return_status' => 'received',
        ]);

        $result = (new OrderOwnerProjection())->project($this->order('delivered', refunds: [$refund]));

        $this->assertTrue($result['business_closed']);
        $this->assertSame([], $result['blockers']);
    }

    #[Test]
    public function available_actions_follow_the_transition_policy_and_pickup_evidence(): void
    {
        $projection = new OrderOwnerProjection();

        $this->assertSame(['processing'], $projection->availableActions($this->order('pending')));
        $this->assertSame(['shipped'], $projection->availableActions($this->order('processing')));
        $this->assertSame([], $projection->availableActions($this->order('shipped')));
        $this->assertSame(
            ['processing', 'completed'],
            $projection->availableActions($this->order('pending', ['pickup_enabled' => true])),
        );
    }

    private function order(string $status, array $attributes = [], array $refunds = []): Order
    {
        $order = Order::make(array_merge([
            'status' => $status,
            'payment_status' => 'paid',
        ], $attributes));

        $order->setRelation('refunds', new Collection($refunds));

        return $order;
    }
}
