<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Services\RepairPosPaymentService;
use App\Services\RepairPosRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RepairPosController extends Controller
{
    public function checkout(Request $request, RepairPosPaymentService $service)
    {
        $validated = $request->validate([
            'repair_request_id' => ['required', 'integer', 'exists:repair_requests,id'],
            'due_type' => ['required', 'string', 'in:deposit,balance,full'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'customer_type' => ['required', 'string', 'in:registered,walk_in'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'walk_in_name' => ['nullable', 'string', 'max:255'],
            'walk_in_phone' => ['nullable', 'string', 'max:30'],
            'walk_in_email' => ['nullable', 'email', 'max:255'],
            'payment_lines' => ['required', 'array', 'min:1'],
            'payment_lines.*.tender_type' => ['required', 'string', 'in:cash,paymongo_card,paymongo_wallet'],
            'payment_lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment_lines.*.provider_reference' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['payment_lines'] as $index => $line) {
            $isNonCash = in_array($line['tender_type'] ?? '', ['paymongo_card', 'paymongo_wallet'], true);
            $reference = trim((string) ($line['provider_reference'] ?? ''));

            if ($isNonCash && $reference === '') {
                throw ValidationException::withMessages([
                    "payment_lines.{$index}.provider_reference" => ['Reference is required for GCash/Card payments.'],
                ]);
            }
        }

        $repair = RepairRequest::findOrFail((int) $validated['repair_request_id']);
        $actor = Auth::guard('user')->user();

        if (!$actor || !$actor->can('posCheckout', $repair)) {
            return response()->json([
                'success' => false,
                'code' => 'AUTH_FORBIDDEN_SHOP_SCOPE',
                'message' => 'You are not allowed to process checkout for this repair request.',
            ], 403);
        }

        $actorId = (int) (Auth::guard('user')->id() ?? 0);

        $transaction = $service->checkout($repair, $validated, $actorId);

        return response()->json([
            'success' => true,
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transaction_no,
            'meta' => [
                'idempotency_replay' => (bool) $transaction->getAttribute('idempotency_replay'),
            ],
        ]);
    }

    public function showTransaction(PosTransaction $transaction)
    {
        return response()->json([
            'success' => true,
            'data' => $transaction->load(['paymentLines', 'receipt']),
        ]);
    }

    public function requestRefund(Request $request, RepairPosRefundService $service)
    {
        $validated = $request->validate([
            'source_transaction_id' => ['required', 'integer', 'exists:pos_transactions,id'],
            'request_type' => ['required', 'string', 'in:full,partial'],
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'reason_code' => ['required', 'string', 'max:100'],
            'reason_notes' => ['nullable', 'string', 'max:2000'],
            'receipt_no' => ['nullable', 'string', 'max:120'],
        ]);

        $source = PosTransaction::findOrFail((int) $validated['source_transaction_id']);
        if ($source->module_type !== 'repair') {
            return response()->json([
                'success' => false,
                'message' => 'Only repair transactions can be refunded from this endpoint.',
            ], 422);
        }

        $actor = Auth::guard('user')->user();
        $actorId = (int) ($actor?->id ?? 0);

        $repair = RepairRequest::query()->find((int) $source->module_reference_id);
        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found for this transaction.',
            ], 404);
        }

        $isCustomerOwner = (int) $repair->user_id === $actorId;
        $isShopActor = $actor && (int) ($actor->shop_owner_id ?? 0) > 0
            && (int) $actor->shop_owner_id === (int) $source->shop_owner_id;

        if (!$isCustomerOwner && !$isShopActor) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to request a refund for this transaction.',
            ], 403);
        }

        if ((string) $source->customer_type === 'walk_in' && $isShopActor) {
            $presentedReceiptNo = trim((string) ($validated['receipt_no'] ?? ''));
            if ($presentedReceiptNo === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Receipt number is required for walk-in refund requests.',
                    'errors' => [
                        'receipt_no' => ['Receipt number is required for walk-in refund requests.'],
                    ],
                ], 422);
            }

            $expectedReceiptNo = trim((string) ($source->receipt?->receipt_no ?? $source->transaction_no ?? ''));
            if ($expectedReceiptNo === '' || strcasecmp($presentedReceiptNo, $expectedReceiptNo) !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Presented receipt does not match the selected transaction.',
                    'errors' => [
                        'receipt_no' => ['Presented receipt does not match the selected transaction.'],
                    ],
                ], 422);
            }
        }

        $refund = $service->requestRefund($source, $validated, $actorId);

        return response()->json([
            'success' => true,
            'refund_id' => $refund->id,
        ]);
    }

    public function listRefundQueue(Request $request)
    {
        $user = Auth::guard('user')->user();
        $shopOwnerId = (int) ($user?->shop_owner_id ?? 0);
        $includeHistory = filter_var($request->query('include_history', false), FILTER_VALIDATE_BOOLEAN);

        $statuses = $includeHistory
            ? ['requested', 'approved', 'processing', 'succeeded', 'failed', 'rejected']
            : ['requested', 'approved', 'processing'];

        $refunds = PosRefund::query()
            ->where('module_type', 'repair')
            ->whereIn('status', $statuses)
            ->when($shopOwnerId > 0, fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
            ->with([
                'sourceTransaction:id,transaction_no,module_reference_id,paid_amount,paid_at',
                'repairRequest:id,request_id,customer_name,status,user_id',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $refunds,
        ]);
    }

    public function listMyRefunds(Request $request)
    {
        $actorId = (int) (Auth::guard('user')->id() ?? 0);
        if ($actorId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $repairRequestId = (int) $request->query('repair_request_id', 0);
        $customerRepairIds = RepairRequest::query()
            ->where('user_id', $actorId)
            ->pluck('id');

        $refunds = PosRefund::query()
            ->where('module_type', 'repair')
            ->whereIn('module_reference_id', $customerRepairIds)
            ->when($repairRequestId > 0, fn ($query) => $query->where('module_reference_id', $repairRequestId))
            ->with(['sourceTransaction:id,transaction_no,module_reference_id,paid_amount,paid_at'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $refunds,
        ]);
    }

    public function listTransactions(Request $request)
    {
        $repairRequestId = (int) $request->query('repair_request_id');
        $shopOwnerId = (int) (Auth::guard('user')->user()?->shop_owner_id ?? 0);

        $rows = PosTransaction::query()
            ->where('module_type', 'repair')
            ->when($shopOwnerId > 0, fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
            ->when($repairRequestId > 0, fn ($query) => $query->where('module_reference_id', $repairRequestId))
            ->with([
                'paymentLines',
                'receipt',
                'refunds' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function approveRefund(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();
        if (!$actor) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $approvalStage = $this->resolveApprovalStage($actor, $refund);
        if ($approvalStage === null) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to approve this refund.'], 403);
        }

        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:0.01'],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->approve(
            refund: $refund,
            actorId: (int) $actor->id,
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            approvalNote: $validated['approval_note'] ?? null,
            stage: $approvalStage,
        );

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    public function rejectRefund(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();
        if (!$actor) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $approvalStage = $this->resolveApprovalStage($actor, $refund);
        if ($approvalStage === null) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to reject this refund.'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->reject(
            refund: $refund,
            actorId: (int) $actor->id,
            rejectionReason: $validated['rejection_reason'],
            stage: $approvalStage,
        );

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    public function executeRefund(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();
        if (!$actor) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if (!$this->canRepairerExecuteRefund($actor, $refund)) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to execute this refund.'], 403);
        }

        $validated = $request->validate([
            'execution_mode' => ['nullable', 'string', 'in:manual,gateway'],
            'execution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $executionMode = strtolower((string) ($validated['execution_mode'] ?? 'manual'));
        $updated = $service->execute(
            refund: $refund,
            actorId: (int) $actor->id,
            executionMode: in_array($executionMode, ['manual', 'gateway'], true) ? $executionMode : 'manual',
            executionNote: $validated['execution_note'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    public function showReceipt(PosTransaction $transaction)
    {
        $transaction->load(['receipt', 'paymentLines']);

        if (!$transaction->receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found for this transaction.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction->receipt,
        ]);
    }

    public function verifyPaymentLine(Request $request, PosPaymentLine $line, RepairPosPaymentService $service)
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'mode' => ['required', 'string', 'in:gateway,manual_fallback'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data = $service->verifyPaymentLine($line, $validated, (int) (Auth::guard('user')->id() ?? 0));

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function canFinanceApproveRefund(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        $shopOwnerId = (int) ($actor->shop_owner_id ?? 0);
        if (!($shopOwnerId > 0 && $shopOwnerId === (int) $refund->shop_owner_id)) {
            return false;
        }

        if (method_exists($actor, 'can') && $actor->can('access-refund-approval')) {
            return true;
        }

        if (method_exists($actor, 'hasRole')) {
            if ($actor->hasRole('Staff') || $actor->hasRole('staff') || $actor->hasRole('Repairer') || $actor->hasRole('repairer')) {
                return false;
            }

            return $actor->hasRole('Finance')
                || $actor->hasRole('finance')
                || $actor->hasRole('Manager')
                || $actor->hasRole('manager')
                || $actor->hasRole('Shop Owner')
                || $actor->hasRole('shop owner');
        }

        return true;
    }

    private function canShopOwnerApproveRefund(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        if ((string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
            return false;
        }

        $shopOwnerId = (int) ($actor->shop_owner_id ?? 0);
        if (!($shopOwnerId > 0 && $shopOwnerId === (int) $refund->shop_owner_id)) {
            return false;
        }

        if (method_exists($actor, 'hasRole')) {
            return $actor->hasRole('Shop Owner')
                || $actor->hasRole('shop owner')
                || $actor->hasRole('Manager')
                || $actor->hasRole('manager');
        }

        return true;
    }

    private function resolveApprovalStage(object $actor, PosRefund $refund): ?string
    {
        if ($this->canShopOwnerApproveRefund($actor, $refund)) {
            return 'shop_owner';
        }

        if ($this->canFinanceApproveRefund($actor, $refund)) {
            return 'finance';
        }

        return null;
    }

    private function canRepairerExecuteRefund(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        $shopOwnerId = (int) ($actor->shop_owner_id ?? 0);
        if (!($shopOwnerId > 0 && $shopOwnerId === (int) $refund->shop_owner_id)) {
            return false;
        }

        if (method_exists($actor, 'hasRole')) {
            return $actor->hasRole('Staff')
                || $actor->hasRole('staff')
                || $actor->hasRole('Repairer')
                || $actor->hasRole('repairer')
                || $actor->hasRole('Manager')
                || $actor->hasRole('manager');
        }

        return false;
    }
}
