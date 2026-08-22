<?php

namespace App\Services;

use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Enums\NotificationType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderRefundService
{
    public const FINANCE_SHIPPING_DECISION_MARKER = 'Finance must decide whether the paid shipping fee';

    /** @var array<string, bool>|null */
    private ?array $orderRefundColumns = null;

    private const CUSTOMER_CAUSED_DELIVERY_REASONS = [
        'recipient_unavailable',
        'wrong_or_incomplete_address',
        'recipient_refused',
    ];

    private const OPERATIONS_CAUSED_DELIVERY_REASONS = [
        'item_damaged',
        'vehicle_or_delivery_problem',
    ];

    public function __construct(
        private readonly PaymongoRefundService $paymongoRefundService,
        private readonly PaymentSettlementService $paymentSettlementService,
        private readonly ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService,
        private readonly NotificationService $notificationService,
        private readonly ?OrderRefundRecoveryService $orderRefundRecoveryService = null,
    ) {
    }

    public function reserveFailedDeliveryRefund(
        Order $order,
        ShipmentLeg $outboundLeg,
        ?string $failureReason = null,
    ): array
    {
        $order->loadMissing('items');
        $customerCaused = in_array($failureReason, self::CUSTOMER_CAUSED_DELIVERY_REASONS, true);
        $operationsCaused = in_array($failureReason, self::OPERATIONS_CAUSED_DELIVERY_REASONS, true);
        $shippingFee = round(min(
            max(0, (float) $order->shipping_fee),
            $this->resolveOrderCapturedAmount($order),
        ), 2);
        $feeLabel = number_format($shippingFee, 2, '.', ',');
        $reasonLabel = $failureReason ?: 'legacy_or_unknown';
        $reasonNote = match (true) {
            $customerCaused => "System-confirmed delivery attempts exhausted. The failure was customer-caused ({$reasonLabel}); the paid shipping fee of PHP {$feeLabel} was retained. Shop Owner approval was bypassed for this workflow.",
            $operationsCaused => "System-confirmed delivery attempts exhausted. The failure was operations-caused ({$reasonLabel}); this refund includes the paid shipping fee of PHP {$feeLabel}. Shop Owner approval was bypassed for this workflow.",
            $shippingFee > 0 => 'System-confirmed delivery attempts exhausted. ' . self::FINANCE_SHIPPING_DECISION_MARKER . " of PHP {$feeLabel} is refundable for {$reasonLabel}. The full remaining balance was requested. Shop Owner approval was bypassed for this workflow.",
            default => "System-confirmed delivery attempts exhausted for {$reasonLabel}. No paid shipping fee needs a separate Finance decision. Shop Owner approval was bypassed for this workflow.",
        };
        $lines = $order->items->map(fn ($item) => [
            'order_item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
            'requested_qty' => (int) $item->quantity,
            'approved_qty' => (int) $item->quantity,
            'unit_price_snapshot' => round((float) $item->price, 2),
            'line_amount' => 0,
            'inspection_disposition' => 'pending',
            'inventory_action' => 'pending',
        ])->all();

        return $this->reserveOrderRefund($order, [
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $order->shop_owner_id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'approved',
            'shop_owner_approved_at' => now(),
            'shop_owner_approved_by' => null,
            'finance_status' => 'pending',
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'staff_return_carrier' => 'Shop-owned logistics',
            'payment_gateway' => 'paymongo',
            'paymongo_payment_id' => $order->paymongo_payment_id,
            'currency' => 'PHP',
            'requested_refund_method' => 'original_payment_method',
            'reason_code' => 'delivery_attempts_exhausted',
            'reason_note' => $reasonNote,
            'exclude_shipping_fee' => $customerCaused,
            'idempotency_key' => "delivery-attempts-exhausted:{$order->id}:{$outboundLeg->id}",
            'requested_at' => now(),
        ], $lines);
    }

    public function reserveConfirmedLossRefund(Order $order, ShipmentLeg $outboundLeg, string $investigationNote): array
    {
        if (! $this->isEligibleForOnlineRefund($order)) {
            return [
                'result' => 'not_required',
                'message' => 'No gateway refund claim is required for this order.',
                'refund' => null,
            ];
        }

        $order->loadMissing('items');
        $lines = $order->items->map(fn ($item) => [
            'order_item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
            'requested_qty' => (int) $item->quantity,
            'approved_qty' => (int) $item->quantity,
            'unit_price_snapshot' => round((float) $item->price, 2),
            'line_amount' => 0,
            'inspection_disposition' => 'pending',
            'inventory_action' => 'pending',
        ])->all();

        return $this->reserveOrderRefund($order, [
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $order->shop_owner_id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'approved',
            'shop_owner_approved_at' => now(),
            'shop_owner_approved_by' => null,
            'finance_status' => 'pending',
            'return_status' => 'not_required',
            'payment_gateway' => 'paymongo',
            'paymongo_payment_id' => $order->paymongo_payment_id,
            'currency' => 'PHP',
            'requested_refund_method' => 'original_payment_method',
            'reason_code' => 'delivery_loss_confirmed',
            'reason_note' => 'Refund claim created after confirmed parcel loss. '.$investigationNote,
            'idempotency_key' => "delivery-loss-confirmed:{$order->id}:{$outboundLeg->id}",
            'requested_at' => now(),
        ], $lines);
    }

    public function reserveOrderRefund(Order $order, array $payload, array $lines = [], ?float $capturedAmount = null): array
    {
        return DB::transaction(function () use ($order, $payload, $lines, $capturedAmount) {
            $lockedOrder = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            $excludeShippingFee = (bool) ($payload['exclude_shipping_fee'] ?? false);
            unset($payload['exclude_shipping_fee']);
            $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

            $sameReservation = $idempotencyKey !== ''
                ? OrderRefund::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()
                : null;

            if ($sameReservation) {
                $this->reconcileRefundLines($sameReservation, $lines);

                return [
                    'result' => 'recovered',
                    'message' => 'Existing refund reservation recovered.',
                    'refund' => $sameReservation->fresh('items'),
                ];
            }

            $activeReservations = OrderRefund::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', ['requested', 'pending_approval', 'processing'])
                ->lockForUpdate()
                ->get();

            if ($activeReservations->isNotEmpty()) {
                return [
                    'result' => 'collision',
                    'message' => 'Another refund request already reserves this payment.',
                    'refund' => $activeReservations->first(),
                ];
            }

            $capturedTotal = $capturedAmount === null
                ? $this->resolveOrderCapturedAmount($lockedOrder)
                : round(max(0, $capturedAmount), 2);
            $succeededAmount = (float) OrderRefund::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'succeeded')
                ->lockForUpdate()
                ->sum('amount');
            $availableAmount = round(max(0, $capturedTotal - $succeededAmount), 2);
            $excludedShippingFee = $excludeShippingFee
                ? min(max(0, (float) $lockedOrder->shipping_fee), $availableAmount)
                : 0;
            $requestedAmount = array_key_exists('amount', $payload)
                ? round((float) $payload['amount'], 2)
                : round(max(0, $availableAmount - $excludedShippingFee), 2);

            if ($requestedAmount <= 0 || $requestedAmount > $availableAmount) {
                return [
                    'result' => 'collision',
                    'message' => 'Refund amount exceeds the remaining captured payment.',
                    'refund' => null,
                ];
            }

            $payload['order_id'] = $lockedOrder->id;
            $payload['customer_id'] = $payload['customer_id'] ?? $lockedOrder->customer_id;
            $payload['shop_owner_id'] = $payload['shop_owner_id'] ?? $lockedOrder->shop_owner_id;
            $payload['amount'] = $requestedAmount;
            $payload['requires_owner_approval'] = $this->resolveRefundApprovalSnapshot(
                $payload,
                (int) $payload['shop_owner_id'],
                $requestedAmount,
            );
            $refund = $this->createReservedRefund($payload, $lockedOrder->id);
            $this->reconcileRefundLines($refund, $lines);

            return [
                'result' => 'reserved',
                'message' => 'Refund amount reserved.',
                'refund' => $refund->fresh('items'),
            ];
        });
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

        $reservation = $this->reserveOrderRefund($order, [
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
            'currency' => 'PHP',
            'reason_code' => $resolvedReasonCode,
            'reason_note' => $mergedReasonNote !== '' ? $mergedReasonNote : null,
            'other_reason_note' => $otherReasonText !== '' ? $otherReasonText : null,
            'idempotency_key' => $idempotencyKey,
            'requested_at' => now(),
        ], [], $amount);

        if (($reservation['result'] ?? null) === 'collision') {
            return $reservation;
        }

        $refund = $reservation['refund'];
        $amount = round((float) $refund->amount, 2);

        $amountInCentavos = (int) round($amount * 100);

        $gatewayResult = $this->paymongoRefundService->createRefund(
            secretKey: $secretKey,
            paymentId: $paymentId,
            amountInCentavos: $amountInCentavos,
            reason: 'requested_by_customer',
        );

        $gatewayResult = $this->retryGatewayRefundWithCapturedAmount(
            gatewayResult: $gatewayResult,
            secretKey: $secretKey,
            paymentId: $paymentId,
            amountInCentavos: $amountInCentavos,
            refund: $refund,
            order: $order,
        );

        if (!($gatewayResult['success'] ?? false)) {
            $this->recoveryService()->recordFailure(
                refund: $refund,
                reason: (string) ($gatewayResult['message'] ?? 'Refund request failed'),
            );

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
            'reason_code' => $resolvedReasonCode,
            'reason_note' => $mergedReasonNote !== '' ? $mergedReasonNote : null,
            'other_reason_note' => $otherReasonText !== '' ? $otherReasonText : null,
        ]);

        if ($refundStatus === 'succeeded' && $refund->exists && $refund->getKey()) {
            $this->recoveryService()->recordSuccessfulExecution($refund, $refund->processed_by);
        }

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
        ?float $approvedAmount = null,
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

        $isExhaustedDeliveryRefund = (string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted';
        $financeShippingDecisionRequired = $isExhaustedDeliveryRefund
            && str_contains((string) ($refund->reason_note ?? ''), self::FINANCE_SHIPPING_DECISION_MARKER);
        if ($isExhaustedDeliveryRefund && strtolower(trim($stage)) === 'finance'
            && (string) ($refund->return_status ?? '') !== 'received') {
            return [
                'result' => 'invalid_state',
                'message' => 'Staff must receive and inspect the returned parcel before Finance approval.',
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
        if (!in_array($stageNormalized, ['staff', 'shop_owner', 'finance'], true)) {
            return [
                'result' => 'invalid_stage',
                'message' => 'Invalid approval stage.',
                'refund' => $refund,
            ];
        }

        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);
        if ($isExhaustedDeliveryRefund) {
            $requiresOwnerApproval = false;
        }

        $isIndividualRegistration = $this->isIndividualRegistrationType(
            (string) ($order->shopOwner?->registration_type ?? '')
        );
        $isCompanyCustomerRefund = strtolower(trim((string) ($order->shopOwner?->registration_type ?? ''))) === 'company'
            && !$isExhaustedDeliveryRefund;

        $payload = [
            'approved_at' => $refund->approved_at ?? now(),
            'processed_by' => $processedBy,
        ];
        $previousFinanceStatus = (string) ($refund->finance_status ?? 'pending');
        $previousShopOwnerStatus = (string) ($refund->shop_owner_status ?? 'pending');
        $wasPayoutExecutable = $this->canExecuteApprovedRefund($refund);

        if ($stageNormalized === 'finance' && $financeShippingDecisionRequired) {
            $fullAmount = round((float) ($refund->amount ?? 0), 2);
            $shippingFee = round(min(max(0, (float) ($order->shipping_fee ?? 0)), $fullAmount), 2);
            $productsOnlyAmount = round(max(0, $fullAmount - $shippingFee), 2);

            if ($approvedAmount === null) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Choose whether this refund includes the paid shipping fee.',
                    'refund' => $refund,
                ];
            }

            $approvedAmount = round($approvedAmount, 2);
            if (!in_array($approvedAmount, [$productsOnlyAmount, $fullAmount], true)) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Refund amount must be either products only or the full amount including shipping.',
                    'refund' => $refund,
                ];
            }

            $payload['amount'] = $approvedAmount;
            $payload['reason_note'] = trim((string) ($refund->reason_note ?? '') . "\n\nFinance shipping decision: "
                . ($approvedAmount === $fullAmount
                    ? "included PHP {$shippingFee} shipping fee."
                    : "retained PHP {$shippingFee} shipping fee."));
        } elseif ($approvedAmount !== null) {
            return [
                'result' => 'invalid_state',
                'message' => 'Refund amount cannot be changed for this request.',
                'refund' => $refund,
            ];
        }

        if ($approvalNote) {
            $payload['reason_note'] = trim((string) (($payload['reason_note'] ?? $refund->reason_note) ? ($payload['reason_note'] ?? $refund->reason_note) . "\n\n" : '') . 'Approval note: ' . $approvalNote);
        }

        if ($stageNormalized === 'staff') {
            if (!$isCompanyCustomerRefund) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Staff review is only available for company customer refunds.',
                    'refund' => $refund,
                ];
            }

            if ((string) ($refund->shop_owner_status ?? 'pending') !== 'pending') {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Staff has already reviewed this refund request.',
                    'refund' => $refund,
                ];
            }

            $payload['shop_owner_status'] = 'approved';
            $payload['shop_owner_approved_at'] = now();
            $payload['shop_owner_approved_by'] = $processedBy;
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

            if ($isCompanyCustomerRefund) {
                if ((string) ($refund->shop_owner_status ?? 'pending') !== 'approved') {
                    return [
                        'result' => 'invalid_state',
                        'message' => 'Staff approval is required before Finance authorization.',
                        'refund' => $refund,
                    ];
                }

                if ($financeStatus !== 'pending') {
                    return [
                        'result' => 'invalid_state',
                        'message' => 'Finance has already reviewed this refund request.',
                        'refund' => $refund,
                    ];
                }

                $payload['finance_status'] = 'approved';
                $payload['finance_approved_at'] = now();
                $payload['finance_approved_by'] = $processedBy;
            } elseif (!$requiresOwnerApproval) {
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

        $resolvedRefund = $freshRefund ?? $refund;
        if (!$wasPayoutExecutable && $this->canExecuteApprovedRefund($resolvedRefund)) {
            $this->notifyFinancePayoutReady($resolvedRefund);
        }

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
        } elseif ($stageNormalized === 'staff') {
            $nextMessage = 'Staff approval recorded. Awaiting Finance authorization.';
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
        if (!in_array($stageNormalized, ['staff', 'shop_owner', 'finance'], true)) {
            return [
                'result' => 'invalid_stage',
                'message' => 'Invalid rejection stage.',
                'refund' => $refund,
            ];
        }

        $isCompanyCustomerRefund = strtolower(trim((string) ($refund->order?->shopOwner?->registration_type ?? ''))) === 'company'
            && (string) ($refund->reason_code ?? '') !== 'delivery_attempts_exhausted';
        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);
        if ((string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted') {
            $requiresOwnerApproval = false;
        }

        if ($stageNormalized === 'finance') {
            if ($isCompanyCustomerRefund && (string) ($refund->shop_owner_status ?? 'pending') !== 'approved') {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Staff approval is required before Finance review.',
                    'refund' => $refund,
                ];
            }

            $financeStatus = strtolower(trim((string) ($refund->finance_status ?? 'pending')));
            if (!in_array($financeStatus, ['pending', 'approved_initial'], true)) {
                return [
                    'result' => 'already_' . $financeStatus,
                    'message' => 'Finance has already ' . $financeStatus . ' this refund request.',
                    'refund' => $refund,
                ];
            }
        }

        if ($stageNormalized === 'staff') {
            if (!$isCompanyCustomerRefund) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Staff review is only available for company customer refunds.',
                    'refund' => $refund,
                ];
            }

            if ((string) ($refund->shop_owner_status ?? 'pending') !== 'pending') {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Staff has already reviewed this refund request.',
                    'refund' => $refund,
                ];
            }
        }

        if ($stageNormalized === 'shop_owner') {
            if (!$requiresOwnerApproval) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Shop owner approval is not required by policy for this refund request.',
                    'refund' => $refund,
                ];
            }

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
        ];

        if (in_array($stageNormalized, ['staff', 'shop_owner'], true)) {
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

        if (strtolower(trim((string) ($shipmentData['delivery_method'] ?? ''))) === 'shop_owned') {
            return $this->arrangeStaffReturnPickup($refund, [
                'carrier_company' => 'Shop-owned logistics',
                'note' => $shipmentData['note'] ?? null,
            ]);
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

    public function ensureReturnShipment(OrderRefund $refund): \App\Models\Logistics\Shipment
    {
        return app(\App\Services\Logistics\SourceShipmentService::class)->ensureRefundReturnShipment($refund);
    }

    public function arrangeStaffReturnPickup(OrderRefund $refund, array $pickupData, ?int $staffId = null): array
    {
        $isShopOwnedPickup = strtolower((string) ($pickupData['carrier_company'] ?? $pickupData['carrier'] ?? '')) === 'shop-owned logistics';
        $arrange = function (OrderRefund $lockedRefund) use ($pickupData, $staffId, $isShopOwnedPickup) {
            if ((string) ($lockedRefund->shop_owner_status ?? 'pending') !== 'approved'
                || (string) ($lockedRefund->finance_status ?? 'pending') !== 'approved') {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Staff and Finance approvals must be completed before arranging return pickup.',
                    'refund' => $lockedRefund,
                ];
            }

            if ((string) ($lockedRefund->return_status ?? '') !== 'pending_customer_shipment') {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Return pickup has already been arranged or cannot be arranged in the current state.',
                    'refund' => $lockedRefund,
                ];
            }

            $staffShippedAt = $pickupData['shipped_at'] ?? null;
            $this->updateOrderRefundCompat($lockedRefund, [
                'return_status' => $staffShippedAt ? 'in_transit' : 'pending_staff_pickup',
                'staff_return_tracking_number' => $pickupData['tracking_number'] ?? $lockedRefund->staff_return_tracking_number,
                'staff_return_carrier' => $pickupData['carrier_company'] ?? ($pickupData['carrier'] ?? $lockedRefund->staff_return_carrier),
                'staff_return_rider_name' => $pickupData['rider_name'] ?? $lockedRefund->staff_return_rider_name,
                'staff_return_rider_phone' => $pickupData['rider_phone'] ?? $lockedRefund->staff_return_rider_phone,
                'staff_return_tracking_link' => $pickupData['tracking_link'] ?? $lockedRefund->staff_return_tracking_link,
                'staff_return_shipped_at' => $staffShippedAt,
                'return_arranged_by_staff_id' => $staffId,
                'return_arranged_by_staff_at' => now(),
                'return_source' => 'staff',
                'return_notes' => $pickupData['note'] ?? $lockedRefund->return_notes,
            ]);

            $resolvedRefund = $lockedRefund->fresh() ?? $lockedRefund;
            if ($lockedRefund->exists && $isShopOwnedPickup) {
                $this->ensureReturnShipment($resolvedRefund);
            }

            if ($lockedRefund->exists) {
                DB::afterCommit(fn () => $this->notifyCustomerForStaffPickup($resolvedRefund));
            } else {
                $this->notifyCustomerForStaffPickup($resolvedRefund);
            }

            return [
                'result' => 'pickup_arranged',
                'message' => $staffShippedAt
                    ? 'Staff pickup and shipment details saved successfully.'
                    : 'Staff pickup details saved successfully. Waiting for rider pickup.',
                'refund' => $resolvedRefund,
            ];
        };

        if (!$refund->exists) {
            return $arrange($refund);
        }

        return DB::transaction(function () use ($refund, $arrange) {
            $lockedRefund = OrderRefund::query()->lockForUpdate()->findOrFail($refund->id);

            return $arrange($lockedRefund);
        });
    }

    public function confirmReturnReceived(
        OrderRefund $refund,
        ?int $staffId,
        ?string $notes = null,
        ?array $lineDispositions = null,
    ): array
    {
        if ((string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted') {
            return $this->confirmFailedDeliveryReturnReceived($refund, $staffId, $notes, $lineDispositions);
        }

        $refund->loadMissing('order.shopOwner');
        if (strtolower(trim((string) ($refund->order?->shopOwner?->registration_type ?? ''))) === 'company') {
            return $this->confirmCompanyReturnReceived($refund, $staffId, $notes, $lineDispositions);
        }

        $isShopOwnedPickup = (string) ($refund->return_source ?? '') === 'staff'
            && strtolower((string) ($refund->staff_return_carrier ?? '')) === 'shop-owned logistics';

        if ($isShopOwnedPickup && (string) ($refund->return_status ?? '') === 'pending_staff_pickup') {
            return [
                'result' => 'invalid_state',
                'message' => 'Wait for the assigned rider to complete the return delivery before confirming receipt.',
                'refund' => $refund,
            ];
        }

        $returnStatus = (string) ($refund->return_status ?? 'awaiting_approval');
        $isStaffPickup = (string) ($refund->return_source ?? '') === 'staff';
        if ($returnStatus !== 'in_transit' && !($isStaffPickup && $returnStatus === 'pending_staff_pickup')) {
            return [
                'result' => 'invalid_state',
                'message' => 'Return cannot be confirmed in the current state.',
                'refund' => $refund,
            ];
        }

        return $this->confirmIndividualReturnReceived($refund, $staffId, $notes, $lineDispositions);
    }

    private function confirmIndividualReturnReceived(
        OrderRefund $refund,
        ?int $staffId,
        ?string $notes,
        ?array $lineDispositions,
    ): array {
        // Keep the legacy in-memory/fallback path usable when the inspection table has
        // not been installed yet. Persisted application returns always use inspection.
        if (! $refund->exists || ! Schema::hasTable('order_refund_items')) {
            $this->updateOrderRefundCompat($refund, [
                'return_status' => 'received',
                'return_confirmed_at' => now(),
                'return_confirmed_by_staff_id' => $staffId,
                'return_notes' => $notes ?? $refund->return_notes,
                'staff_return_shipped_at' => $refund->staff_return_shipped_at ?? now(),
            ]);

            $this->notifyFinancePayoutReady($refund->fresh() ?? $refund);

            return [
                'result' => 'received',
                'message' => 'Product return has been confirmed as received.',
                'refund' => $refund->fresh(),
            ];
        }

        return $this->confirmPersistedReturnReceived(
            refund: $refund,
            staffId: $staffId,
            notes: $notes,
            lineDispositions: $lineDispositions,
            allowPendingStaffPickup: true,
            invalidStateMessage: 'Return cannot be confirmed in the current state.',
        );
    }

    private function confirmCompanyReturnReceived(
        OrderRefund $refund,
        ?int $staffId,
        ?string $notes,
        ?array $lineDispositions,
    ): array {
        return $this->confirmPersistedReturnReceived(
            refund: $refund,
            staffId: $staffId,
            notes: $notes,
            lineDispositions: $lineDispositions,
            allowPendingStaffPickup: false,
            invalidStateMessage: 'Wait for the returned parcel before completing the Staff inspection.',
        );
    }

    private function confirmPersistedReturnReceived(
        OrderRefund $refund,
        ?int $staffId,
        ?string $notes,
        ?array $lineDispositions,
        bool $allowPendingStaffPickup,
        string $invalidStateMessage,
    ): array {
        return DB::transaction(function () use ($refund, $staffId, $notes, $lineDispositions, $allowPendingStaffPickup, $invalidStateMessage) {
            $refund = OrderRefund::query()->with('order.items')->lockForUpdate()->findOrFail($refund->id);
            $returnStatus = (string) ($refund->return_status ?? 'awaiting_approval');
            $isStaffPickup = (string) ($refund->return_source ?? '') === 'staff';
            $staffPickupAllowed = $allowPendingStaffPickup && $isStaffPickup && $returnStatus === 'pending_staff_pickup';
            if ($returnStatus !== 'in_transit' && ! $staffPickupAllowed) {
                return $this->invalidReturnReceipt($refund, $invalidStateMessage);
            }

            $lines = $refund->items()->lockForUpdate()->get();
            $orderItems = $refund->order->items->keyBy('id');
            $expectedOrderItemIds = $lines->isEmpty()
                ? $orderItems->keys()->map(fn ($id) => (int) $id)
                : $lines->pluck('order_item_id')->map(fn ($id) => (int) $id);
            $submitted = collect($lineDispositions ?? [])->filter(fn ($line) => is_array($line));
            $submittedIds = $submitted->pluck('order_item_id')->map(fn ($id) => (int) $id);

            if ($expectedOrderItemIds->isEmpty() || $submitted->count() !== $expectedOrderItemIds->count()
                || $submittedIds->unique()->count() !== $submitted->count()
                || $submittedIds->sort()->values()->all() !== $expectedOrderItemIds->sort()->values()->all()) {
                return $this->invalidReturnReceipt($refund, 'Every refund item must be inspected exactly once.');
            }

            $submittedById = $submitted->keyBy(fn ($line) => (int) ($line['order_item_id'] ?? 0));
            $linesByOrderItemId = $lines->keyBy(fn ($line) => (int) $line->order_item_id);
            $validatedLines = [];
            foreach ($expectedOrderItemIds as $orderItemId) {
                $line = $linesByOrderItemId->get($orderItemId);
                $orderItem = $orderItems->get($orderItemId);
                $input = $submittedById->get($orderItemId);
                $disposition = strtolower(trim((string) ($input['inspection_disposition'] ?? '')));
                $approvedQty = (int) ($input['approved_qty'] ?? 0);
                $expectedQty = (int) ($line?->approved_qty ?? $orderItem?->quantity ?? 0);

                if (!$orderItem || ($line && (int) $line->product_id !== (int) $orderItem->product_id)
                    || ($line && (int) ($line->product_variant_id ?? 0) !== (int) ($orderItem->product_variant_id ?? 0))
                    || $approvedQty !== $expectedQty || $approvedQty <= 0
                    || !in_array($disposition, ['resellable', 'damaged'], true)) {
                    return $this->invalidReturnReceipt($refund, 'Return item identity, quantity, or disposition is invalid.');
                }

                $validatedLines[] = compact('line', 'orderItem', 'approvedQty', 'disposition');
            }

            $inspectedLines = collect();
            foreach ($validatedLines as $validatedLine) {
                $line = $validatedLine['line'];
                $orderItem = $validatedLine['orderItem'];
                $approvedQty = $validatedLine['approvedQty'];
                $disposition = $validatedLine['disposition'];

                if ($line) {
                    $line->update([
                        'approved_qty' => $approvedQty,
                        'inspection_disposition' => $disposition,
                    ]);
                } else {
                    $unitPrice = round((float) ($orderItem->price ?? 0), 2);
                    $line = $refund->items()->create([
                        'order_item_id' => (int) $orderItem->id,
                        'product_id' => (int) $orderItem->product_id,
                        'product_variant_id' => $orderItem->product_variant_id,
                        'requested_qty' => $approvedQty,
                        'approved_qty' => $approvedQty,
                        'unit_price_snapshot' => $unitPrice,
                        'line_amount' => round($unitPrice * $approvedQty, 2),
                        'inspection_disposition' => $disposition,
                        'inventory_action' => 'pending',
                    ]);
                }

                $inspectedLines->push($line);
            }

            $inventoryDisposition = app(RefundInventoryDispositionService::class);
            foreach ($inspectedLines as $line) {
                $inventoryDisposition->applyOrderLine($line->fresh());
            }

            $refund->update([
                'return_status' => 'received',
                'return_confirmed_at' => now(),
                'return_confirmed_by_staff_id' => $staffId,
                'return_notes' => $notes ?? $refund->return_notes,
                'staff_return_shipped_at' => $refund->staff_return_shipped_at ?? now(),
            ]);

            $resolvedRefund = $refund->fresh() ?? $refund;
            DB::afterCommit(fn () => $this->notifyFinancePayoutReady($resolvedRefund));

            return [
                'result' => 'received',
                'message' => 'Every returned item was inspected. Finance may now release the refund.',
                'refund' => $resolvedRefund,
            ];
        });
    }

    private function confirmFailedDeliveryReturnReceived(
        OrderRefund $refund,
        ?int $staffId,
        ?string $notes,
        ?array $lineDispositions,
    ): array {
        return DB::transaction(function () use ($refund, $staffId, $notes, $lineDispositions) {
            $refund = OrderRefund::query()->with('order.items')->lockForUpdate()->findOrFail($refund->id);
            if ((string) $refund->return_status === 'received') {
                return ['result' => 'received', 'message' => 'Product return was already received.', 'refund' => $refund];
            }

            $outboundLegId = (int) str($refund->idempotency_key)->afterLast(':')->toString();
            $returnLeg = ShipmentLeg::query()->lockForUpdate()->where('return_for_leg_id', $outboundLegId)->first();
            if (!$returnLeg || $returnLeg->status->value !== 'delivered'
                || !$returnLeg->proofs()->where('handoff_type', 'receive')->where('review_status', 'approved')->exists()) {
                return $this->invalidReturnReceipt($refund, 'Wait for the rider return delivery and approved receive proof.');
            }

            $lines = $refund->items()->lockForUpdate()->get();
            $submitted = collect($lineDispositions ?? [])->filter(fn ($line) => is_array($line));
            $submittedIds = $submitted->pluck('order_item_id')->map(fn ($id) => (int) $id);
            $lineIds = $lines->pluck('order_item_id')->map(fn ($id) => (int) $id);

            if ($lines->isEmpty() || $submitted->count() !== $lines->count()
                || $submittedIds->unique()->count() !== $submitted->count()
                || $submittedIds->sort()->values()->all() !== $lineIds->sort()->values()->all()) {
                return $this->invalidReturnReceipt($refund, 'Every refund item must be inspected exactly once.');
            }

            $orderItems = $refund->order->items->keyBy('id');
            $submittedById = $submitted->keyBy(fn ($line) => (int) $line['order_item_id']);
            foreach ($lines as $line) {
                $orderItem = $orderItems->get((int) $line->order_item_id);
                $input = $submittedById->get((int) $line->order_item_id);
                $disposition = strtolower(trim((string) ($input['inspection_disposition'] ?? '')));
                $approvedQty = (int) ($input['approved_qty'] ?? 0);

                if (!$orderItem || (int) $line->product_id !== (int) $orderItem->product_id
                    || (int) ($line->product_variant_id ?? 0) !== (int) ($orderItem->product_variant_id ?? 0)
                    || $approvedQty !== (int) $line->approved_qty || $approvedQty !== (int) $orderItem->quantity
                    || !in_array($disposition, ['resellable', 'damaged'], true)) {
                    return $this->invalidReturnReceipt($refund, 'Return item identity, quantity, or disposition is invalid.');
                }

                $line->update(['inspection_disposition' => $disposition]);
            }

            $inventoryDisposition = app(RefundInventoryDispositionService::class);
            foreach ($lines as $line) {
                $inventoryDisposition->applyOrderLine($line->fresh());
            }

            $refund->update([
                'return_status' => 'received',
                'return_confirmed_at' => now(),
                'return_confirmed_by_staff_id' => $staffId,
                'return_notes' => $notes ?? $refund->return_notes,
                'staff_return_shipped_at' => $refund->staff_return_shipped_at ?? now(),
            ]);

            $resolvedRefund = $refund->fresh() ?? $refund;
            DB::afterCommit(fn () => $this->notifyFinancePayoutReady($resolvedRefund));

            return ['result' => 'received', 'message' => 'Product return has been received and inventory disposition applied.', 'refund' => $resolvedRefund];
        });
    }

    private function invalidReturnReceipt(OrderRefund $refund, string $message): array
    {
        return ['result' => 'invalid_state', 'message' => $message, 'refund' => $refund];
    }

    public function canExecuteApprovedRefund(OrderRefund $refund): bool
    {
        return (string) ($refund->shop_owner_status ?? 'pending') === 'approved'
            && (string) ($refund->finance_status ?? 'pending') === 'approved'
            && (string) ($refund->return_status ?? 'awaiting_approval') === 'received'
            && !in_array((string) ($refund->status ?? ''), [
                'processing',
                'succeeded',
                'failed',
                'rejected',
                'completed',
                'paid',
            ], true);
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

        if ((string) ($refund->return_status ?? 'awaiting_approval') !== 'received') {
            return [
                'result' => 'invalid_state',
                'message' => 'The returned parcel must be received before payout execution.',
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

    public function resolvePayoutAmount(OrderRefund $refund, ?Order $order = null): float
    {
        $amount = round(max(0, (float) ($refund->amount ?? 0)), 2);
        if ((string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted') {
            return $amount;
        }
        if ($refund->refund_executed_at) {
            return $amount;
        }

        $lineAmount = $this->resolveLineBasedRefundAmount($refund);
        if ($lineAmount > 0) {
            return $lineAmount;
        }

        $order ??= $refund->relationLoaded('order') ? $refund->order : $refund->order()->first();
        $shipping = round(max(0, (float) ($order?->shipping_fee ?? 0)), 2);
        if ($amount > 0) {
            return round(max(0, $amount - min($shipping, $amount)), 2);
        }

        return 0.0;
    }

    private function executeGatewayRefund(OrderRefund $refund, Order $order, ?int $processedBy = null, ?string $executionNote = null): array
    {
        $secretKey = (string) ($order->shopOwner?->paymongo_secret_key ?? '');
        if ($secretKey === '') {
            $this->recoveryService()->recordFailure(
                refund: $refund,
                reason: 'Payment gateway is not configured for this shop.',
                processedBy: $processedBy,
            );

            return [
                'result' => 'failed',
                'message' => 'Payment gateway is not configured for this shop.',
                'refund' => $refund->fresh(),
            ];
        }

        $paymentId = $this->resolvePaymentId($order, $secretKey);
        if (!$paymentId) {
            $this->recoveryService()->recordFailure(
                refund: $refund,
                reason: 'Unable to resolve payment reference for refund.',
                processedBy: $processedBy,
            );

            return [
                'result' => 'failed',
                'message' => 'Unable to resolve payment reference for refund.',
                'refund' => $refund->fresh(),
            ];
        }

        $amount = $this->resolvePayoutAmount($refund, $order);
        if ($amount > 0 && round((float) ($refund->amount ?? 0), 2) !== $amount) {
            $refund->update(['amount' => $amount]);
        }

        if ($amount <= 0) {
            $this->recoveryService()->recordFailure(
                refund: $refund,
                reason: 'Refund amount is invalid.',
                processedBy: $processedBy,
            );

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

        $gatewayResult = $this->retryGatewayRefundWithCapturedAmount(
            gatewayResult: $gatewayResult,
            secretKey: $secretKey,
            paymentId: $paymentId,
            amountInCentavos: $amountInCentavos,
            refund: $refund,
            order: $order,
            allowCapturedAmount: (float) ($order->shipping_fee ?? 0) <= 0
                || (string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted',
        );

        if (!($gatewayResult['success'] ?? false)) {
            $this->recoveryService()->recordFailure(
                refund: $refund,
                reason: (string) ($gatewayResult['message'] ?? 'Refund request failed'),
            );

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
        ]);

        if ($refundStatus === 'succeeded' && $refund->exists && $refund->getKey()) {
            $this->recoveryService()->recordSuccessfulExecution($refund, $refund->processed_by);
        }

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

        return str_contains($message, 'cannot partially refund for payments done on the same day');
    }

    private function retryGatewayRefundWithCapturedAmount(
        array $gatewayResult,
        string $secretKey,
        string $paymentId,
        int $amountInCentavos,
        OrderRefund $refund,
        Order $order,
        bool $allowCapturedAmount = true,
    ): array {
        if (! $allowCapturedAmount
            || ($gatewayResult['success'] ?? false)
            || ! $this->shouldRetryWithCapturedAmount($gatewayResult, $amountInCentavos)) {
            return $gatewayResult;
        }

        $capturedAmountInCentavos = $this->paymongoRefundService->getPaymentAmountInCentavos($secretKey, $paymentId);
        if ($capturedAmountInCentavos === null || $capturedAmountInCentavos <= $amountInCentavos) {
            return $gatewayResult;
        }

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
            $refund->update(['amount' => round($capturedAmountInCentavos / 100, 2)]);
        }

        return $gatewayResult;
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

    private function reconcileRefundLines(OrderRefund $refund, array $lines): void
    {
        if (!Schema::hasTable('order_refund_items') || empty($lines)) {
            return;
        }

        $orderItemIds = [];
        foreach ($lines as $line) {
            $orderItemId = (int) ($line['order_item_id'] ?? 0);
            if ($orderItemId <= 0) {
                continue;
            }

            $orderItemIds[] = $orderItemId;
            $refund->items()->updateOrCreate(
                ['order_item_id' => $orderItemId],
                collect($line)->except('order_item_id')->all()
            );
        }

        $refund->items()->whereNotIn('order_item_id', array_values(array_unique($orderItemIds)))->delete();
    }

    private function createReservedRefund(array $payload, int $orderId): OrderRefund
    {
        $filteredPayload = $this->filterOrderRefundPayload($payload);

        try {
            return OrderRefund::create($filteredPayload);
        } catch (QueryException $exception) {
            $fallbackPayload = $filteredPayload;
            if (($fallbackPayload['status'] ?? null) === 'pending_approval') {
                $fallbackPayload['status'] = 'requested';
            }
            unset(
                $fallbackPayload['requested_refund_method'],
                $fallbackPayload['evidence_media'],
                $fallbackPayload['other_reason_note']
            );

            Log::warning('Refund reservation retry with compatibility fallback after query exception', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            return OrderRefund::create($this->filterOrderRefundPayload($fallbackPayload));
        }
    }

    private function resolveRefundApprovalSnapshot(array $payload, int $shopOwnerId, float $amount): bool
    {
        if (($payload['flow_type'] ?? null) === 'cancel_auto'
            || ($payload['reason_code'] ?? null) === 'delivery_attempts_exhausted') {
            return false;
        }

        return $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRefund($shopOwnerId, $amount);
    }

    private function resolveOrderCapturedAmount(Order $order): float
    {
        $subtotal = max(0, (float) ($order->total_amount ?? 0));
        $shipping = max(0, (float) ($order->shipping_fee ?? 0));
        $vat = max(0, (float) ($order->vat_amount ?? 0));

        return round(max(
            (float) ($order->grand_total ?? 0),
            (float) ($order->total ?? 0),
            $subtotal + $shipping + $vat,
            $subtotal + $shipping,
            $subtotal,
        ), 2);
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

    private function notifyFinancePayoutReady(OrderRefund $refund): void
    {
        if (!$this->canExecuteApprovedRefund($refund)) {
            return;
        }

        $refund->loadMissing('order');
        $orderNumber = (string) ($refund->order?->order_number ?? ('#' . (int) ($refund->order_id ?? 0)));
        $payoutAmount = $this->resolvePayoutAmount($refund, $refund->order);
        $data = $this->buildRefundNotificationData($refund, [
            'stage' => 'payout_ready',
            'can_execute_payout' => true,
            'payout_amount' => number_format($payoutAmount, 2, '.', ''),
        ]);

        $this->notificationService->sendToErpRole(
            roleName: 'Finance',
            shopId: (int) ($refund->shop_owner_id ?? 0),
            type: NotificationType::REFUND_REQUEST,
            title: 'Refund Payout Ready',
            message: "Refund payout for order #{$orderNumber} is ready for execution.",
            data: $data,
            actionUrl: '/finance?section=refund-approvals',
            priority: 'high',
            groupKey: 'refund-payout-ready:order:' . (int) ($refund->id ?? 0),
            requiresAction: true,
        );
    }

    public function notifyRefundApprovalRequested(OrderRefund $refund): void
    {
        $refund->loadMissing('order.shopOwner', 'customer');
        $order = $refund->order;
        if (!$order) {
            return;
        }

        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);
        $isIndividualRegistration = $this->isIndividualRegistrationType(
            (string) ($order->shopOwner?->registration_type ?? '')
        );

        $data = $this->buildRefundNotificationData($refund, [
            'stage' => 'submitted',
            'requires_owner_approval' => $requiresOwnerApproval,
        ]);

        if ($isIndividualRegistration && $requiresOwnerApproval) {
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

    private function recoveryService(): OrderRefundRecoveryService
    {
        return $this->orderRefundRecoveryService ?? app(OrderRefundRecoveryService::class);
    }
}
