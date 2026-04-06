<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
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
            'repair_request_id' => ['nullable', 'integer', 'exists:repair_requests,id'],
            'due_type' => ['required', 'string', 'in:deposit,balance,full'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'customer_type' => ['required', 'string', 'in:registered,walk_in'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'walk_in_name' => ['nullable', 'string', 'max:255'],
            'walk_in_phone' => ['nullable', 'string', 'max:30'],
            'walk_in_email' => ['nullable', 'email', 'max:255'],
            'manual_repair_subtotal' => ['nullable', 'numeric', 'min:0.01'],
            'manual_service_summary' => ['nullable', 'string', 'max:2000'],
            'manual_payment_policy' => ['nullable', 'string', 'in:deposit_50,full_upfront'],
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

        $actor = $this->resolveActor();
        if (!$actor) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $actorShopOwnerId = $this->resolveActorShopOwnerId($actor);

        $repairRequestId = (int) ($validated['repair_request_id'] ?? 0);
        if ($repairRequestId > 0) {
            $repair = RepairRequest::findOrFail($repairRequestId);

            if (!$this->canActorCheckoutRepair($actor, $repair, $actorShopOwnerId)) {
                return response()->json([
                    'success' => false,
                    'code' => 'AUTH_FORBIDDEN_SHOP_SCOPE',
                    'message' => 'You are not allowed to process checkout for this repair request.',
                ], 403);
            }
        } else {
            if ((string) ($validated['customer_type'] ?? '') !== 'walk_in') {
                return response()->json([
                    'success' => false,
                    'message' => 'Manual POS checkout without a repair request currently supports walk-in customers only.',
                ], 422);
            }

            if (trim((string) ($validated['walk_in_name'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Walk-in customer name is required for manual POS checkout.',
                ], 422);
            }

            $manualSubtotal = (float) ($validated['manual_repair_subtotal'] ?? 0);
            if ($manualSubtotal <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Manual repair subtotal is required when checkout is not linked to a job order.',
                ], 422);
            }

            $repair = $this->createManualRepairRequestFromPos($validated, $actor, $actorShopOwnerId);
        }

        $auditActorId = $this->resolveActorAuditUserId();

        $transaction = $service->checkout($repair, $validated, $auditActorId);

        return response()->json([
            'success' => true,
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transaction_no,
            'meta' => [
                'idempotency_replay' => (bool) $transaction->getAttribute('idempotency_replay'),
            ],
        ]);
    }

    private function createManualRepairRequestFromPos(array $payload, object $actor, int $shopOwnerId): RepairRequest
    {
        if ($shopOwnerId <= 0) {
            throw ValidationException::withMessages([
                'shop_owner_id' => ['Unable to resolve shop scope for manual POS checkout.'],
            ]);
        }

        $counter = RepairRequest::query()
            ->whereDate('created_at', now()->toDateString())
            ->count() + 1;

        $requestId = sprintf('REP-POS-%s-%04d', now()->format('Ymd'), $counter);
        while (RepairRequest::query()->where('request_id', $requestId)->exists()) {
            $counter++;
            $requestId = sprintf('REP-POS-%s-%04d', now()->format('Ymd'), $counter);
        }

        $subtotal = round((float) ($payload['manual_repair_subtotal'] ?? 0), 2);
        $summary = trim((string) ($payload['manual_service_summary'] ?? 'Walk-in service from POS checkout.'));
        $walkInName = trim((string) ($payload['walk_in_name'] ?? 'Walk-in Customer'));
        $walkInPhone = trim((string) ($payload['walk_in_phone'] ?? 'N/A'));
        $walkInEmail = trim((string) ($payload['walk_in_email'] ?? ''));

        $snapshotServiceName = $summary !== '' ? $summary : 'Walk-in POS Service';
        $shopPolicy = (string) (ShopOwner::query()->whereKey($shopOwnerId)->value('repair_payment_policy') ?? 'deposit_50');
        $manualPolicy = (string) ($payload['manual_payment_policy'] ?? $shopPolicy);
        $resolvedPolicy = $manualPolicy === 'deposit_50' ? 'deposit_50' : 'full_upfront';

        return RepairRequest::create([
            'request_id' => $requestId,
            'customer_name' => $walkInName,
            'email' => $walkInEmail !== '' ? $walkInEmail : sprintf('walkin-pos-%s@local.invalid', strtolower(now()->format('YmdHis'))),
            'phone' => $walkInPhone !== '' ? $walkInPhone : 'N/A',
            'shoe_type' => 'Walk-in',
            'brand' => null,
            'description' => $snapshotServiceName,
            'shop_owner_id' => $shopOwnerId,
            'user_id' => null,
            'images' => [],
            'total' => $subtotal,
            'final_total' => $subtotal,
            'package_price' => null,
            'add_ons_total' => 0,
            'included_services_snapshot' => [[
                'id' => null,
                'name' => $snapshotServiceName,
                'category' => 'Walk-in POS',
                'price' => $subtotal,
                'duration' => null,
            ]],
            'add_on_services_snapshot' => null,
            'pricing_breakdown' => [
                'mode' => 'manual_pos',
                'package_id' => null,
                'package_name' => null,
                'included_services_total' => $subtotal,
                'package_price' => null,
                'add_ons_total' => 0,
                'base_total' => $subtotal,
                'materials_total' => 0,
                'final_total' => $subtotal,
            ],
            'payment_policy' => $resolvedPolicy,
            'payment_policy_snapshot' => $resolvedPolicy,
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
            'manual_pos_queue_enabled' => true,
            'delivery_method' => 'walk_in',
            'intake_delivery_method' => 'walk_in',
            'return_delivery_method' => 'walk_in',
            'status' => 'pending',
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

        $actor = $this->resolveActor();
        $actorId = $this->resolveActorId();

        if (!$actor || $actorId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $repair = RepairRequest::query()->find((int) $source->module_reference_id);
        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found for this transaction.',
            ], 404);
        }

        $isCustomerOwner = Auth::guard('user')->check() && (int) $repair->user_id === $actorId;
        $actorShopOwnerId = $this->resolveActorShopOwnerId($actor);
        $isShopActor = $actorShopOwnerId > 0 && $actorShopOwnerId === (int) $source->shop_owner_id;

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

        $refund = $service->requestRefund($source, $validated, $this->resolveActorAuditUserId());

        return response()->json([
            'success' => true,
            'refund_id' => $refund->id,
        ]);
    }

    public function listRefundQueue(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
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
        if (!Auth::guard('user')->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Only registered customers can view this refund list.',
            ], 403);
        }

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
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());

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

    public function listManualQueue(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        if ($shopOwnerId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $q = trim((string) $request->query('q', ''));

        $rows = RepairRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('manual_pos_queue_enabled', true)
            ->where('request_id', 'like', 'REP-POS-%')
            ->whereIn('status', ['pending', 'received', 'in_progress', 'ready_for_pickup'])
            ->with(['latestPosTransaction.receipt'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('request_id', 'like', "%{$q}%")
                        ->orWhere('customer_name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhereHas('latestPosTransaction.receipt', function ($receiptQuery) use ($q) {
                            $receiptQuery->where('receipt_no', 'like', "%{$q}%");
                        });
                });
            })
            ->orderByDesc('id')
            ->get();

        $data = $rows->map(function (RepairRequest $repair) {
            $total = round((float) ($repair->final_total ?? $repair->total ?? 0), 2);
            $paid = round((float) ($repair->total_paid_amount ?? 0), 2);
            $refunded = round((float) ($repair->total_refunded_amount ?? 0), 2);
            $remaining = max(0, round($total - $paid + $refunded, 2));

            $policy = (string) ($repair->payment_policy_snapshot ?? $repair->payment_policy ?? 'deposit_50');
            $normalizedPolicy = $policy === 'full_upfront' ? 'full_upfront' : 'deposit_50';
            $status = (string) $repair->status;
            $nextDueType = null;

            if ($remaining > 0) {
                if ($normalizedPolicy === 'deposit_50') {
                    if ($paid <= 0.0) {
                        $nextDueType = 'deposit';
                    } elseif ($status === 'ready_for_pickup') {
                        $nextDueType = 'balance';
                    }
                } else {
                    $nextDueType = 'full';
                }
            }

            return [
                'id' => (int) $repair->id,
                'request_id' => (string) $repair->request_id,
                'customer_name' => (string) $repair->customer_name,
                'phone' => (string) ($repair->phone ?? ''),
                'status' => $status,
                'payment_policy' => $normalizedPolicy,
                'total' => $total,
                'paid' => $paid,
                'refunded' => $refunded,
                'remaining_balance' => $remaining,
                'next_due_type' => $nextDueType,
                'receipt_no' => $repair->latestPosTransaction?->receipt?->receipt_no,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function updateManualQueueStatus(Request $request, int $repairId)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        if ($shopOwnerId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:received,in_progress,ready_for_pickup,picked_up'],
        ]);

        $repair = RepairRequest::query()
            ->where('id', $repairId)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('manual_pos_queue_enabled', true)
            ->firstOrFail();

        $allowedTransitions = [
            'pending' => 'received',
            'received' => 'in_progress',
            'in_progress' => 'ready_for_pickup',
            'ready_for_pickup' => 'picked_up',
        ];

        $currentStatus = (string) $repair->status;
        $targetStatus = (string) $validated['status'];

        if (!isset($allowedTransitions[$currentStatus]) || $allowedTransitions[$currentStatus] !== $targetStatus) {
            return response()->json([
                'success' => false,
                'message' => "Invalid transition from {$currentStatus} to {$targetStatus}.",
            ], 422);
        }

        $updates = ['status' => $targetStatus];
        if ($targetStatus === 'received') {
            $updates['received_at'] = now();
        }
        if ($targetStatus === 'in_progress') {
            $updates['started_at'] = now();
        }
        if ($targetStatus === 'ready_for_pickup') {
            $updates['completed_at'] = now();
        }
        if ($targetStatus === 'picked_up') {
            $updates['picked_up_at'] = now();
        }

        $repair->update($updates);

        return response()->json([
            'success' => true,
            'data' => $repair->fresh(),
        ]);
    }

    public function approveRefund(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = $this->resolveActor();
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
            actorId: $this->resolveActorAuditUserId(),
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
        $actor = $this->resolveActor();
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
            actorId: $this->resolveActorAuditUserId(),
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
        $actor = $this->resolveActor();
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
            actorId: $this->resolveActorAuditUserId(),
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

        $data = $service->verifyPaymentLine($line, $validated, $this->resolveActorAuditUserId());

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function canFinanceApproveRefund(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        $shopOwnerId = $this->resolveActorShopOwnerId($actor);
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

        $shopOwnerId = $this->resolveActorShopOwnerId($actor);
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

        $shopOwnerId = $this->resolveActorShopOwnerId($actor);
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

    private function resolveActor(): ?object
    {
        return Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();
    }

    private function resolveActorId(): int
    {
        return (int) (Auth::guard('user')->id() ?? Auth::guard('shop_owner')->id() ?? 0);
    }

    private function resolveActorAuditUserId(): int
    {
        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->id() ?? 0);
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return 0;
        }

        $shopOwnerId = (int) ($shopOwner->id ?? 0);
        if ($shopOwnerId <= 0) {
            return 0;
        }

        $shopOwnerEmail = trim((string) ($shopOwner->email ?? ''));
        if ($shopOwnerEmail !== '') {
            $matchedByEmail = (int) (User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('email', $shopOwnerEmail)
                ->value('id') ?? 0);

            if ($matchedByEmail > 0) {
                return $matchedByEmail;
            }
        }

        return (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    private function resolveActorShopOwnerId(?object $actor = null): int
    {
        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->user()?->shop_owner_id ?? 0);
        }

        return (int) ($actor?->shop_owner_id ?? 0);
    }

    private function canActorCheckoutRepair(object $actor, RepairRequest $repair, int $actorShopOwnerId): bool
    {
        if (!($actorShopOwnerId > 0 && $actorShopOwnerId === (int) $repair->shop_owner_id)) {
            return false;
        }

        if (Auth::guard('shop_owner')->check()) {
            $allowedStatuses = ['pending', 'ready_for_pickup', 'in_progress', 'completed', 'picked_up'];

            return in_array((string) $repair->status, $allowedStatuses, true);
        }

        return method_exists($actor, 'can') && $actor->can('posCheckout', $repair);
    }
}
