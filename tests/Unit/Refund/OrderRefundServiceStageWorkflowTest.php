<?php

declare(strict_types=1);

namespace Tests\Unit\Refund;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\NotificationService;
use App\Services\OrderRefundService;
use App\Services\PaymentSettlementService;
use App\Services\PaymongoRefundService;
use App\Services\ShopOwnerApprovalPolicyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderRefundServiceStageWorkflowTest extends TestCase
{
    private bool $requiresOwnerApproval = true;

    /** @var MockObject&PaymongoRefundService */
    private MockObject $paymongoRefundService;

    /** @var MockObject&PaymentSettlementService */
    private MockObject $paymentSettlementService;

    /** @var MockObject&ShopOwnerApprovalPolicyService */
    private MockObject $shopOwnerApprovalPolicyService;

    /** @var MockObject&NotificationService */
    private MockObject $notificationService;

    private OrderRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymongoRefundService = $this->createMock(PaymongoRefundService::class);
        $this->paymentSettlementService = $this->createMock(PaymentSettlementService::class);
        $this->shopOwnerApprovalPolicyService = $this->createMock(ShopOwnerApprovalPolicyService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->shopOwnerApprovalPolicyService
            ->method('requiresOwnerApprovalForRefund')
            ->willReturnCallback(fn () => $this->requiresOwnerApproval);

        $this->service = new OrderRefundService(
            paymongoRefundService: $this->paymongoRefundService,
            paymentSettlementService: $this->paymentSettlementService,
            shopOwnerApprovalPolicyService: $this->shopOwnerApprovalPolicyService,
            notificationService: $this->notificationService,
        );
    }

    #[Test]
    public function shop_owner_approval_requires_finance_initial_approval_first(): void
    {
        $refund = $this->makeRefund();

        $result = $this->service->approveRequestedRefund($refund, stage: 'shop_owner', processedBy: 10);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('pending', $refund->shop_owner_status);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame('awaiting_approval', $refund->return_status);
    }

    #[Test]
    public function finance_can_finalize_immediately_when_owner_approval_is_not_required(): void
    {
        $this->requiresOwnerApproval = false;

        $refund = $this->makeRefund();

        $result = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 10);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved', $refund->shop_owner_status);
        $this->assertSame('approved', $refund->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->return_status);
    }

    #[Test]
    public function finance_uses_the_refund_snapshot_when_live_policy_changes(): void
    {
        $this->requiresOwnerApproval = true;

        $refund = $this->makeRefund(['requires_owner_approval' => false]);
        $result = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 10);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved', $refund->finance_status);
        $this->assertSame('approved', $refund->shop_owner_status);

        $this->requiresOwnerApproval = false;
        $refund = $this->makeRefund(['requires_owner_approval' => true]);
        $result = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 10);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved_initial', $refund->finance_status);
        $this->assertSame('pending', $refund->shop_owner_status);
    }

    #[Test]
    public function staged_approval_moves_refund_to_pending_customer_shipment(): void
    {
        $refund = $this->makeRefund();

        $financeInitial = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 12);
        $this->assertSame('approved_initial', $refund->finance_status);
        $this->assertSame('pending', $refund->shop_owner_status);

        $shopOwnerApproval = $this->service->approveRequestedRefund($refund, stage: 'shop_owner', processedBy: 11);
        $financeFinal = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 12);

        $this->assertSame('approved', $shopOwnerApproval['result']);
        $this->assertSame('approved', $financeInitial['result']);
        $this->assertSame('approved', $financeFinal['result']);
        $this->assertSame('approved', $refund->shop_owner_status);
        $this->assertSame('approved', $refund->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->return_status);
        $this->assertSame('pending_approval', $refund->status);
    }

    #[Test]
    public function company_refund_requires_staff_approval_before_finance(): void
    {
        $refund = $this->makeRefund(registrationType: 'company');
        $this->assertSame('company', $refund->order->shopOwner->registration_type);

        $blocked = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 12);

        $this->assertSame('invalid_state', $blocked['result']);
        $this->assertSame('pending', $refund->finance_status);

        $staff = $this->service->approveRequestedRefund($refund, stage: 'staff', processedBy: 11);

        $this->assertSame('approved', $staff['result']);
        $this->assertSame('approved', $refund->shop_owner_status);
        $this->assertSame(11, $refund->shop_owner_approved_by);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame('awaiting_approval', $refund->return_status);

        $finance = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 12);

        $this->assertSame('approved', $finance['result']);
        $this->assertSame('approved', $refund->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->return_status);
    }

    #[Test]
    public function company_finance_cannot_reject_before_staff_review(): void
    {
        $refund = $this->makeRefund(registrationType: 'company');
        $this->assertSame('company', $refund->order->shopOwner->registration_type);

        $blocked = $this->service->rejectRequestedRefund($refund, 'Not approved', stage: 'finance', processedBy: 12);

        $this->assertSame('invalid_state', $blocked['result']);
        $this->assertSame('requested', $refund->status);

        $staff = $this->service->rejectRequestedRefund($refund, 'Not refundable', stage: 'staff', processedBy: 11);

        $this->assertSame('rejected', $staff['result']);
        $this->assertSame('rejected', $refund->shop_owner_status);
        $this->assertSame('Not refundable', $refund->rejection_reason);
    }

    #[Test]
    public function customer_return_shipment_requires_completed_approvals(): void
    {
        $refund = $this->makeRefund();

        $result = $this->service->markCustomerReturnShipped($refund, [
            'tracking_number' => 'TRK-123',
            'carrier' => 'LBC',
        ]);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('awaiting_approval', $refund->return_status);
    }

    #[Test]
    public function customer_return_shipment_transitions_to_in_transit_with_tracking_details(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->markCustomerReturnShipped($refund, [
            'tracking_number' => 'TRK-789',
            'carrier' => 'J&T',
            'tracking_link' => 'https://track.example/TRK-789',
            'note' => 'Parcel dropped off at branch',
        ]);

        $this->assertSame('in_transit', $result['result']);
        $this->assertSame('in_transit', $refund->return_status);
        $this->assertSame('TRK-789', $refund->customer_return_tracking_number);
        $this->assertSame('J&T', $refund->customer_return_carrier);
        $this->assertSame('https://track.example/TRK-789', $refund->customer_return_tracking_link);
    }

    #[Test]
    public function customer_return_shipment_is_blocked_when_staff_pickup_mode_is_active(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->markCustomerReturnShipped($refund, [
            'tracking_number' => 'TRK-BLOCK',
            'carrier' => 'LBC',
        ]);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertStringContainsString('handled by staff', strtolower((string) $result['message']));
    }

    #[Test]
    public function staff_pickup_arrangement_sets_pending_staff_pickup_and_staff_fields(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->arrangeStaffReturnPickup($refund, [
            'tracking_number' => 'TRK-STAFF-123',
            'carrier_company' => 'J&T',
            'rider_name' => 'Rider One',
            'rider_phone' => '09171234567',
            'tracking_link' => 'https://track.example/TRK-STAFF-123',
        ], staffId: 77);

        $this->assertSame('pickup_arranged', $result['result']);
        $this->assertSame('pending_staff_pickup', $refund->return_status);
        $this->assertSame('staff', $refund->return_source);
        $this->assertSame('TRK-STAFF-123', $refund->staff_return_tracking_number);
        $this->assertSame('J&T', $refund->staff_return_carrier);
        $this->assertSame(77, $refund->return_arranged_by_staff_id);
    }

    #[Test]
    public function staff_pickup_arrangement_notifies_customer_pickup_is_scheduled(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('sendToUser')
            ->with(
                501,
                NotificationType::ORDER_STATUS_UPDATE,
                'Refund Return Pickup Arranged',
                $this->stringContains('Staff arranged your return pickup.'),
                $this->isType('array'),
                '/my-orders?tab=return_refund',
                202
            );

        $refund = $this->makeRefund([
            'customer_id' => 501,
            'shop_owner_id' => 202,
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->arrangeStaffReturnPickup($refund, [
            'tracking_number' => 'TRK-NOTIFY-123',
            'carrier_company' => 'Lalamove',
            'rider_name' => 'Pickup Rider',
            'rider_phone' => '09170000000',
            'tracking_link' => 'https://track.example/TRK-NOTIFY-123',
        ], staffId: 55);

        $this->assertSame('pickup_arranged', $result['result']);
    }

    #[Test]
    public function completed_return_notifies_finance_that_payout_is_ready(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('sendToErpRole')
            ->with(
                'Finance',
                202,
                NotificationType::REFUND_REQUEST,
                'Refund Payout Ready',
                $this->stringContains('payout'),
                $this->callback(fn ($data): bool => is_array($data)
                    && ($data['refund_id'] ?? null) === 5001
                    && ($data['return_status'] ?? null) === 'received'),
                '/finance?section=refund-approvals',
                'high',
                'refund-payout-ready:order:5001',
                true,
            );

        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
            'status' => 'pending_approval',
        ]);
        $refund->setAttribute('id', 5001);
        $refund->setAttribute('shop_owner_id', 202);

        $result = $this->service->confirmReturnReceived($refund, staffId: 77, notes: 'Box inspected and complete');

        $this->assertSame('received', $result['result']);
    }

    #[Test]
    public function final_finance_approval_notifies_finance_when_return_was_already_received(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('sendToErpRole')
            ->with(
                'Finance',
                202,
                NotificationType::REFUND_REQUEST,
                'Refund Payout Ready',
                $this->stringContains('payout'),
                $this->callback(fn ($data): bool => is_array($data)
                    && ($data['refund_id'] ?? null) === 5001
                    && ($data['return_status'] ?? null) === 'received'),
                '/finance?section=refund-approvals',
                'high',
                'refund-payout-ready:order:5001',
                true,
            );

        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved_initial',
            'return_status' => 'received',
            'status' => 'pending_approval',
        ]);
        $refund->setAttribute('id', 5001);
        $refund->setAttribute('shop_owner_id', 202);

        $result = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 77);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved', $refund->finance_status);
    }

    #[Test]
    public function staff_can_confirm_return_received_after_shipment(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->confirmReturnReceived($refund, staffId: 77, notes: 'Box inspected and complete');

        $this->assertSame('received', $result['result']);
        $this->assertSame('received', $refund->return_status);
        $this->assertSame(77, $refund->return_confirmed_by_staff_id);
        $this->assertNotNull($refund->return_confirmed_at);
    }

    #[Test]
    public function staff_can_confirm_return_received_from_pending_staff_pickup_state(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->confirmReturnReceived($refund, staffId: 88, notes: 'Collected and inspected');

        $this->assertSame('received', $result['result']);
        $this->assertSame('received', $refund->return_status);
        $this->assertSame(88, $refund->return_confirmed_by_staff_id);
    }

    #[Test]
    public function staff_cannot_confirm_shop_owned_return_before_rider_delivery(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'staff_return_carrier' => 'Shop-owned logistics',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->confirmReturnReceived($refund, staffId: 88);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('pending_staff_pickup', $refund->return_status);
    }

    #[Test]
    public function execute_refund_blocks_payout_when_return_is_in_transit(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
            'status' => 'pending_approval',
        ]);

        $this->paymongoRefundService->expects($this->never())->method('createRefund');

        $result = $this->service->executeApprovedRefund($refund, processedBy: 90);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('pending_approval', $refund->status);
        $this->assertStringContainsString('received', strtolower((string) $result['message']));
    }

    #[Test]
    public function execute_refund_marks_request_as_refunded_when_gateway_succeeds(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'status' => 'pending_approval',
            'amount' => 2500.00,
            'reason_code' => 'defective_item',
            'reason_note' => 'Customer sent proof photos',
        ]);

        $this->paymongoRefundService
            ->expects($this->once())
            ->method('createRefund')
            ->willReturn([
                'success' => true,
                'status' => 'succeeded',
                'refund_id' => 're_test_123',
            ]);

        $this->paymentSettlementService
            ->expects($this->once())
            ->method('settleOrderRefunded');

        $result = $this->service->executeApprovedRefund($refund, processedBy: 91, executionNote: 'Release approved');

        $this->assertSame('refunded', $result['result']);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('re_test_123', $refund->paymongo_refund_id);
        $this->assertNotNull($refund->refund_executed_at);
        $this->assertNotNull($refund->refunded_at);
    }

    #[Test]
    public function execute_refund_retries_with_captured_amount_for_same_day_partial_rejection(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'status' => 'pending_approval',
            'amount' => 900.00,
        ]);

        $this->paymongoRefundService
            ->expects($this->exactly(2))
            ->method('createRefund')
            ->willReturnOnConsecutiveCalls(
                [
                    'success' => false,
                    'message' => 'Cannot partially refund for payments done on the same day.',
                ],
                [
                    'success' => true,
                    'status' => 'succeeded',
                    'refund_id' => 're_retry_full_amount',
                ],
            );

        $this->paymongoRefundService
            ->expects($this->once())
            ->method('getPaymentAmountInCentavos')
            ->willReturn(250000);

        $this->paymentSettlementService
            ->expects($this->once())
            ->method('settleOrderRefunded');

        $result = $this->service->executeApprovedRefund($refund, processedBy: 99);

        $this->assertSame('refunded', $result['result']);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame(2500.00, (float) $refund->amount);
        $this->assertSame('re_retry_full_amount', $refund->paymongo_refund_id);
    }

    #[Test]
    public function shop_owner_rejection_sets_rejected_state_and_reason(): void
    {
        $refund = $this->makeRefund([
            'status' => 'pending_approval',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);

        $result = $this->service->rejectRequestedRefund(
            refund: $refund,
            rejectionReason: 'Item condition does not qualify for refund.',
            stage: 'shop_owner',
            processedBy: 321,
        );

        $this->assertSame('rejected', $result['result']);
        $this->assertSame('rejected', $refund->status);
        $this->assertSame('rejected', $refund->shop_owner_status);
        $this->assertSame('Item condition does not qualify for refund.', $refund->rejection_reason);
    }

    private function makeRefund(array $overrides = [], ?string $registrationType = null): InMemoryOrderRefund
    {
        $refund = new InMemoryOrderRefund(array_merge([
            'id' => 5001,
            'order_id' => 3001,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'awaiting_approval',
            'requires_owner_approval' => $this->requiresOwnerApproval,
            'amount' => 2500.00,
            'currency' => 'PHP',
            'reason_code' => 'quality_issue',
        ], $overrides));

        $order = new Order([
            'id' => 3001,
            'customer_id' => 101,
            'shop_owner_id' => 202,
            'payment_method' => 'paymongo',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_test_123',
            'total_amount' => 2500.00,
            'shipping_fee' => 0,
        ]);

        $shopOwner = new ShopOwner([
            'id' => 202,
            'registration_type' => $registrationType,
            'paymongo_secret_key' => 'sk_test_abc',
        ]);

        $order->setRelation('shopOwner', $shopOwner);
        $refund->setRelation('order', $order);

        return $refund;
    }
}

final class InMemoryOrderRefund extends OrderRefund
{
    public function loadMissing($relations)
    {
        return $this;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->fill($attributes);

        return true;
    }

    public function fresh($with = [])
    {
        return $this;
    }

    public function refresh()
    {
        return $this;
    }
}
