<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderRefund;
use App\Services\OrderRefundService;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class RefundApprovalController extends Controller
{
    public function __construct(
        private readonly OrderRefundService $orderRefundService,
        private readonly ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService,
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
        $paginated->setCollection($paginated->getCollection()->map(fn (OrderRefund $refund) => $this->transformRefund($refund)));

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
            ->where('shop_owner_id', (int) $shopOwner->id);

        if (strtolower((string) $request->get('status', '')) === 'pending') {
            $query->where('shop_owner_status', 'pending');

            if ($isIndividualRegistration) {
                $query->whereIn('finance_status', ['pending', 'approved_initial']);
            } else {
                $query->where('finance_status', 'approved_initial');
            }
        }

        $paginated = $query->paginate((int) $request->get('per_page', 50));
        $paginated->setCollection($paginated->getCollection()->map(fn (OrderRefund $refund) => $this->transformRefund($refund)));

        return response()->json($paginated);
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
            ->with(['order:id,order_number,total_amount,shipping_fee', 'customer:id,name'])
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
        $refund->loadMissing(['order', 'customer', 'items']);

        $order = $refund->order;
        $status = strtolower((string) $refund->status);
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

        $lineBasedAmount = 0.0;
        if (Schema::hasTable('order_refund_items')) {
            $lineBasedAmount = round((float) $refund->items->sum(fn ($line) => (float) ($line->line_amount ?? 0)), 2);
        }

        $isExhaustedDeliveryRefund = (string) ($refund->reason_code ?? '') === 'delivery_attempts_exhausted';
        $effectiveAmount = !$isExhaustedDeliveryRefund && $lineBasedAmount > 0
            ? $lineBasedAmount
            : round((float) ($refund->amount ?? 0), 2);
        $shippingFee = round(min(max(0, (float) ($order->shipping_fee ?? 0)), $effectiveAmount), 2);
        $canAdjustRefundAmount = $isExhaustedDeliveryRefund
            && $financeStatus === 'pending'
            && $shippingFee > 0
            && str_contains($cleanReasonNote, OrderRefundService::FINANCE_SHIPPING_DECISION_MARKER);

        $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRefund(
            (int) ($refund->shop_owner_id ?? 0),
            $effectiveAmount
        );

        $approvalStage = 'none';
        if ($financeStatus === 'pending') {
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
            'canAdjustRefundAmount' => $canAdjustRefundAmount,
            'refundAmountWithoutShipping' => round(max(0, $effectiveAmount - $shippingFee), 2),
            'shippingFee' => $shippingFee,
            'refundMethod' => $this->humanizeRefundMethod($refund->requested_refund_method),
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
            'media' => is_array($refund->evidence_media) ? $refund->evidence_media : [],
        ];
    }

    private function humanizeReason(string $reasonCode): string
    {
        if ($reasonCode === '') {
            return 'Customer refund request';
        }

        return ucwords(str_replace('_', ' ', strtolower($reasonCode)));
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
