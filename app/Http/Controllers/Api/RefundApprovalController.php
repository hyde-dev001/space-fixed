<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodCollection;
use App\Models\OrderRefund;
use App\Services\OrderRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundApprovalController extends Controller
{
    public function __construct(
        private readonly OrderRefundService $orderRefundService,
    ) {
    }

    public function financeIndex(Request $request)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = $this->baseListQuery($request)
            ->where('shop_owner_id', (int) ($user->shop_owner_id ?? 0))
            ->where(function ($builder) {
                $builder->whereDoesntHave('order', function ($orderQuery) {
                    $orderQuery->whereIn('payment_method', ['cod', 'cash_on_delivery', 'cash on delivery', 'cash']);
                })->orWhere(function ($nested) {
                    $nested->whereHas('order', function ($orderQuery) {
                        $orderQuery->whereIn('payment_method', ['cod', 'cash_on_delivery', 'cash on delivery', 'cash']);
                    })->where('status', 'pending_approval');
                })->orWhere('shop_owner_status', 'approved');
            })
            ->where(function ($builder) {
                $builder->where('reason_code', '!=', 'delivery_attempts_exhausted')
                    ->orWhereNull('reason_code')
                    ->orWhere('return_status', 'received');
            });

        if (strtolower((string) $request->get('status', '')) === 'pending') {
            $query->where(function ($builder) {
                $builder->where('finance_status', 'pending')
                    ->orWhere(function ($nested) {
                        $nested->where('finance_status', 'approved_initial')
                            ->where('shop_owner_status', 'approved');
                    });
            });
        }

        $paginated = $query->paginate((int) $request->get('per_page', 50));
        $paginated->setCollection($paginated->getCollection()->map(function (OrderRefund $refund) {
            return $this->transformRefund($this->orderRefundService->reconcileCodRefundPayout($refund));
        }));

        return response()->json($paginated);
    }

    public function shopOwnerIndex(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $registrationType = strtolower(trim((string) ($shopOwner->registration_type ?? '')));
        $isIndividualRegistration = $registrationType === 'individual';

        $query = $this->baseListQuery($request)
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->where(function ($builder) {
                $builder->whereNull('requires_owner_approval')
                    ->orWhere('requires_owner_approval', true);
            });

        if (strtolower((string) $request->get('status', '')) === 'pending') {
            $query->where('shop_owner_status', 'pending');

            $financeStatuses = $isIndividualRegistration
                ? ['pending', 'approved_initial']
                : ['approved_initial'];
            $query->where(function ($builder) use ($financeStatuses) {
                $builder->where(function ($codQuery) {
                    $codQuery->whereHas('order', function ($orderQuery) {
                        $orderQuery->whereIn('payment_method', ['cod', 'cash_on_delivery', 'cash on delivery', 'cash']);
                    })->where('status', 'pending_approval')->where('finance_status', 'approved_initial');
                })->orWhere(function ($nonCodQuery) use ($financeStatuses) {
                    $nonCodQuery->whereDoesntHave('order', function ($orderQuery) {
                        $orderQuery->whereIn('payment_method', ['cod', 'cash_on_delivery', 'cash on delivery', 'cash']);
                    })->whereIn('finance_status', $financeStatuses);
                });
            });
        }

        $paginated = $query->paginate((int) $request->get('per_page', 50));
        $paginated->setCollection($paginated->getCollection()->map(fn (OrderRefund $refund) => $this->transformRefund($refund)));

        return response()->json($paginated);
    }

    public function shopOwnerShow(Request $request, int $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $refund = $this->baseListQuery($request)
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->where(function ($builder) {
                $builder->whereNull('requires_owner_approval')
                    ->orWhere('requires_owner_approval', true);
            })
            ->whereKey($id)
            ->first();

        if (!$refund) {
            return response()->json(['message' => 'Refund not found'], 404);
        }

        return response()->json($this->transformRefund($refund));
    }

    public function financeApprove(Request $request, int $id)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $refund = OrderRefund::query()
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) ($user->shop_owner_id ?? 0))
            ->firstOrFail();

        $validated = $request->validate([
            'approval_note' => 'nullable|string|max:1000',
            'approved_amount' => 'nullable|numeric|min:0.01',
        ]);

        $result = $this->orderRefundService->approveRequestedRefund(
            refund: $refund,
            stage: 'finance',
            processedBy: (int) $user->id,
            approvalNote: $validated['approval_note'] ?? null,
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
        );

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state', 'already_approved', 'already_refunded'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to approve refund request.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Refund request approved.',
            'refund' => $this->transformRefund($result['refund']),
        ]);
    }

    public function financeReject(Request $request, int $id)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $refund = OrderRefund::query()
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) ($user->shop_owner_id ?? 0))
            ->firstOrFail();

        $result = $this->orderRefundService->rejectRequestedRefund(
            refund: $refund,
            rejectionReason: $validated['rejection_reason'],
            stage: 'finance',
            processedBy: (int) $user->id,
        );

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state', 'already_approved', 'already_rejected'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to reject refund request.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Refund request rejected.',
            'refund' => $this->transformRefund($result['refund']),
        ]);
    }

    public function shopOwnerApprove(Request $request, int $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'approval_note' => 'nullable|string|max:1000',
        ]);

        $refund = OrderRefund::query()
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->firstOrFail();

        $result = $this->orderRefundService->approveRequestedRefund(
            refund: $refund,
            stage: 'shop_owner',
            processedBy: null,
            approvalNote: $validated['approval_note'] ?? null,
        );

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state', 'already_approved', 'already_refunded'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to approve refund request.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Refund request approved.',
            'refund' => $this->transformRefund($result['refund']),
        ]);
    }

    public function shopOwnerReject(Request $request, int $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $refund = OrderRefund::query()
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->firstOrFail();

        $result = $this->orderRefundService->rejectRequestedRefund(
            refund: $refund,
            rejectionReason: $validated['rejection_reason'],
            stage: 'shop_owner',
            processedBy: null,
        );

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state', 'already_approved', 'already_rejected'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to reject refund request.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Refund request rejected.',
            'refund' => $this->transformRefund($result['refund']),
        ]);
    }

    public function financeExecuteGatewayRefund(Request $request, int $id)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'execution_note' => 'nullable|string|max:1000',
        ]);

        $refund = OrderRefund::query()
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) ($user->shop_owner_id ?? 0))
            ->firstOrFail();

        $result = $this->orderRefundService->executeApprovedRefund(
            refund: $refund,
            processedBy: (int) $user->id,
            executionNote: $validated['execution_note'] ?? null,
        );

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to execute refund payout.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Refund payout execution has started.',
            'refund' => $this->transformRefund($result['refund']),
        ]);
    }

    public function revealCodRefundDestination(Request $request, int $id)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $refund = OrderRefund::query()
            ->with('order:id,payment_method')
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) ($user->shop_owner_id ?? 0))
            ->first();

        if (!$refund || !$this->isCodRefund($refund)) {
            return response()->json(['message' => 'COD refund destination not found.'], 404);
        }

        $destination = is_array($refund->refund_destination) ? $refund->refund_destination : [];
        $accountNumber = trim((string) ($destination['account_number'] ?? $destination['number'] ?? ''));
        if ($accountNumber === '') {
            return response()->json(['message' => 'COD refund destination not provided.'], 404);
        }

        activity('sensitive_refund_destinations')
            ->causedBy($user)
            ->performedOn($refund)
            ->withProperties([
                'refund_id' => (int) $refund->id,
                'shop_id' => (int) $refund->shop_owner_id,
                'destination_type' => (string) ($refund->refund_destination_type ?? ''),
            ])
            ->log('cod_refund_destination_revealed');

        return response()->json([
            'destination' => array_filter([
                'type' => $destination['type'] ?? null,
                'channel_code' => $destination['channel_code'] ?? null,
                'channel' => $destination['channel'] ?? $destination['bank'] ?? null,
                'account_name' => $destination['account_name'] ?? $destination['account_holder_name'] ?? null,
                'account_number' => $accountNumber,
            ], static fn (mixed $value): bool => filled($value)),
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function shopOwnerExecuteGatewayRefund(Request $request, int $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $registrationType = strtolower(trim((string) ($shopOwner->registration_type ?? '')));
        if ($registrationType === 'company') {
            return response()->json([
                'message' => 'Company accounts must execute refund payout via the Finance module.',
            ], 422);
        }

        $validated = $request->validate([
            'execution_note' => 'nullable|string|max:1000',
        ]);

        $refund = OrderRefund::query()
            ->where('id', $id)
            ->where('flow_type', 'request_approval')
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->firstOrFail();

        $result = $this->orderRefundService->executeApprovedRefund(
            refund: $refund,
            processedBy: null,
            executionNote: $validated['execution_note'] ?? null,
        );

        if (in_array((string) ($result['result'] ?? ''), ['already_processing', 'already_refunded'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Refund payout execution has already started for this request.',
                'refund' => $this->transformRefund($result['refund']),
            ], 409);
        }

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state'], true)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to execute refund payout.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Refund payout execution has started.',
            'refund' => $this->transformRefund($result['refund']),
        ]);
    }

    private function baseListQuery(Request $request)
    {
        $query = OrderRefund::query()
            ->with([
                'order:id,order_number,total_amount,shipping_fee,payment_method,total,grand_total,vat_amount',
                'order.deliveryDisputes:id,order_id,order_refund_id,evidence_media',
                'order.codCollection.remittanceItem.remittance',
                'customer:id,name',
            ])
            ->where('flow_type', 'request_approval');

        $statusFilter = strtolower((string) $request->get('status', ''));
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            if ($statusFilter === 'pending') {
                $query->whereIn('status', ['requested', 'pending_approval']);
            } elseif ($statusFilter === 'approved') {
                $query->whereIn('status', ['processing', 'succeeded']);
            } elseif ($statusFilter === 'rejected') {
                $query->where('status', 'rejected');
            }
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('order_number', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                    ->orWhere('reason_note', 'like', "%{$search}%")
                    ->orWhere('requested_refund_method', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('requested_at')->orderByDesc('id');

        return $query;
    }

    private function transformRefund(OrderRefund $refund): array
    {
        $refund->loadMissing([
            'order.codCollection.remittanceItem.remittance',
            'order.deliveryDisputes:id,order_id,order_refund_id,evidence_media',
            'customer',
            'items',
        ]);

        $order = $refund->order;
        $refundDispute = $order?->deliveryDisputes?->first(
            fn ($dispute): bool => (int) $dispute->order_refund_id === (int) $refund->id,
        );
        $evidenceSource = is_array($refund->evidence_media) && $refund->evidence_media !== []
            ? $refund->evidence_media
            : ($refundDispute?->evidence_media ?? []);
        $evidenceMedia = collect($evidenceSource)
            ->map(function (mixed $entry) use ($refundDispute): ?string {
                if (is_string($entry) && $entry !== '') {
                    return $entry;
                }

                if (! is_array($entry) || ! $refundDispute) {
                    return null;
                }

                $mediaId = trim((string) ($entry['id'] ?? ''));
                if ($mediaId === '') {
                    return null;
                }

                $url = route('api.logistics.delivery-disputes.evidence', [
                    'dispute' => $refundDispute->id,
                    'mediaId' => $mediaId,
                ]);

                return strtolower((string) ($entry['kind'] ?? '')) === 'video'
                    ? $url . '?media_kind=video'
                    : $url;
            })
            ->filter()
            ->values()
            ->all();
        $status = strtolower((string) $refund->status);
        $payoutFailureMessage = $refund->payout_failure_message;
        if (is_string($payoutFailureMessage) && str_contains($payoutFailureMessage, 'API_VALIDATION_ERROR')) {
            $payoutFailureMessage = 'Xendit rejected the COD payout details. Verify the selected channel and account number, then retry the payout.';
        }
        $shopOwnerStatus = strtolower((string) ($refund->shop_owner_status ?? 'pending'));
        $financeStatus = strtolower((string) ($refund->finance_status ?? 'pending'));
        $reasonLabel = $this->humanizeReason((string) $refund->reason_code);
        $reasonNote = trim((string) ($refund->reason_note ?? ''));
        $cleanReasonNote = trim((string) (preg_replace('/\bRefund scope:\s*(?:full|partial)\b.*$/i', '', $reasonNote) ?? $reasonNote));
        $otherReasonNote = trim((string) ($refund->other_reason_note ?? ''));
        $reasonDetails = $cleanReasonNote !== '' ? $cleanReasonNote : $reasonLabel;

        if ($otherReasonNote !== '' && stripos($reasonDetails, $otherReasonNote) === false) {
            $reasonDetails = trim($reasonDetails . ($reasonDetails !== '' ? "\n\n" : '') . 'Other reason note: ' . $otherReasonNote);
        }

        $isExhaustedDeliveryRefund = (string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted';
        $effectiveAmount = $this->orderRefundService->resolvePayoutAmount($refund, $order);
        $rawAmount = round((float) ($refund->amount ?? 0), 2);
        $shippingFee = round(min(max(0, (float) ($order->shipping_fee ?? 0)), $effectiveAmount), 2);
        $canAdjustRefundAmount = $isExhaustedDeliveryRefund
            && $financeStatus === 'pending'
            && $shippingFee > 0
            && str_contains($cleanReasonNote, OrderRefundService::FINANCE_SHIPPING_DECISION_MARKER);

        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);
        $paymentMethod = strtolower(trim((string) ($order?->payment_method ?? '')));
        $isCod = in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true);
        $codCollection = $order?->codCollection;
        $codRemittance = $codCollection?->remittanceItem?->remittance;
        $canExecutePayout = $this->orderRefundService->canExecuteApprovedRefund($refund);
        $codHasCollectedCash = $isCod && (float) ($codCollection?->collected_amount ?? 0) > 0;
        $codRemittanceIsSettled = $isCod
            && (string) ($codCollection?->status ?? '') === CodCollection::STATUS_SETTLED
            && (string) ($codRemittance?->status ?? '') === 'settled';
        $codHasRefundDestination = $isCod
            && is_array($refund->refund_destination)
            && $refund->refund_destination !== [];

        $approvalStage = 'none';
        if ($isCod && $status === 'requested' && $shopOwnerStatus === 'pending') {
            $approvalStage = 'staff';
        } elseif ($financeStatus === 'pending') {
            $approvalStage = 'finance_initial';
        } elseif ($financeStatus === 'approved_initial' && $shopOwnerStatus === 'pending') {
            $approvalStage = 'shop_owner';
        } elseif ($financeStatus === 'approved_initial' && $shopOwnerStatus === 'approved') {
            $approvalStage = 'finance_final';
        } elseif ($financeStatus === 'approved' && $shopOwnerStatus === 'approved') {
            $approvalStage = 'approved';
        }

        $uiStatus = match (true) {
            in_array($status, ['requested', 'pending_approval'], true) => 'Pending',
            $status === 'rejected' => 'Rejected',
            default => 'Approved',
        };

        $orderTotal = (float) ($order->total_amount ?? 0) + max(0, (float) ($order->shipping_fee ?? 0));
        if ($orderTotal <= 0) {
            $orderTotal = max((float) ($order->total ?? 0), (float) ($refund->amount ?? 0));
        }

        return [
            'id' => (int) $refund->id,
            'orderNumber' => (string) ($order->order_number ?? ('#' . $refund->order_id)),
            'customerName' => (string) ($refund->customer?->name ?? 'Unknown Customer'),
            'orderTotal' => '₱' . number_format($orderTotal, 2),
            'refundAmount' => '₱' . number_format($effectiveAmount, 2),
            'refundAmountValue' => $effectiveAmount,
            'payoutAmount' => '₱' . number_format($effectiveAmount, 2),
            'payoutAmountValue' => $effectiveAmount,
            'canAdjustRefundAmount' => $canAdjustRefundAmount,
            'refundAmountWithoutShipping' => $isExhaustedDeliveryRefund
                ? round(max(0, $rawAmount - min(max(0, (float) ($order->shipping_fee ?? 0)), $rawAmount)), 2)
                : $effectiveAmount,
            'shippingFee' => $shippingFee,
            'refundMethod' => $this->humanizeRefundMethod($refund->requested_refund_method),
            'originalPaymentMethod' => $isCod ? 'COD' : 'PayMongo',
            'isCod' => $isCod,
            'codCollectedAmount' => $isCod ? round((float) ($codCollection?->collected_amount ?? 0), 2) : null,
            'codCollectionStatus' => $isCod ? (string) ($codCollection?->status ?? 'not_collected') : null,
            'codRemittanceStatus' => $isCod ? (string) ($codRemittance?->status ?? 'not_submitted') : null,
            'codSettledAmount' => $isCod && (string) ($codRemittance?->status ?? '') === 'settled'
                ? round((float) ($codCollection?->collected_amount ?? 0), 2)
                : 0.0,
            'refundDestinationType' => $isCod ? (string) ($refund->refund_destination_type ?? '') : null,
            'refundDestination' => $isCod ? $refund->maskedRefundDestination() : null,
            'refundProvider' => $isCod ? (string) ($refund->refund_provider ?? 'xendit') : null,
            'payoutStatus' => (string) ($refund->payout_status ?? 'not_started'),
            'payoutFailureCode' => $refund->payout_failure_code,
            'payoutFailureMessage' => $payoutFailureMessage,
            'providerPayoutId' => $refund->provider_payout_id,
            'requestedBy' => (string) ($refund->customer?->name ?? 'Customer'),
            'requestDate' => optional($refund->requested_at)->format('Y-m-d') ?? optional($refund->created_at)->format('Y-m-d'),
            'refundReason' => $reasonLabel,
            'refundNote' => $cleanReasonNote,
            'otherReasonNote' => $otherReasonNote,
            'reason' => $reasonDetails,
            'status' => $uiStatus,
            'rawStatus' => $status,
            'shopOwnerStatus' => (string) ($refund->shop_owner_status ?? 'pending'),
            'financeStatus' => (string) ($refund->finance_status ?? 'pending'),
            'requiresOwnerApproval' => $requiresOwnerApproval,
            'approvalStage' => $approvalStage,
            'returnStatus' => (string) ($refund->return_status ?? 'awaiting_approval'),
            'canExecutePayout' => $canExecutePayout,
            'payoutBlockingReason' => $isCod && ! $canExecutePayout
                ? (! $codHasCollectedCash
                    ? 'No COD cash was collected; there is nothing to refund.'
                    : (! $codRemittanceIsSettled
                        ? 'COD remittance has not been settled by Finance.'
                        : (! $codHasRefundDestination
                            ? 'Customer has not provided a refund destination yet.'
                            : 'COD refund still requires both approvals and a received return.')))
                : null,
            'returnSource' => (string) ($refund->return_source ?? 'customer'),
            'refundExecutedAt' => optional($refund->refund_executed_at)->toDateTimeString(),
            'refundedAt' => optional($refund->refunded_at)->toDateTimeString(),
            'customerReturnTrackingNumber' => $refund->customer_return_tracking_number,
            'customerReturnCarrier' => $refund->customer_return_carrier,
            'customerReturnRiderName' => $refund->customer_return_rider_name,
            'customerReturnRiderPhone' => $refund->customer_return_rider_phone,
            'customerReturnTrackingLink' => $refund->customer_return_tracking_link,
            'customerReturnShippedAt' => optional($refund->customer_return_shipped_at)->toDateTimeString(),
            'staffReturnTrackingNumber' => $refund->staff_return_tracking_number,
            'staffReturnCarrier' => $refund->staff_return_carrier,
            'staffReturnRiderName' => $refund->staff_return_rider_name,
            'staffReturnRiderPhone' => $refund->staff_return_rider_phone,
            'staffReturnTrackingLink' => $refund->staff_return_tracking_link,
            'staffReturnShippedAt' => optional($refund->staff_return_shipped_at)->toDateTimeString(),
            'returnArrangedByStaffAt' => optional($refund->return_arranged_by_staff_at)->toDateTimeString(),
            'returnConfirmedAt' => optional($refund->return_confirmed_at)->toDateTimeString(),
            'rejectionReason' => $refund->rejection_reason,
            'media' => $evidenceMedia,
        ];
    }

    private function humanizeReason(string $reasonCode): string
    {
        if ($reasonCode === '') {
            return 'Customer refund request';
        }

        return ucwords(str_replace('_', ' ', strtolower($reasonCode)));
    }

    private function isCodRefund(OrderRefund $refund): bool
    {
        $paymentMethod = strtolower(trim((string) ($refund->order?->payment_method ?? '')));

        return in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true);
    }

    private function humanizeRefundMethod(?string $method): string
    {
        $normalized = strtolower(trim((string) $method));
        if ($normalized === '') {
            return 'Original Payment Method';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }
}
