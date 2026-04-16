<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderRefundService
{
    /** @var array<string, bool>|null */
    private ?array $orderRefundColumns = null;

    public function __construct(
        private readonly PaymongoRefundService $paymongoRefundService,
        private readonly PaymentSettlementService $paymentSettlementService,
        private readonly ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function autoRefundOnCancellation(
        Order $order,
        ?string $reason = null,
        ?string $note = null,
        ?string $otherReasonNote = null,
    ): array
    {
        if (!$this->isEligibleForOnlineRefund($order)) {
            return [
                'result' => 'not_required',
                'message' => 'No gateway refund required for this order.',
                'refund' => null,
            ];
        }

        $existing = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('flow_type', 'cancel_auto')
            ->whereIn('status', ['processing', 'succeeded'])
            ->latest('id')
            ->first();

        if ($existing) {
            return [
                'result' => $existing->status === 'succeeded' ? 'already_refunded' : 'already_processing',
                'message' => 'A refund was already created for this cancellation.',
                'refund' => $existing,
            ];
        }

        $order->loadMissing('shopOwner', 'items');

        $secretKey = (string) ($order->shopOwner?->paymongo_secret_key ?? '');
        if ($secretKey === '') {
            return [
                'result' => 'failed',
                'message' => 'Payment gateway is not configured for this shop.',
                'refund' => null,
            ];
        }

        $paymentId = $this->resolvePaymentId($order, $secretKey);
        if (!$paymentId) {
            return [
                'result' => 'failed',
                'message' => 'Unable to resolve payment reference for refund.',
                'refund' => null,
            ];
        }

        $amount = $this->resolveRefundAmount($order, $secretKey);

        // PayMongo may reject same-day partial refunds for captured payments, so
        // ensure full-order cancellation uses at least the captured gateway amount.
        $capturedAmountInCentavos = $this->paymongoRefundService->getPaymentAmountInCentavos($secretKey, $paymentId);
        if ($capturedAmountInCentavos !== null && $capturedAmountInCentavos > 0) {
            $capturedAmount = round($capturedAmountInCentavos / 100, 2);
            $amount = max($amount, $capturedAmount);
        }

        if ($amount <= 0) {
            return [
                'result' => 'failed',
                'message' => 'Refund amount is invalid.',
                'refund' => null,
            ];
        }

        $idempotencyKey = 'cancel-auto-order-' . $order->id;
        $reasonText = trim((string) $reason);
        $noteText = trim((string) $note);
        $otherReasonText = trim((string) $otherReasonNote);
        $resolvedReasonCode = $reasonText !== '' ? Str::slug($reasonText, '_') : 'customer_cancellation';
        $mergedReasonNote = collect([$reasonText, $otherReasonText, $noteText])
            ->filter(fn ($value) => $value !== '')
            ->implode("\n\n");

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $order->shop_owner_id,
            'flow_type' => 'cancel_auto',
            'status' => 'processing',
            'shop_owner_status' => 'approved',
            'shop_owner_approved_at' => now(),
            'finance_status' => 'approved',
            'finance_approved_at' => now(),
            'return_status' => 'not_required',
            'refund_executed_at' => now(),
            'payment_gateway' => 'paymongo',
            'paymongo_payment_id' => $paymentId,
            'amount' => $amount,
            'currency' => 'PHP',
            'reason_code' => $resolvedReasonCode,
            'reason_note' => $mergedReasonNote !== '' ? $mergedReasonNote : null,
            'other_reason_note' => $otherReasonText !== '' ? $otherReasonText : null,
            'idempotency_key' => $idempotencyKey,
            'requested_at' => now(),
        ]);

        $amountInCentavos = (int) round($amount * 100);

        $gatewayResult = $this->paymongoRefundService->createRefund(
            secretKey: $secretKey,
            paymentId: $paymentId,
            amountInCentavos: $amountInCentavos,
            reason: 'requested_by_customer',
        );

        if (
            !($gatewayResult['success'] ?? false)
            && $this->shouldRetryWithCapturedAmount($gatewayResult, $amountInCentavos)
        ) {
            $capturedAmountInCentavos = $this->paymongoRefundService->getPaymentAmountInCentavos($secretKey, $paymentId);

            if ($capturedAmountInCentavos !== null && $capturedAmountInCentavos > $amountInCentavos) {
                Log::info('Retrying refund payout with captured amount due to PayMongo same-day partial restriction', [
                    'refund_id' => (int) ($refund->id ?? 0),
                    'order_id' => (int) ($order->id ?? 0),
                    'requested_amount_in_centavos' => $amountInCentavos,
                    'captured_amount_in_centavos' => $capturedAmountInCentavos,
                ]);

                $gatewayResult = $this->paymongoRefundService->createRefund(
                    secretKey: $secretKey,
                    paymentId: $paymentId,
                    amountInCentavos: $capturedAmountInCentavos,
                    reason: 'requested_by_customer',
                );

                if ($gatewayResult['success'] ?? false) {
                    $amount = round($capturedAmountInCentavos / 100, 2);
                    $refund->update(['amount' => $amount]);
                }
            }
        }

        if (!($gatewayResult['success'] ?? false)) {
            $refund->update([
                'status' => 'failed',
                'failure_reason' => (string) ($gatewayResult['message'] ?? 'Refund request failed'),
                'failed_at' => now(),
            ]);

            $this->paymentSettlementService->recordOrderRefundFailure($order, (string) ($gatewayResult['message'] ?? 'refund_failed'));

            return [
                'result' => 'failed',
                'message' => (string) ($gatewayResult['message'] ?? 'Refund request failed'),
                'refund' => $refund->fresh(),
            ];
        }

        $gatewayStatus = strtolower((string) ($gatewayResult['status'] ?? 'processing'));
        $refundStatus = in_array($gatewayStatus, ['succeeded', 'completed', 'paid'], true)
            ? 'succeeded'
            : 'processing';

        $refund->update([
            'status' => $refundStatus,
            'paymongo_refund_id' => $gatewayResult['refund_id'] ?? null,
            'refunded_at' => $refundStatus === 'succeeded' ? now() : null,
            'failure_reason' => null,
            'failed_at' => null,
            'reason_code' => $resolvedReasonCode,
            'reason_note' => $mergedReasonNote !== '' ? $mergedReasonNote : null,
            'other_reason_note' => $otherReasonText !== '' ? $otherReasonText : null,
        ]);

        if ($refundStatus === 'succeeded') {
            $this->paymentSettlementService->settleOrderRefunded(
                order: $order,
                refundId: $refund->paymongo_refund_id,
                reason: $reason,
                note: $note,
            );
        }

        return [
            'result' => $refundStatus === 'succeeded' ? 'refunded' : 'processing',
            'message' => 'Refund request has been submitted successfully.',
            'refund' => $refund->fresh(),
        ];
    }

    public function approveRequestedRefund(
        OrderRefund $refund,
        string $stage = 'finance',
        ?int $processedBy = null,
        ?string $approvalNote = null,
    ): array
    {
        $refund->loadMissing('order.shopOwner');
        $order = $refund->order;

        if (!$order) {
            return [
                'result' => 'failed',
                'message' => 'Refund is not linked to an order.',
                'refund' => $refund,
            ];
        }

        if (in_array((string) $refund->status, ['failed', 'rejected', 'succeeded'], true)) {
            return [
                'result' => 'invalid_state',
                'message' => 'Refund request cannot be approved in its current state.',
                'refund' => $refund,
            ];
        }

        if ((string) ($order->payment_status ?? 'pending') === 'refunded') {
            $refund->update([
                'status' => 'succeeded',
                'approved_at' => $refund->approved_at ?? now(),
                'refunded_at' => $refund->refunded_at ?? now(),
                'processed_by' => $processedBy,
            ]);

            return [
                'result' => 'already_refunded',
                'message' => 'Order is already refunded.',
                'refund' => $refund->fresh(),
            ];
        }

        $stageNormalized = strtolower(trim($stage));
        if (!in_array($stageNormalized, ['shop_owner', 'finance'], true)) {
            return [
                'result' => 'invalid_stage',
                'message' => 'Invalid approval stage.',
                'refund' => $refund,
            ];
        }

        $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRefund(
            (int) ($refund->shop_owner_id ?? 0),
            (float) ($refund->amount ?? 0)
        );

        $isIndividualRegistration = $this->isIndividualRegistrationType(
            (string) ($order->shopOwner?->registration_type ?? '')
        );

        // Individual shops should route refund approvals directly to shop owner without finance pre-approval.
        if ($isIndividualRegistration) {
            $requiresOwnerApproval = true;
        }

        $payload = [
            'approved_at' => $refund->approved_at ?? now(),
            'processed_by' => $processedBy,
        ];
        $previousFinanceStatus = (string) ($refund->finance_status ?? 'pending');
        $previousShopOwnerStatus = (string) ($refund->shop_owner_status ?? 'pending');

        if ($approvalNote) {
            $payload['reason_note'] = trim((string) ($refund->reason_note ? $refund->reason_note . "\n\n" : '') . 'Approval note: ' . $approvalNote);
        }

        if ($stageNormalized === 'shop_owner') {
            if (!$requiresOwnerApproval) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Shop owner approval is not required by policy for this refund request.',
                    'refund' => $refund,
                ];
            }

            $financeStatus = (string) ($refund->finance_status ?? 'pending');
            $financePreapproved = $financeStatus === 'approved_initial';

            if (!$isIndividualRegistration && !$financePreapproved) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Shop owner approval requires finance initial approval first.',
                    'refund' => $refund,
                ];
            }

            if ((string) ($refund->shop_owner_status ?? 'pending') === 'approved') {
                return [
                    'result' => 'already_approved',
                    'message' => 'Shop owner has already approved this refund request.',
                    'refund' => $refund,
                ];
            }

            $payload['shop_owner_status'] = 'approved';
            $payload['shop_owner_approved_at'] = now();
            $payload['shop_owner_approved_by'] = $processedBy;

            if ($isIndividualRegistration && $financeStatus !== 'approved') {
                $payload['finance_status'] = 'approved';
                $payload['finance_approved_at'] = now();
                $payload['finance_approved_by'] = null;
            }
        }

        if ($stageNormalized === 'finance') {
            $financeStatus = (string) ($refund->finance_status ?? 'pending');

            if (!$requiresOwnerApproval) {
                if ($financeStatus === 'approved') {
                    return [
                        'result' => 'already_approved',
                        'message' => 'Finance has already approved this refund request.',
                        'refund' => $refund,
                    ];
                }

                $payload['finance_status'] = 'approved';
                $payload['finance_approved_at'] = now();
                $payload['finance_approved_by'] = $processedBy;
                $payload['shop_owner_status'] = 'approved';
                $payload['shop_owner_approved_at'] = $payload['shop_owner_approved_at'] ?? now();
                $payload['shop_owner_approved_by'] = $payload['shop_owner_approved_by'] ?? null;
            } else {
                if ($financeStatus === 'approved') {
                    return [
                        'result' => 'already_approved',
                        'message' => 'Finance has already finalized this refund request.',
                        'refund' => $refund,
                    ];
                }

                if ($financeStatus === 'pending') {
                    $payload['finance_status'] = 'approved_initial';
                    $payload['finance_approved_at'] = now();
                    $payload['finance_approved_by'] = $processedBy;
                } elseif ($financeStatus === 'approved_initial') {
                    if ((string) ($refund->shop_owner_status ?? 'pending') !== 'approved') {
                        return [
                            'result' => 'invalid_state',
                            'message' => 'Shop owner approval is required before finance final approval.',
                            'refund' => $refund,
                        ];
                    }

                    $payload['finance_status'] = 'approved';
                    $payload['finance_approved_at'] = now();
                    $payload['finance_approved_by'] = $processedBy;
                } else {
                    return [
                        'result' => 'invalid_state',
                        'message' => 'Refund request cannot be approved in its current finance state.',
                        'refund' => $refund,
                    ];
                }
            }
        }

        if ((string) ($refund->status ?? 'requested') === 'requested') {
            $payload['status'] = 'pending_approval';
        }

        $isDualApproved = ($payload['shop_owner_status'] ?? $refund->shop_owner_status) === 'approved'
            && ($payload['finance_status'] ?? $refund->finance_status) === 'approved';

        if ($isDualApproved && in_array((string) ($refund->return_status ?? 'awaiting_approval'), ['awaiting_approval', 'not_required'], true)) {
            $payload['return_status'] = 'pending_customer_shipment';
            $payload['return_source'] = $payload['return_source'] ?? 'customer';
        }

        $this->updateOrderRefundCompat($refund, $payload);
        $freshRefund = $refund->fresh();

        $this->dispatchRefundApprovalNotifications(
            $freshRefund ?? $refund,
            $order,
            $stageNormalized,
            $requiresOwnerApproval,
            $isIndividualRegistration,
            $previousFinanceStatus,
            $previousShopOwnerStatus,
        );

        $nextMessage = 'Refund approval recorded.';
        if ($stageNormalized === 'finance') {
            $newFinanceStatus = (string) ($payload['finance_status'] ?? $refund->finance_status);
            if (!$requiresOwnerApproval) {
                $nextMessage = 'Finance final approval recorded. Awaiting product return confirmation before payout.';
            } elseif ($newFinanceStatus === 'approved_initial') {
                $nextMessage = 'Finance initial approval recorded. Awaiting shop owner approval.';
            } else {
                $nextMessage = 'Finance final approval recorded. Awaiting product return confirmation before payout.';
            }
        } elseif ($stageNormalized === 'shop_owner') {
            $nextMessage = $isIndividualRegistration
                ? 'Shop owner approval recorded. Awaiting customer return shipment.'
                : 'Shop owner approval recorded. Awaiting finance final approval.';
        }

        return [
            'result' => 'approved',
            'message' => $nextMessage,
            'refund' => $freshRefund ?? $refund,
        ];
    }

    public function rejectRequestedRefund(OrderRefund $refund, string $rejectionReason, string $stage = 'finance', ?int $processedBy = null): array
    {
        $refund->loadMissing('order.shopOwner');

        if (!in_array((string) $refund->status, ['requested', 'pending_approval'], true)) {
            return [
                'result' => 'invalid_state',
                'message' => 'Refund request cannot be rejected in its current state.',
                'refund' => $refund,
            ];
        }

        $stageNormalized = strtolower(trim($stage));
        if (!in_array($stageNormalized, ['shop_owner', 'finance'], true)) {
            return [
                'result' => 'invalid_stage',
                'message' => 'Invalid rejection stage.',
                'refund' => $refund,
            ];
        }

        if ($stageNormalized === 'finance') {
            $financeStatus = strtolower(trim((string) ($refund->finance_status ?? 'pending')));
            if (!in_array($financeStatus, ['pending', 'approved_initial'], true)) {
                return [
                    'result' => 'already_' . $financeStatus,
                    'message' => 'Finance has already ' . $financeStatus . ' this refund request.',
                    'refund' => $refund,
                ];
            }
        }

        if ($stageNormalized === 'shop_owner') {
            $shopOwnerStatus = strtolower(trim((string) ($refund->shop_owner_status ?? 'pending')));
            $registrationType = strtolower(trim((string) ($refund->order?->shopOwner?->registration_type ?? '')));
            $isIndividualRegistration = $registrationType === 'individual';
            if ($shopOwnerStatus !== 'pending') {
                return [
                    'result' => 'already_' . $shopOwnerStatus,
                    'message' => 'Shop owner has already ' . $shopOwnerStatus . ' this refund request.',
                    'refund' => $refund,
                ];
            }

            if (!$isIndividualRegistration && (string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Shop owner can reject only after finance initial approval.',
                    'refund' => $refund,
                ];
            }
        }

        $payload = [
            'status' => 'rejected',
            'rejection_reason' => $rejectionReason,
            'approved_at' => now(),
            'processed_by' => $processedBy,
            'failed_at' => null,
            'failure_reason' => null,
        ];

        if ($stageNormalized === 'shop_owner') {
            $payload['shop_owner_status'] = 'rejected';
        } else {
            $payload['finance_status'] = 'rejected';
        }

        $refund->update($payload);

        $this->dispatchRefundRejectionNotifications($refund->fresh() ?? $refund, $stageNormalized, $rejectionReason);

        return [
            'result' => 'rejected',
            'message' => 'Refund request has been rejected.',
            'refund' => $refund->fresh(),
        ];
    }

    public function markCustomerReturnShipped(OrderRefund $refund, array $shipmentData): array
    {
        if ((string) ($refund->shop_owner_status ?? 'pending') !== 'approved' || (string) ($refund->finance_status ?? 'pending') !== 'approved') {
            return [
                'result' => 'invalid_state',
                'message' => 'Refund approvals must be completed before return shipment.',
                'refund' => $refund,
            ];
        }

        $returnSource = strtolower((string) ($refund->return_source ?? 'customer'));
        if ($returnSource === 'staff') {
            return [
                'result' => 'invalid_state',
                'message' => 'Return pickup is being handled by staff. Please wait for pickup updates.',
                'refund' => $refund,
            ];
        }

        if (!in_array((string) ($refund->return_status ?? 'awaiting_approval'), ['pending_customer_shipment', 'in_transit'], true)) {
            return [
                'result' => 'invalid_state',
                'message' => 'Return shipment cannot be updated in the current state.',
                'refund' => $refund,
            ];
        }

        $this->updateOrderRefundCompat($refund, [
            'return_status' => 'in_transit',
            'customer_return_tracking_number' => $shipmentData['tracking_number'] ?? $refund->customer_return_tracking_number,
            'customer_return_carrier' => $shipmentData['carrier_company'] ?? ($shipmentData['carrier'] ?? $refund->customer_return_carrier),
            'customer_return_rider_name' => $shipmentData['rider_name'] ?? $refund->customer_return_rider_name,
            'customer_return_rider_phone' => $shipmentData['rider_phone'] ?? $refund->customer_return_rider_phone,
            'customer_return_tracking_link' => $shipmentData['tracking_link'] ?? $refund->customer_return_tracking_link,
            'customer_return_shipped_at' => $shipmentData['shipped_at'] ?? now(),
            'return_notes' => $shipmentData['note'] ?? $refund->return_notes,
            'return_source' => 'customer',
        ]);

        return [
            'result' => 'in_transit',
            'message' => 'Return shipment details submitted successfully.',
            'refund' => $refund->fresh(),
        ];
    }

    public function arrangeStaffReturnPickup(OrderRefund $refund, array $pickupData, ?int $staffId = null): array
    {
        if ((string) ($refund->shop_owner_status ?? 'pending') !== 'approved' || (string) ($refund->finance_status ?? 'pending') !== 'approved') {
            return [
                'result' => 'invalid_state',
                'message' => 'Refund approvals must be completed before arranging return pickup.',
                'refund' => $refund,
            ];
        }

        if (!in_array((string) ($refund->return_status ?? 'awaiting_approval'), ['pending_customer_shipment', 'pending_staff_pickup', 'in_transit'], true)) {
            return [
                'result' => 'invalid_state',
                'message' => 'Return pickup cannot be arranged in the current state.',
                'refund' => $refund,
            ];
        }

        $staffShippedAt = $pickupData['shipped_at'] ?? null;

        $this->updateOrderRefundCompat($refund, [
            'return_status' => $staffShippedAt ? 'in_transit' : 'pending_staff_pickup',
            'staff_return_tracking_number' => $pickupData['tracking_number'] ?? $refund->staff_return_tracking_number,
            'staff_return_carrier' => $pickupData['carrier_company'] ?? ($pickupData['carrier'] ?? $refund->staff_return_carrier),
            'staff_return_rider_name' => $pickupData['rider_name'] ?? $refund->staff_return_rider_name,
            'staff_return_rider_phone' => $pickupData['rider_phone'] ?? $refund->staff_return_rider_phone,
            'staff_return_tracking_link' => $pickupData['tracking_link'] ?? $refund->staff_return_tracking_link,
            'staff_return_shipped_at' => $staffShippedAt,
            'return_arranged_by_staff_id' => $staffId,
            'return_arranged_by_staff_at' => now(),
            'return_source' => 'staff',
            'return_notes' => $pickupData['note'] ?? $refund->return_notes,
        ]);

        $freshRefund = $refund->fresh();

        return [
            'result' => 'pickup_arranged',
            'message' => $staffShippedAt
                ? 'Staff pickup and shipment details saved successfully.'
                : 'Staff pickup details saved successfully. Waiting for rider pickup.',
            'refund' => $freshRefund ?? $refund,
        ];
    }

    public function confirmReturnReceived(
        OrderRefund $refund,
        ?int $staffId,
        ?string $notes = null,
        ?array $lineDispositions = null,
    ): array
    {
        if (!in_array((string) ($refund->return_status ?? 'awaiting_approval'), ['pending_customer_shipment', 'pending_staff_pickup', 'in_transit'], true)) {
            return [
                'result' => 'invalid_state',
                'message' => 'Return cannot be confirmed in the current state.',
                'refund' => $refund,
            ];
        }

        if (!empty($lineDispositions) && Schema::hasTable('order_refund_items')) {
            try {
                $dispositionByOrderItemId = collect($lineDispositions)
                    ->filter(fn ($line) => is_array($line))
                    ->mapWithKeys(function (array $line) {
                        $orderItemId = (int) ($line['order_item_id'] ?? 0);
                        $disposition = strtolower(trim((string) ($line['inspection_disposition'] ?? '')));

                        if ($orderItemId <= 0 || !in_array($disposition, ['resellable', 'damaged'], true)) {
                            return [];
                        }

                        return [$orderItemId => $disposition];
                    });

                if ($dispositionByOrderItemId->isNotEmpty()) {
                    $refund->loadMissing('items');

                    foreach ($refund->items as $itemLine) {
                        $orderItemId = (int) ($itemLine->order_item_id ?? 0);
                        if ($orderItemId <= 0 || !$dispositionByOrderItemId->has($orderItemId)) {
                            continue;
                        }

                        $itemLine->inspection_disposition = $dispositionByOrderItemId->get($orderItemId);
                        $itemLine->save();
                    }
                }
            } catch (\Throwable $lineDispositionError) {
                Log::warning('Unable to persist return inspection dispositions before confirming return', [
                    'refund_id' => (int) ($refund->id ?? 0),
                    'error' => $lineDispositionError->getMessage(),
                ]);
            }
        }

        $this->updateOrderRefundCompat($refund, [
            'return_status' => 'received',
            'return_confirmed_at' => now(),
            'return_confirmed_by_staff_id' => $staffId,
            'return_notes' => $notes ?? $refund->return_notes,
            'staff_return_shipped_at' => $refund->staff_return_shipped_at ?? now(),
        ]);

        return [
            'result' => 'received',
            'message' => 'Product return has been confirmed as received.',
            'refund' => $refund->fresh(),
        ];
    }

    public function executeApprovedRefund(OrderRefund $refund, ?int $processedBy = null, ?string $executionNote = null): array
    {
        $refund->loadMissing('order.shopOwner');
        $order = $refund->order;

        if (!$order) {
            return [
                'result' => 'failed',
                'message' => 'Refund is not linked to an order.',
                'refund' => $refund,
            ];
        }

        if ((string) ($refund->shop_owner_status ?? 'pending') !== 'approved' || (string) ($refund->finance_status ?? 'pending') !== 'approved') {
            return [
                'result' => 'invalid_state',
                'message' => 'Both shop owner and finance approvals are required before payout.',
                'refund' => $refund,
            ];
        }

        if (!in_array((string) ($refund->return_status ?? 'awaiting_approval'), ['in_transit', 'received'], true)) {
            return [
                'result' => 'invalid_state',
                'message' => 'Return shipment must be marked in transit or received before payout execution.',
                'refund' => $refund,
            ];
        }

        if (in_array((string) ($refund->status ?? ''), ['processing', 'succeeded'], true)) {
            return [
                'result' => (string) ($refund->status ?? 'processing') === 'succeeded' ? 'already_refunded' : 'already_processing',
                'message' => 'Refund execution has already started for this request.',
                'refund' => $refund,
            ];
        }

        return $this->executeGatewayRefund($refund, $order, $processedBy, $executionNote);
    }

    private function executeGatewayRefund(OrderRefund $refund, Order $order, ?int $processedBy = null, ?string $executionNote = null): array
    {
        $secretKey = (string) ($order->shopOwner?->paymongo_secret_key ?? '');
        if ($secretKey === '') {
            $refund->update([
                'status' => 'failed',
                'failure_reason' => 'Payment gateway is not configured for this shop.',
                'failed_at' => now(),
                'processed_by' => $processedBy,
            ]);

            return [
                'result' => 'failed',
                'message' => 'Payment gateway is not configured for this shop.',
                'refund' => $refund->fresh(),
            ];
        }

        $paymentId = $this->resolvePaymentId($order, $secretKey);
        if (!$paymentId) {
            $refund->update([
                'status' => 'failed',
                'failure_reason' => 'Unable to resolve payment reference for refund.',
                'failed_at' => now(),
                'processed_by' => $processedBy,
            ]);

            return [
                'result' => 'failed',
                'message' => 'Unable to resolve payment reference for refund.',
                'refund' => $refund->fresh(),
            ];
        }

        $amount = $this->resolveLineBasedRefundAmount($refund);
        if ($amount > 0 && round((float) ($refund->amount ?? 0), 2) !== $amount) {
            $refund->update(['amount' => $amount]);
        }

        if ($amount <= 0) {
            $amount = (float) ($refund->amount ?? 0);
        }

        if ($amount <= 0) {
            $amount = $this->resolveRefundAmount($order, $secretKey);
        }

        if ($amount <= 0) {
            $refund->update([
                'status' => 'failed',
                'failure_reason' => 'Refund amount is invalid.',
                'failed_at' => now(),
                'processed_by' => $processedBy,
            ]);

            return [
                'result' => 'failed',
                'message' => 'Refund amount is invalid.',
                'refund' => $refund->fresh(),
            ];
        }

        $processingPayload = [
            'status' => 'processing',
            'paymongo_payment_id' => $paymentId,
            'amount' => round($amount, 2),
            'processed_by' => $processedBy,
            'refund_executed_at' => now(),
            'reason_note' => $executionNote
                ? trim((string) ($refund->reason_note ? $refund->reason_note . "\n\n" : '') . 'Finance payout note: ' . $executionNote)
                : $refund->reason_note,
        ];

        if ($refund->exists && $refund->getKey()) {
            $claimed = OrderRefund::query()
                ->whereKey($refund->id)
                ->whereNotIn('status', ['processing', 'succeeded'])
                ->update($processingPayload);

            if ($claimed === 0) {
                $freshRefund = OrderRefund::query()->find($refund->id) ?? $refund;
                $freshStatus = (string) ($freshRefund->status ?? 'processing');

                return [
                    'result' => $freshStatus === 'succeeded' ? 'already_refunded' : 'already_processing',
                    'message' => 'Refund execution has already started for this request.',
                    'refund' => $freshRefund,
                ];
            }

            $refund->refresh();
        } else {
            // Unit tests may use in-memory refund doubles without a persisted DB row.
            $refund->update($processingPayload);
        }

        $amountInCentavos = (int) round($amount * 100);

        $gatewayResult = $this->paymongoRefundService->createRefund(
            secretKey: $secretKey,
            paymentId: $paymentId,
            amountInCentavos: $amountInCentavos,
            reason: 'requested_by_customer',
        );

        if (
            !($gatewayResult['success'] ?? false)
            && $this->shouldRetryWithCapturedAmount($gatewayResult, $amountInCentavos)
        ) {
            $capturedAmountInCentavos = $this->paymongoRefundService->getPaymentAmountInCentavos($secretKey, $paymentId);

            if ($capturedAmountInCentavos !== null && $capturedAmountInCentavos > $amountInCentavos) {
                Log::info('Retrying refund payout with captured amount due to PayMongo same-day partial restriction', [
                    'refund_id' => (int) ($refund->id ?? 0),
                    'order_id' => (int) ($order->id ?? 0),
                    'requested_amount_in_centavos' => $amountInCentavos,
                    'captured_amount_in_centavos' => $capturedAmountInCentavos,
                ]);

                $gatewayResult = $this->paymongoRefundService->createRefund(
                    secretKey: $secretKey,
                    paymentId: $paymentId,
                    amountInCentavos: $capturedAmountInCentavos,
                    reason: 'requested_by_customer',
                );

                if ($gatewayResult['success'] ?? false) {
                    $amount = round($capturedAmountInCentavos / 100, 2);
                    $refund->update(['amount' => $amount]);
                }
            }
        }

        if (!($gatewayResult['success'] ?? false)) {
            $refund->update([
                'status' => 'failed',
                'failure_reason' => (string) ($gatewayResult['message'] ?? 'Refund request failed'),
                'failed_at' => now(),
            ]);

            $this->paymentSettlementService->recordOrderRefundFailure($order, (string) ($gatewayResult['message'] ?? 'refund_failed'));

            return [
                'result' => 'failed',
                'message' => (string) ($gatewayResult['message'] ?? 'Refund request failed'),
                'refund' => $refund->fresh(),
            ];
        }

        $gatewayStatus = strtolower((string) ($gatewayResult['status'] ?? 'processing'));
        $refundStatus = in_array($gatewayStatus, ['succeeded', 'completed', 'paid'], true)
            ? 'succeeded'
            : 'processing';

        $refund->update([
            'status' => $refundStatus,
            'paymongo_refund_id' => $gatewayResult['refund_id'] ?? null,
            'refunded_at' => $refundStatus === 'succeeded' ? now() : null,
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        if ($refundStatus === 'succeeded') {
            if (Schema::hasTable('order_refund_items')) {
                try {
                    $refund->loadMissing('items');
                    /** @var RefundInventoryDispositionService $inventoryDisposition */
                    $inventoryDisposition = app(RefundInventoryDispositionService::class);
                    foreach ($refund->items as $itemLine) {
                        $inventoryDisposition->applyOrderLine($itemLine);
                    }
                } catch (\Throwable $lineApplyError) {
                    Log::warning('Order refund succeeded but line-level inventory disposition failed', [
                        'refund_id' => (int) ($refund->id ?? 0),
                        'order_id' => (int) ($order->id ?? 0),
                        'error' => $lineApplyError->getMessage(),
                    ]);
                }
            }

            $this->paymentSettlementService->settleOrderRefunded(
                order: $order,
                refundId: $refund->paymongo_refund_id,
                reason: $refund->reason_code,
                note: $refund->reason_note,
            );
        }

        return [
            'result' => $refundStatus === 'succeeded' ? 'refunded' : 'processing',
            'message' => 'Refund payout execution has been submitted successfully.',
            'refund' => $refund->fresh(),
        ];
    }

    private function shouldRetryWithCapturedAmount(array $gatewayResult, int $amountInCentavos): bool
    {
        if ($amountInCentavos <= 0) {
            return false;
        }

        $message = strtolower(trim((string) ($gatewayResult['message'] ?? '')));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'cannot partially refund')
            && str_contains($message, 'same day');
    }

    private function resolveLineBasedRefundAmount(OrderRefund $refund): float
    {
        if (!Schema::hasTable('order_refund_items')) {
            return 0.0;
        }

        try {
            $refund->loadMissing('items');
            $lineBasedAmount = round((float) $refund->items->sum(fn ($line) => (float) ($line->line_amount ?? 0)), 2);

            return $lineBasedAmount > 0 ? $lineBasedAmount : 0.0;
        } catch (\Throwable $error) {
            Log::warning('Unable to resolve line-based refund amount for order refund', [
                'refund_id' => (int) ($refund->id ?? 0),
                'error' => $error->getMessage(),
            ]);

            return 0.0;
        }
    }

    private function isEligibleForOnlineRefund(Order $order): bool
    {
        $paymentMethod = strtolower((string) ($order->payment_method ?? 'paymongo'));
        $isOnlinePayment = !in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);

        return $isOnlinePayment && in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed'], true);
    }

    private function resolvePaymentId(Order $order, string $secretKey): ?string
    {
        $storedPaymentId = trim((string) ($order->paymongo_payment_id ?? ''));
        if ($storedPaymentId !== '') {
            return $storedPaymentId;
        }

        $sessionId = trim((string) ($order->paymongo_link_id ?? ''));
        if ($sessionId === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
                'Accept' => 'application/json',
            ])->get("https://api.paymongo.com/v1/checkout_sessions/{$sessionId}");

            if ($response->failed()) {
                return null;
            }

            $payments = $response->json('data.attributes.payments') ?? [];
            $firstPayment = $payments[0] ?? [];
            $paymentId = $firstPayment['data']['id'] ?? ($firstPayment['id'] ?? null);

            if ($paymentId) {
                $order->update(['paymongo_payment_id' => (string) $paymentId]);
            }

            return $paymentId ? (string) $paymentId : null;
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve PayMongo payment ID for refund', [
                'order_id' => $order->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveRefundAmount(Order $order, string $secretKey): float
    {
        $totalAmount = (float) ($order->total_amount ?? 0);
        $shippingFee = (float) ($order->shipping_fee ?? 0);
        $legacyTotal = (float) ($order->total ?? 0);

        $computed = $totalAmount + max(0, $shippingFee);
        $amount = max($computed, $legacyTotal, $totalAmount);

        if ($amount > 0) {
            return round($amount, 2);
        }

        $sessionId = trim((string) ($order->paymongo_link_id ?? ''));
        if ($sessionId === '') {
            return 0;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
                'Accept' => 'application/json',
            ])->get("https://api.paymongo.com/v1/checkout_sessions/{$sessionId}");

            if ($response->failed()) {
                return 0;
            }

            $payments = $response->json('data.attributes.payments') ?? [];
            $firstPaymentAmount = (int) ($payments[0]['data']['attributes']['amount'] ?? $payments[0]['attributes']['amount'] ?? 0);

            if ($firstPaymentAmount > 0) {
                return round($firstPaymentAmount / 100, 2);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve refund amount from PayMongo session', [
                'order_id' => $order->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    private function notifyCustomerForStaffPickup(OrderRefund $refund): void
    {
        if (!$this->notificationService) {
            return;
        }

        $customerId = (int) ($refund->customer_id ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $trackingNumber = (string) ($refund->staff_return_tracking_number ?? '-');
        $carrier = (string) ($refund->staff_return_carrier ?? '-');
        $riderName = (string) ($refund->staff_return_rider_name ?? '-');
        $riderPhone = (string) ($refund->staff_return_rider_phone ?? '-');

        $this->notificationService->sendToUser(
            userId: $customerId,
            type: NotificationType::ORDER_STATUS_UPDATE,
            title: 'Refund Return Pickup Arranged',
            message: 'Staff arranged your return pickup. Please wait for the rider to collect the item.',
            data: [
                'refund_id' => (int) ($refund->id ?? 0),
                'order_id' => (int) ($refund->order_id ?? 0),
                'return_status' => (string) ($refund->return_status ?? 'pending_staff_pickup'),
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
                'rider_name' => $riderName,
                'rider_phone' => $riderPhone,
                'tracking_link' => (string) ($refund->staff_return_tracking_link ?? ''),
            ],
            actionUrl: '/my-orders?tab=return_refund',
            shopId: (int) ($refund->shop_owner_id ?? 0),
        );
    }

    public function notifyRefundApprovalRequested(OrderRefund $refund): void
    {
        $refund->loadMissing('order.shopOwner', 'customer');
        $order = $refund->order;
        if (!$order) {
            return;
        }

        $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRefund(
            (int) ($refund->shop_owner_id ?? 0),
            (float) ($refund->amount ?? 0)
        );
        $isIndividualRegistration = $this->isIndividualRegistrationType(
            (string) ($order->shopOwner?->registration_type ?? '')
        );

        $data = $this->buildRefundNotificationData($refund, [
            'stage' => 'submitted',
            'requires_owner_approval' => $requiresOwnerApproval,
        ]);

        if ($isIndividualRegistration) {
            $this->notificationService->notifyRefundRequest(
                (int) ($refund->shop_owner_id ?? 0),
                $data,
            );
        } else {
            $this->notificationService->sendToErpRole(
                'Finance',
                (int) ($refund->shop_owner_id ?? 0),
                NotificationType::REFUND_REQUEST,
                'New Refund Approval Request',
                "Refund request for order #{$data['order_number']} (₱{$data['amount']}) needs finance review.",
                $data,
                '/finance?section=refund-approvals',
                'high'
            );
        }

    }

    private function dispatchRefundApprovalNotifications(
        OrderRefund $refund,
        Order $order,
        string $stage,
        bool $requiresOwnerApproval,
        bool $isIndividualRegistration,
        string $previousFinanceStatus,
        string $previousShopOwnerStatus,
    ): void {
        if (!$this->notificationService) {
            return;
        }

        $refund->loadMissing('customer', 'order.shopOwner');
        $currentFinanceStatus = (string) ($refund->finance_status ?? 'pending');
        $currentShopOwnerStatus = (string) ($refund->shop_owner_status ?? 'pending');
        $data = $this->buildRefundNotificationData($refund, [
            'stage' => $stage,
            'requires_owner_approval' => $requiresOwnerApproval,
            'previous_finance_status' => $previousFinanceStatus,
            'previous_shop_owner_status' => $previousShopOwnerStatus,
            'current_finance_status' => $currentFinanceStatus,
            'current_shop_owner_status' => $currentShopOwnerStatus,
        ]);

        if ($stage === 'finance' && !$requiresOwnerApproval && $previousFinanceStatus !== 'approved' && $currentFinanceStatus === 'approved') {
            $this->notifyShopOwnerBypassInfo($refund, $data);
            $this->notifyCustomerFinalApproval($refund, $data, 'Your refund request has been approved by finance and is awaiting return shipment confirmation.');
            return;
        }

        if ($stage === 'finance' && $requiresOwnerApproval && $currentFinanceStatus === 'approved_initial') {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: (int) ($refund->shop_owner_id ?? 0),
                type: NotificationType::REFUND_REQUEST,
                title: 'Refund Approval Required',
                message: "Order #{$data['order_number']} requires your refund approval.",
                data: $data,
                actionUrl: '/shop-owner/refund-approvals',
                priority: 'high',
                requiresAction: true,
            );
            return;
        }

        if ($stage === 'shop_owner' && $requiresOwnerApproval && !$isIndividualRegistration && $previousShopOwnerStatus !== 'approved' && $currentShopOwnerStatus === 'approved') {
            $this->notificationService->sendToErpRole(
                'Finance',
                (int) ($refund->shop_owner_id ?? 0),
                NotificationType::REFUND_REQUEST,
                'Refund Ready For Final Finance Approval',
                "Shop owner approved refund for order #{$data['order_number']}. Final finance approval is required.",
                $data,
                '/finance?section=refund-approvals',
                'high'
            );
            return;
        }

        if ($stage === 'shop_owner' && $isIndividualRegistration && $previousShopOwnerStatus !== 'approved' && $currentShopOwnerStatus === 'approved') {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: (int) ($refund->shop_owner_id ?? 0),
                type: NotificationType::REFUND_REQUEST,
                title: 'Refund Approved',
                message: "Refund for order #{$data['order_number']} has been approved and is awaiting return shipment confirmation.",
                data: $data,
                actionUrl: '/shop-owner/refund-approvals',
                priority: 'high',
                requiresAction: true,
            );
        }

        if ($currentFinanceStatus === 'approved' && $currentShopOwnerStatus === 'approved') {
            $this->notifyCustomerFinalApproval($refund, $data, 'Your refund request has completed approvals and is awaiting return shipment confirmation.');
        }
    }

    private function dispatchRefundRejectionNotifications(OrderRefund $refund, string $stage, string $reason): void
    {
        if (!$this->notificationService) {
            return;
        }

        $refund->loadMissing('customer', 'order.shopOwner');
        $data = $this->buildRefundNotificationData($refund, [
            'stage' => $stage,
            'rejection_reason' => $reason,
        ]);

        $customerId = (int) ($refund->customer_id ?? 0);
        if ($customerId > 0) {
            $this->notificationService->sendToUser(
                userId: $customerId,
                type: NotificationType::ORDER_STATUS_UPDATE,
                title: 'Refund Request Rejected',
                message: "Your refund request for order #{$data['order_number']} was rejected. Reason: {$reason}",
                data: $data,
                actionUrl: '/my-orders',
                shopId: (int) ($refund->shop_owner_id ?? 0),
                priority: 'high',
            );
        }

        $this->notificationService->sendToShopOwner(
            shopOwnerId: (int) ($refund->shop_owner_id ?? 0),
            type: NotificationType::REFUND_REQUEST,
            title: 'Refund Request Rejected',
            message: "Order #{$data['order_number']} refund request was rejected at " . ($stage === 'finance' ? 'finance' : 'shop owner') . " stage.",
            data: $data,
            actionUrl: '/shop-owner/refund-approvals',
            priority: 'medium',
        );
    }

    private function notifyShopOwnerBypassInfo(OrderRefund $refund, array $data): void
    {
        $this->notificationService?->sendToShopOwner(
            shopOwnerId: (int) ($refund->shop_owner_id ?? 0),
            type: NotificationType::REFUND_REQUEST,
            title: 'Owner Approval Bypassed By Settings',
            message: "Refund request for order #{$data['order_number']} was finalized by finance because owner approval is disabled in shop settings.",
            data: $data,
            actionUrl: '/shop-owner/refund-approvals',
            priority: 'medium',
        );
    }

    private function notifyCustomerFinalApproval(OrderRefund $refund, array $data, string $message): void
    {
        $customerId = (int) ($refund->customer_id ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $this->notificationService?->sendToUser(
            userId: $customerId,
            type: NotificationType::ORDER_STATUS_UPDATE,
            title: 'Refund Approved',
            message: $message,
            data: $data,
            actionUrl: '/my-orders',
            shopId: (int) ($refund->shop_owner_id ?? 0),
            priority: 'high',
        );
    }

    private function buildRefundNotificationData(OrderRefund $refund, array $extra = []): array
    {
        $order = $refund->order;

        return array_merge([
            'refund_id' => (int) ($refund->id ?? 0),
            'order_id' => (int) ($refund->order_id ?? 0),
            'order_number' => (string) ($order?->order_number ?? ('#' . (int) ($refund->order_id ?? 0))),
            'amount' => number_format((float) ($refund->amount ?? 0), 2),
            'shop_owner_id' => (int) ($refund->shop_owner_id ?? 0),
            'customer_id' => (int) ($refund->customer_id ?? 0),
            'status' => (string) ($refund->status ?? 'pending_approval'),
            'finance_status' => (string) ($refund->finance_status ?? 'pending'),
            'shop_owner_status' => (string) ($refund->shop_owner_status ?? 'pending'),
            'return_status' => (string) ($refund->return_status ?? 'awaiting_approval'),
        ], $extra);
    }

    private function updateOrderRefundCompat(OrderRefund $refund, array $payload): void
    {
        $refund->update($this->filterOrderRefundPayload($payload));
    }

    private function filterOrderRefundPayload(array $payload): array
    {
        $columns = $this->getOrderRefundColumnsMap();
        if ($columns === null) {
            return $payload;
        }

        $filtered = [];
        foreach ($payload as $key => $value) {
            if (isset($columns[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function isIndividualRegistrationType(string $registrationType): bool
    {
        $normalized = strtolower(trim($registrationType));

        if ($normalized === 'individual') {
            return true;
        }

        if ($normalized === '' || $normalized === 'company') {
            return false;
        }

        return str_contains($normalized, 'individual') || str_contains($normalized, 'sole');
    }

    /** @return array<string, bool>|null */
    private function getOrderRefundColumnsMap(): ?array
    {
        if ($this->orderRefundColumns !== null) {
            return $this->orderRefundColumns;
        }

        try {
            if (!Schema::hasTable('order_refunds')) {
                return null;
            }

            $columns = Schema::getColumnListing('order_refunds');
            $this->orderRefundColumns = array_fill_keys($columns, true);

            return $this->orderRefundColumns;
        } catch (\Throwable) {
            return null;
        }
    }
}
