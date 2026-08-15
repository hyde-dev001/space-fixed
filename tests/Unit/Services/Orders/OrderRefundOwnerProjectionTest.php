<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Orders;

use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Services\Orders\OrderRefundOwnerProjection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderRefundOwnerProjectionTest extends TestCase
{
    #[Test]
    public function requested_refund_exposes_owner_review_as_presentation_state(): void
    {
        $refund = new OrderRefund([
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'pending_customer_shipment',
        ]);

        $result = (new OrderRefundOwnerProjection())->project($refund);

        $this->assertSame([
            'case_state' => 'requested',
            'return_state' => 'awaiting_return',
            'payout_state' => 'not_started',
            'waiting_on' => 'owner',
            'owner_action_required' => true,
            'next_action' => 'review_refund',
            'material_failure_reason' => null,
        ], $result);
    }

    #[Test]
    public function approved_refund_waiting_for_customer_return_is_not_an_owner_action(): void
    {
        $refund = new OrderRefund([
            'status' => 'approved',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
        ]);

        $result = (new OrderRefundOwnerProjection())->project($refund);

        $this->assertSame('approved', $result['case_state']);
        $this->assertSame('awaiting_return', $result['return_state']);
        $this->assertSame('pending', $result['payout_state']);
        $this->assertSame('customer', $result['waiting_on']);
        $this->assertFalse($result['owner_action_required']);
        $this->assertNull($result['next_action']);
        $this->assertNull($result['material_failure_reason']);
    }

    #[Test]
    public function pos_refund_processing_waits_on_finance_without_leaking_workflow_fields(): void
    {
        $refund = new PosRefund([
            'status' => 'processing',
            'finance_status' => 'processing',
            'shop_owner_status' => 'approved',
        ]);

        $result = (new OrderRefundOwnerProjection())->project($refund);

        $this->assertSame([
            'case_state' => 'processing',
            'return_state' => 'not_required',
            'payout_state' => 'processing',
            'waiting_on' => 'finance',
            'owner_action_required' => false,
            'next_action' => null,
            'material_failure_reason' => null,
        ], $result);
    }

    #[Test]
    public function failed_refund_preserves_a_material_failure_reason(): void
    {
        $refund = new PosRefund([
            'status' => 'failed',
            'failure_reason' => 'Gateway timeout',
        ]);

        $result = (new OrderRefundOwnerProjection())->project($refund);

        $this->assertSame('failed', $result['case_state']);
        $this->assertSame('failed', $result['payout_state']);
        $this->assertSame('none', $result['waiting_on']);
        $this->assertFalse($result['owner_action_required']);
        $this->assertNull($result['next_action']);
        $this->assertSame('Gateway timeout', $result['material_failure_reason']);
    }

    #[Test]
    public function successful_refund_is_a_terminal_presentation_state(): void
    {
        $refund = new OrderRefund([
            'status' => 'succeeded',
            'return_status' => 'received',
        ]);

        $result = (new OrderRefundOwnerProjection())->project($refund);

        $this->assertSame('succeeded', $result['case_state']);
        $this->assertSame('received', $result['return_state']);
        $this->assertSame('succeeded', $result['payout_state']);
        $this->assertSame('none', $result['waiting_on']);
        $this->assertFalse($result['owner_action_required']);
        $this->assertNull($result['next_action']);
        $this->assertNull($result['material_failure_reason']);
    }
}
