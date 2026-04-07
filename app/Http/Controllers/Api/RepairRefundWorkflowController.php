<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosRefund;
use App\Services\ShopOwnerApprovalPolicyService;
use App\Services\RepairOnlineRefundWorkflowService;
use App\Services\RepairPosRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairRefundWorkflowController extends Controller
{
    public function financeIndex(Request $request)
    {
        $actor = Auth::guard('user')->user();

        $refunds = $this->buildApprovalListQuery(
            request: $request,
            shopOwnerId: (int) ($actor->shop_owner_id ?? 0)
        )->get();

        return response()->json([
            'data' => $refunds->map(fn (PosRefund $refund) => $this->transformApprovalRefund($refund))->values(),
        ]);
    }

    public function ownerIndex(Request $request)
    {
        $actor = Auth::guard('shop_owner')->user();

        $refunds = $this->buildApprovalListQuery(
            request: $request,
            shopOwnerId: (int) ($actor->id ?? 0)
        )->get();

        return response()->json([
            'data' => $refunds->map(fn (PosRefund $refund) => $this->transformApprovalRefund($refund))->values(),
        ]);
    }

    public function repairerQueue(Request $request)
    {
        $actor = Auth::guard('user')->user();

        $refunds = PosRefund::query()
            ->where('module_type', 'repair')
            ->where('shop_owner_id', (int) ($actor->shop_owner_id ?? 0))
            ->where('repairer_status', 'pending')
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $refunds,
        ]);
    }

    public function repairerApprove(Request $request, PosRefund $refund, RepairOnlineRefundWorkflowService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'assessment_note' => ['required', 'string', 'max:2000'],
            'approved_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $updated = $service->repairerApprove(
            refund: $refund,
            actorId: (int) $actor->id,
            assessmentNote: (string) $validated['assessment_note'],
            approvedAmount: (float) $validated['approved_amount'],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function repairerReject(Request $request, PosRefund $refund, RepairOnlineRefundWorkflowService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'assessment_note' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->repairerReject(
            refund: $refund,
            actorId: (int) $actor->id,
            assessmentNote: (string) $validated['assessment_note'],
            reason: (string) $validated['reason'],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function financeApprove(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:0.01'],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->approve(
            refund: $refund,
            actorId: (int) $actor->id,
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            approvalNote: $validated['approval_note'] ?? null,
            stage: 'finance',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function financeReject(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->reject(
            refund: $refund,
            actorId: (int) $actor->id,
            rejectionReason: (string) $validated['reason'],
            stage: 'finance',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function financeExecute(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();

        if (!$this->canFinanceExecute($actor, $refund)) {
            abort(403, 'Not authorized to execute this refund.');
        }

        $executionMode = (string) $request->input('execution_mode', 'manual');
        $hasPosManualLeg = $refund->legs()->where('leg_type', 'pos_manual')->exists();

        $rules = [
            'execution_mode' => ['nullable', 'in:manual,gateway'],
            'execution_note' => ['nullable', 'string', 'max:1000'],
            'execution_channel' => ['nullable', 'in:gcash,card,bank_transfer,manual_cash'],
            'execution_reference' => ['nullable', 'string', 'max:150'],
            'execution_amount' => ['nullable', 'numeric', 'min:0.01'],
            'execution_proof_urls' => ['nullable', 'array', 'min:1'],
            'execution_proof_urls.*' => ['url'],
        ];

        if ($executionMode === 'manual' && $hasPosManualLeg) {
            $rules['execution_channel'] = ['required', 'in:gcash,card,bank_transfer,manual_cash'];
            $rules['execution_reference'] = ['required', 'string', 'max:150'];
            $rules['execution_amount'] = ['required', 'numeric', 'min:0.01'];
            $rules['execution_proof_urls'] = ['required', 'array', 'min:1'];
        }

        $validated = $request->validate($rules);

        $updated = $service->execute(
            refund: $refund,
            actorId: (int) $actor->id,
            executionMode: (string) ($validated['execution_mode'] ?? 'manual'),
            executionNote: $validated['execution_note'] ?? null,
            executionContext: [
                'execution_channel' => $validated['execution_channel'] ?? null,
                'execution_reference' => $validated['execution_reference'] ?? null,
                'execution_amount' => isset($validated['execution_amount']) ? (float) $validated['execution_amount'] : null,
                'execution_proof_urls' => $validated['execution_proof_urls'] ?? [],
            ],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function ownerApprove(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:0.01'],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->approve(
            refund: $refund,
            actorId: (int) ($actor->id ?? 0),
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            approvalNote: $validated['approval_note'] ?? null,
            stage: 'shop_owner',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function ownerReject(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->reject(
            refund: $refund,
            actorId: (int) ($actor->id ?? 0),
            rejectionReason: (string) $validated['reason'],
            stage: 'shop_owner',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    private function canFinanceExecute(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        if ((int) ($actor->shop_owner_id ?? 0) !== (int) $refund->shop_owner_id) {
            return false;
        }

        return method_exists($actor, 'can')
            ? $actor->can('access-refund-approval')
            : true;
    }

    private function buildApprovalListQuery(Request $request, int $shopOwnerId)
    {
        $query = PosRefund::query()
            ->with([
                'sourceTransaction:id,transaction_no,shop_owner_id,customer_type,customer_id,walk_in_name,module_type,module_reference_id,total_amount,paid_amount',
                'sourceTransaction.receipt:id,pos_transaction_id,receipt_no',
                'sourceTransaction.paymentLines:id,pos_transaction_id,tender_type',
                'repairRequest:id,customer_name',
                'requestedByUser:id,name',
            ])
            ->where('module_type', 'repair')
            ->where('shop_owner_id', $shopOwnerId);

        $statusFilter = strtolower((string) $request->get('status', ''));
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            if ($statusFilter === 'pending') {
                $query->whereIn('status', ['requested']);
            } elseif ($statusFilter === 'approved') {
                $query->whereIn('status', ['approved', 'processing', 'succeeded']);
            } elseif ($statusFilter === 'rejected') {
                $query->whereIn('status', ['rejected', 'failed', 'cancelled']);
            }
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('refund_no', 'like', "%{$search}%")
                    ->orWhere('reason_notes', 'like', "%{$search}%")
                    ->orWhereHas('sourceTransaction', function ($transactionQuery) use ($search) {
                        $transactionQuery->where('transaction_no', 'like', "%{$search}%")
                            ->orWhere('walk_in_name', 'like', "%{$search}%")
                            ->orWhereHas('receipt', fn ($receiptQuery) => $receiptQuery->where('receipt_no', 'like', "%{$search}%"));
                    })
                    ->orWhereHas('repairRequest', fn ($repairQuery) => $repairQuery->where('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('requestedByUser', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest('requested_at')->latest('id');
    }

    private function transformApprovalRefund(PosRefund $refund): array
    {
        $source = $refund->sourceTransaction;
        $repair = $refund->repairRequest;
        $receiptNo = (string) ($source?->receipt?->receipt_no ?? '');
        $transactionNo = (string) ($source?->transaction_no ?? '');

        $customerName = (string) (
            $repair?->customer_name
            ?? $source?->walk_in_name
            ?? $refund->requestedByUser?->name
            ?? 'Customer'
        );

        $requestedBy = (string) ($refund->requestedByUser?->name ?? $customerName);

        $financeStatus = strtolower((string) ($refund->finance_status ?? 'pending'));
        $shopOwnerStatus = strtolower((string) ($refund->shop_owner_status ?? 'pending'));
        $status = strtolower((string) $refund->status);

        $requiresOwnerApproval = app(ShopOwnerApprovalPolicyService::class)
            ->requiresOwnerApprovalForRefund((int) $refund->shop_owner_id, (float) ($refund->requested_amount ?? 0));

        $approvalStage = 'none';
        if ($financeStatus === 'pending') {
            $approvalStage = 'finance_initial';
        } elseif ($financeStatus === 'approved_initial' && $shopOwnerStatus === 'pending') {
            $approvalStage = 'shop_owner';
        } elseif ($financeStatus === 'approved' && in_array($shopOwnerStatus, ['approved', 'skipped'], true)) {
            $approvalStage = 'approved';
        }

        $uiStatus = match (true) {
            in_array($status, ['requested'], true) => 'Pending',
            in_array($status, ['rejected', 'failed', 'cancelled'], true) => 'Rejected',
            default => 'Approved',
        };

        $evidenceSnapshot = is_array($refund->evidence_snapshot) ? $refund->evidence_snapshot : [];
        $evidenceCandidates = [];

        if (array_is_list($evidenceSnapshot)) {
            $evidenceCandidates = $evidenceSnapshot;
        } elseif (isset($evidenceSnapshot['media']) && is_array($evidenceSnapshot['media'])) {
            $evidenceCandidates = $evidenceSnapshot['media'];
        } elseif (isset($evidenceSnapshot['images']) && is_array($evidenceSnapshot['images'])) {
            $evidenceCandidates = $evidenceSnapshot['images'];
        }

        $evidenceMedia = array_values(array_filter(array_map(function ($item) {
            if (is_string($item)) {
                return trim($item);
            }

            if (!is_array($item)) {
                return null;
            }

            $candidate = (string) ($item['url'] ?? $item['path'] ?? $item['src'] ?? '');
            return trim($candidate) !== '' ? trim($candidate) : null;
        }, $evidenceCandidates), fn ($item) => is_string($item) && $item !== ''));

        return [
            'id' => (int) $refund->id,
            'refundType' => 'repair',
            'orderNumber' => $receiptNo !== '' ? $receiptNo : ($transactionNo !== '' ? $transactionNo : (string) $refund->refund_no),
            'customerName' => $customerName,
            'orderTotal' => '₱' . number_format((float) ($source?->total_amount ?? $refund->requested_amount), 2),
            'refundAmount' => '₱' . number_format((float) ($refund->requested_amount ?? 0), 2),
            'refundMethod' => $this->resolveRefundMethodLabel($source?->paymentLines?->pluck('tender_type')->first()),
            'requestedBy' => $requestedBy,
            'requestDate' => optional($refund->requested_at ?? $refund->created_at)->format('Y-m-d'),
            'refundReason' => ucwords(str_replace('_', ' ', (string) ($refund->reason_code ?? 'repair_refund'))),
            'refundNote' => (string) ($refund->reason_notes ?? ''),
            'reason' => (string) ($refund->reason_notes ?? ''),
            'status' => $uiStatus,
            'rawStatus' => $status,
            'shopOwnerStatus' => (string) ($refund->shop_owner_status ?? 'pending'),
            'financeStatus' => (string) ($refund->finance_status ?? 'pending'),
            'requiresOwnerApproval' => $requiresOwnerApproval,
            'approvalStage' => $approvalStage,
            'returnStatus' => 'received',
            'refundExecutedAt' => optional($refund->executed_at)->toDateTimeString(),
            'refundedAt' => optional($refund->executed_at)->toDateTimeString(),
            'rejectionReason' => (string) ($refund->failure_reason ?? ''),
            'media' => $evidenceMedia,
            'financeExecution' => [
                'execution_channel' => (string) ($refund->execution_channel ?? ''),
                'execution_reference' => (string) ($refund->execution_reference ?? ''),
                'execution_amount' => (float) ($refund->execution_amount ?? 0),
                'execution_proof_urls' => is_array($refund->execution_proof_urls) ? $refund->execution_proof_urls : [],
            ],
        ];
    }

    private function resolveRefundMethodLabel(?string $tenderType): string
    {
        return match (strtolower((string) $tenderType)) {
            'paymongo_card' => 'Credit Card',
            'paymongo_wallet' => 'GCash',
            'cash' => 'Cash',
            default => 'Original Payment Method',
        };
    }
}
