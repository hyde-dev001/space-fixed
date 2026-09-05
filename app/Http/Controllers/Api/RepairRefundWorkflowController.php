<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosRefund;
use App\Models\RepairRequest;
use App\Services\PaymentSettlementService;
use App\Services\RepairOnlineRefundWorkflowService;
use App\Services\RepairPosRefundService;
use App\Services\Orders\OrderRefundOwnerProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RepairRefundWorkflowController extends Controller
{
    public function __construct(
        private readonly OrderRefundOwnerProjection $orderRefundOwnerProjection,
    ) {}

    public function financeDeliveryReconciliations(Request $request, PaymentSettlementService $payments)
    {
        $actor = Auth::guard('user')->user();
        $search = strtolower(trim((string) $request->input('search', '')));
        $repairs = RepairRequest::query()
            ->with('user:id,name')
            ->where('shop_owner_id', (int) ($actor->shop_owner_id ?? 0))
            ->whereNotNull('logistics_payment_reconciliation')
            ->latest('id')
            ->get();

        $items = $repairs->flatMap(function (RepairRequest $repair) use ($payments): array {
            $reconciliation = is_array($repair->logistics_payment_reconciliation)
                ? $repair->logistics_payment_reconciliation
                : [];
            if ((string) data_get($reconciliation, 'status') !== 'pending') {
                return [];
            }

            return collect(data_get($reconciliation, 'entries', []))
                ->filter(fn ($entry): bool => is_array($entry)
                    && (string) ($entry['type'] ?? '') === 'delivery_compensation'
                    && in_array((string) ($entry['status'] ?? 'pending'), ['pending', 'processing'], true))
                ->map(function (array $entry) use ($repair, $payments): array {
                    $amount = round((float) ($entry['reconciliation_amount'] ?? 0), 2);

                    return [
                        'repair_id' => (int) $repair->id,
                        'request_id' => (string) $repair->request_id,
                        'customer_name' => (string) ($repair->customer_name ?: $repair->user?->name ?: 'Customer'),
                        'compensation_key' => (string) $entry['compensation_key'],
                        'phase' => (string) $entry['phase'],
                        'reason' => (string) ($entry['reason'] ?? 'delivery_unavailable'),
                        'amount' => $amount,
                        'status' => (string) ($entry['status'] ?? 'pending'),
                        'created_at' => $entry['created_at'] ?? null,
                        'can_credit_balance' => $payments->canCreditDeliveryCompensation($repair, $amount),
                    ];
                })
                ->values()
                ->all();
        })->when($search !== '', fn ($items) => $items->filter(
            fn (array $item): bool => str_contains(strtolower(
                $item['request_id'].' '.$item['customer_name'].' '.$item['phase']
            ), $search)
        ))->values();

        return response()->json(['data' => $items]);
    }

    public function resolveFinanceDeliveryReconciliation(
        Request $request,
        RepairRequest $repair,
        PaymentSettlementService $payments,
    ) {
        $actor = Auth::guard('user')->user();
        abort_unless((int) ($actor->shop_owner_id ?? 0) === (int) $repair->shop_owner_id, 403);
        $validated = $request->validate([
            'compensation_key' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:credit_balance,refund_original'],
        ]);

        $result = $payments->resolveRepairDeliveryReconciliation(
            $repair,
            (string) $validated['compensation_key'],
            (string) $validated['action'],
            (int) $actor->id,
        );

        return response()->json([
            'success' => true,
            'message' => $result['status'] === 'resolved'
                ? 'Delivery fee compensation resolved.'
                : 'Original-channel refund is still processing. The delivery plan remains locked.',
            'data' => [
                'status' => $result['status'],
                'repair_id' => (int) $repair->id,
                'entry' => $result['entry'],
            ],
        ], $result['status'] === 'processing' ? 202 : 200);
    }

    public function financeIndex(Request $request)
    {
        $actor = Auth::guard('user')->user();

        $query = $this->buildApprovalListQuery(
            request: $request,
            shopOwnerId: (int) ($actor->shop_owner_id ?? 0)
        );

        // Finance queue should only include online MyRepair refunds after repairer endorsement.
        $query->where(function ($builder) {
            $builder->whereNull('workflow_source')
                ->orWhere('workflow_source', '!=', 'online_myrepair')
                ->orWhere('repairer_status', 'approved');
        });

        $refunds = $query->get();

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

    public function ownerShow(Request $request, int $id)
    {
        $actor = Auth::guard('shop_owner')->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $refund = $this->buildApprovalListQuery($request, (int) $actor->id)
            ->whereKey($id)
            ->first();

        if (!$refund) {
            return response()->json(['message' => 'Repair refund not found'], 404);
        }

        return response()->json([
            'data' => $this->transformApprovalRefund($refund),
        ]);
    }

    public function repairerQueue(Request $request)
    {
        $actor = Auth::guard('user')->user();

        $refunds = PosRefund::query()
            ->where('module_type', 'repair')
            ->where('shop_owner_id', (int) ($actor->shop_owner_id ?? 0))
            ->where('repairer_status', 'pending')
            ->whereHas('repairRequest', function ($query) use ($actor): void {
                $query->where('shop_owner_id', (int) ($actor->shop_owner_id ?? 0))
                    ->where('assigned_repairer_id', (int) ($actor->id ?? 0));
            })
            ->latest('id')
            ->get();

        $data = $refunds->map(function (PosRefund $refund): array {
            $payload = $refund->toArray();
            $reasonNotes = PosRefund::normalizeReasonNotes($refund->reason_notes);
            $payload['reason_notes'] = $reasonNotes;
            $payload['reason_details'] = $reasonNotes;

            return $payload;
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function repairerApprove(Request $request, PosRefund $refund, RepairOnlineRefundWorkflowService $service)
    {
        $actor = Auth::guard('user')->user();
        $this->authorizeRepairerRefund($actor, $refund);

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
        $this->authorizeRepairerRefund($actor, $refund);

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
        $this->authorizeFinanceRefund($actor, $refund);

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
        $this->authorizeFinanceRefund($actor, $refund);

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
            'execution_proof_images' => ['nullable', 'array'],
            'execution_proof_images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];

        if ($executionMode === 'manual' && $hasPosManualLeg) {
            $rules['execution_channel'] = ['required', 'in:gcash,card,bank_transfer,manual_cash'];
            $rules['execution_reference'] = ['required', 'string', 'max:150'];
            $rules['execution_amount'] = ['required', 'numeric', 'min:0.01'];
            $rules['execution_proof_images'] = ['required', 'array', 'min:1'];
        }

        $validated = $request->validate($rules);
        $executionProofUrls = $this->resolveExecutionProofUrls($request, $refund);

        if ($executionMode === 'manual' && $hasPosManualLeg && empty($executionProofUrls)) {
            throw ValidationException::withMessages([
                'execution_proof_images' => ['At least one transaction screenshot is required for manual refund execution.'],
            ]);
        }

        $updated = $service->execute(
            refund: $refund,
            actorId: (int) $actor->id,
            executionMode: (string) ($validated['execution_mode'] ?? 'manual'),
            executionNote: $validated['execution_note'] ?? null,
            executionContext: [
                'execution_channel' => $validated['execution_channel'] ?? null,
                'execution_reference' => $validated['execution_reference'] ?? null,
                'execution_amount' => isset($validated['execution_amount']) ? (float) $validated['execution_amount'] : null,
                'execution_proof_urls' => $executionProofUrls,
            ],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function ownerApprove(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();
        $this->authorizeShopOwnerRefund($actor, $refund);

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
        $this->authorizeShopOwnerRefund($actor, $refund);

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

    public function ownerExecute(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('shop_owner')->user();

        if (!$actor || !$this->canOwnerExecute($actor, $refund)) {
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
            'execution_proof_images' => ['nullable', 'array'],
            'execution_proof_images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];

        if ($executionMode === 'manual' && $hasPosManualLeg) {
            $rules['execution_channel'] = ['required', 'in:gcash,card,bank_transfer,manual_cash'];
            $rules['execution_reference'] = ['required', 'string', 'max:150'];
            $rules['execution_amount'] = ['required', 'numeric', 'min:0.01'];
            $rules['execution_proof_images'] = ['required', 'array', 'min:1'];
        }

        $validated = $request->validate($rules);
        $executionProofUrls = $this->resolveExecutionProofUrls($request, $refund);

        if ($executionMode === 'manual' && $hasPosManualLeg && empty($executionProofUrls)) {
            throw ValidationException::withMessages([
                'execution_proof_images' => ['At least one transaction screenshot is required for manual refund execution.'],
            ]);
        }

        $updated = $service->execute(
            refund: $refund,
            actorId: (int) $actor->id,
            executionMode: (string) ($validated['execution_mode'] ?? 'manual'),
            executionNote: $validated['execution_note'] ?? null,
            executionContext: [
                'execution_channel' => $validated['execution_channel'] ?? null,
                'execution_reference' => $validated['execution_reference'] ?? null,
                'execution_amount' => isset($validated['execution_amount']) ? (float) $validated['execution_amount'] : null,
                'execution_proof_urls' => $executionProofUrls,
            ],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    private function canOwnerExecute(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        if ((int) ($actor->id ?? 0) !== (int) $refund->shop_owner_id) {
            return false;
        }

        return strtolower((string) ($actor->registration_type ?? '')) === 'individual';
    }

    private function authorizeRepairerRefund(?object $actor, PosRefund $refund): void
    {
        $authorized = $actor
            && (string) $refund->module_type === 'repair'
            && (int) ($actor->shop_owner_id ?? 0) === (int) $refund->shop_owner_id
            && RepairRequest::query()
                ->whereKey((int) $refund->module_reference_id)
                ->where('shop_owner_id', (int) $refund->shop_owner_id)
                ->where('assigned_repairer_id', (int) $actor->id)
                ->exists();

        abort_unless($authorized, 403);
    }

    private function authorizeFinanceRefund(?object $actor, PosRefund $refund): void
    {
        abort_unless($actor && $this->canFinanceExecute($actor, $refund), 403);
    }

    private function authorizeShopOwnerRefund(?object $actor, PosRefund $refund): void
    {
        $authorized = $actor
            && (string) $refund->module_type === 'repair'
            && (int) ($actor->id ?? 0) === (int) $refund->shop_owner_id;

        abort_unless($authorized, 403);
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

    private function resolveExecutionProofUrls(Request $request, PosRefund $refund): array
    {
        $executionProofUrls = [];

        $files = $request->file('execution_proof_images', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            $storedPath = $file->store("refund-evidence/execution/refund-{$refund->id}", 'public');
            $executionProofUrls[] = Storage::url($storedPath);
        }

        return array_values(array_unique($executionProofUrls));
    }

    private function buildApprovalListQuery(Request $request, int $shopOwnerId)
    {
        $query = PosRefund::query()
            ->with([
                'sourceTransaction:id,transaction_no,shop_owner_id,customer_type,customer_id,walk_in_name,module_type,module_reference_id,total_amount,paid_amount',
                'sourceTransaction.receipt:id,pos_transaction_id,receipt_no',
                'sourceTransaction.paymentLines:id,pos_transaction_id,tender_type,provider_reference,status',
                'repairRequest:id,request_id,customer_name,paymongo_payment_id',
                'requestedByUser:id,name',
                'legs:id,pos_refund_id,leg_type,requested_amount,approved_amount,status',
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

        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);

        $approvalStage = 'none';
        if ($financeStatus === 'pending') {
            $approvalStage = 'finance_initial';
        } elseif ($financeStatus === 'approved_initial' && $shopOwnerStatus === 'pending') {
            $approvalStage = 'shop_owner';
        } elseif ($financeStatus === 'approved' && in_array($shopOwnerStatus, ['approved', 'skipped'], true)) {
            $approvalStage = 'approved';
        }

        $approvalStageLabel = match ($approvalStage) {
            'finance_initial' => 'Waiting for Finance approval',
            'shop_owner' => 'Waiting for shop owner approval',
            'approved' => 'Ready for payout',
            default => null,
        };

        $uiStatus = match (true) {
            in_array($status, ['requested'], true) => 'Pending',
            in_array($status, ['rejected', 'failed', 'cancelled'], true) => 'Rejected',
            default => 'Approved',
        };

        $canExecutePayout = $financeStatus === 'approved'
            && in_array($shopOwnerStatus, ['approved', 'skipped'], true)
            && !in_array($status, ['processing', 'succeeded', 'failed', 'rejected', 'cancelled'], true);

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
        $reasonNotes = PosRefund::normalizeReasonNotes($refund->reason_notes);

        $legs = $refund->legs ?? collect();
        $hasGatewayLeg = $legs->contains(fn ($leg) => (string) ($leg->leg_type ?? '') === 'gateway' && (float) ($leg->requested_amount ?? 0) > 0);
        $hasPosManualLeg = $legs->contains(fn ($leg) => (string) ($leg->leg_type ?? '') === 'pos_manual' && (float) ($leg->requested_amount ?? 0) > 0);

        $refundPaymentType = 'manual_only';
        if ($hasGatewayLeg && $hasPosManualLeg) {
            $refundPaymentType = 'mixed';
        } elseif ($hasGatewayLeg) {
            $refundPaymentType = 'pure_online';
        } elseif (!$hasPosManualLeg) {
            $paymentLines = collect($source?->paymentLines ?? []);
            $hasStoredGatewayPaymentId = $this->looksLikeGatewayProviderReference((string) ($refund->paymongo_payment_id ?? $repair?->paymongo_payment_id ?? ''));

            $hasGatewayTender = $paymentLines->contains(function ($line) use ($hasStoredGatewayPaymentId) {
                if (!in_array((string) ($line->tender_type ?? ''), ['paymongo_card', 'paymongo_wallet'], true)) {
                    return false;
                }

                if ((string) ($line->status ?? '') !== 'paid') {
                    return false;
                }

                if ($hasStoredGatewayPaymentId) {
                    return true;
                }

                return $this->looksLikeGatewayProviderReference((string) ($line->provider_reference ?? ''));
            });

            $hasManualTender = $paymentLines->contains(function ($line) {
                return (string) ($line->status ?? '') === 'paid'
                    && !in_array((string) ($line->tender_type ?? ''), ['paymongo_card', 'paymongo_wallet'], true);
            });

            if ($hasGatewayTender && $hasManualTender) {
                $refundPaymentType = 'mixed';
            } elseif ($hasGatewayTender) {
                $refundPaymentType = 'pure_online';
            }
        }

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
            'refundNote' => $reasonNotes ?? '',
            'reason' => $reasonNotes ?? '',
            'reasonDetails' => $reasonNotes ?? '',
            'status' => $uiStatus,
            'rawStatus' => $status,
            'workflowSource' => (string) ($refund->workflow_source ?? 'pos'),
            'repairerStatus' => (string) ($refund->repairer_status ?? 'pending'),
            'shopOwnerStatus' => (string) ($refund->shop_owner_status ?? 'pending'),
            'financeStatus' => (string) ($refund->finance_status ?? 'pending'),
            'requiresOwnerApproval' => $requiresOwnerApproval,
            'approvalStage' => $approvalStage,
            'approvalStageLabel' => $approvalStageLabel,
            'sourceType' => 'repair',
            'repairNumber' => $repair?->request_id,
            'receiptNumber' => $receiptNo !== '' ? $receiptNo : null,
            'returnStatus' => null,
            'canExecutePayout' => $canExecutePayout,
            'refundExecutedAt' => optional($refund->executed_at)->toDateTimeString(),
            'refundedAt' => optional($refund->executed_at)->toDateTimeString(),
            'rejectionReason' => (string) ($refund->failure_reason ?? ''),
            'preferredReturnChannel' => (string) ($refund->preferred_return_channel ?? ''),
            'preferredReturnAccountName' => (string) ($refund->preferred_return_account_name ?? ''),
            'preferredReturnAccountRef' => (string) ($refund->preferred_return_account_ref ?? ''),
            'customerPayoutConsent' => (bool) ($refund->customer_payout_consent ?? false),
            'media' => $evidenceMedia,
            'refundPaymentType' => $refundPaymentType,
            'hasGatewayLeg' => $hasGatewayLeg,
            'hasPosManualLeg' => $hasPosManualLeg,
            'financeExecution' => [
                'execution_channel' => (string) ($refund->execution_channel ?? ''),
                'execution_reference' => (string) ($refund->execution_reference ?? ''),
                'execution_amount' => (float) ($refund->execution_amount ?? 0),
                'execution_proof_urls' => is_array($refund->execution_proof_urls) ? $refund->execution_proof_urls : [],
            ],
            'owner_projection' => $this->orderRefundOwnerProjection->project($refund),
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

    private function looksLikeGatewayProviderReference(?string $reference): bool
    {
        $value = strtolower(trim((string) ($reference ?? '')));
        if ($value === '') {
            return false;
        }

        return str_starts_with($value, 'pay_')
            || str_starts_with($value, 'pi_')
            || str_starts_with($value, 'src_')
            || str_starts_with($value, 'pmw_')
            || str_starts_with($value, 'pmc_');
    }
}
