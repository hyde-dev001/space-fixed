<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ExpenseApprovalService;
use App\Services\Finance\ExpenseSettlementService;
use App\Support\Finance\FinanceShopContext;
use App\Support\Finance\FinanceErrorResponse;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ExpenseController extends Controller
{
    protected NotificationService $notificationService;
    protected ExpenseApprovalService $expenseApprovalService;
    protected ExpenseSettlementService $expenseSettlementService;
    protected FinanceShopContext $shopContext;

    public function __construct(
        NotificationService $notificationService,
        ExpenseApprovalService $expenseApprovalService,
        ExpenseSettlementService $expenseSettlementService,
        FinanceShopContext $shopContext
    )
    {
        $this->notificationService = $notificationService;
        $this->expenseApprovalService = $expenseApprovalService;
        $this->expenseSettlementService = $expenseSettlementService;
        $this->shopContext = $shopContext;
    }
    public function index(Request $request)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $query = Expense::where('shop_id', $shopId)
            ->when($request->boolean('archived'), function ($builder) {
                $builder->onlyTrashed();
            });

        $expenses = QueryBuilder::for($query)
            ->allowedFilters([
                'status',
                'category',
                'vendor',
                AllowedFilter::partial('search', 'reference'),
                AllowedFilter::scope('date_from'),
                AllowedFilter::scope('date_to'),
                AllowedFilter::scope('search_all'),
            ])
            ->allowedSorts(['date', 'amount', 'created_at', 'reference'])
            ->defaultSort('-date')
            ->paginate($request->get('per_page', 15));

        $this->appendProcurementDetails($expenses->getCollection(), (int) $shopId);
        $expenses->getCollection()->each(function (Expense $expense) use ($shopId): void {
            $expense->setAttribute('settlement_state', $this->expenseSettlementService->state($expense, (int) $shopId));
        });

        return response()->json($expenses);
    }

    public function show($id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::where('shop_id', $shopId)
            ->findOrFail($id);

        $this->appendProcurementDetails(collect([$expense]), (int) $shopId);
        $expense->setAttribute('settlement_state', $this->expenseSettlementService->state($expense, (int) $shopId));

        return response()->json($expense);
    }

    /**
     * Attach procurement purchase-order details to expense payloads when available.
     */
    private function appendProcurementDetails($expenses, int $shopId): void
    {
        $receiptIds = $expenses->pluck('procurement_receipt_id')->filter()->unique()->values();
        $receipts = PurchaseOrderReceipt::with([
            'purchaseOrder.supplier:id,name',
            'purchaseOrder.items',
            'items.purchaseOrderItem',
        ])->where('shop_owner_id', $shopId)->whereIn('id', $receiptIds)->get()->keyBy('id');
        $poIds = [];
        $poNumbers = [];

        foreach ($expenses as $expense) {
            $receipt = $receipts->get($expense->procurement_receipt_id);
            if ($receipt) {
                $purchaseOrder = $receipt->purchaseOrder;
                $expense->setAttribute('procurement_details', [
                    'purchase_order_id' => $purchaseOrder->id,
                    'po_number' => $purchaseOrder->po_number,
                    'supplier_name' => $purchaseOrder->supplier?->name,
                    'receipt_id' => $receipt->id,
                    'received_at' => $receipt->received_at,
                    'items' => $receipt->items->map(fn ($receiptItem) => [
                        'purchase_order_item_id' => $receiptItem->purchase_order_item_id,
                        'product_name' => $receiptItem->purchaseOrderItem?->product_name,
                        'received_quantity' => $receiptItem->received_quantity,
                        'defective_quantity' => $receiptItem->defective_quantity,
                        'accepted_quantity' => $receiptItem->accepted_quantity,
                    ])->values(),
                ]);
                continue;
            }

            $meta = is_array($expense->meta) ? $expense->meta : [];
            $poId = (int) ($expense->purchase_order_id ?? ($meta['purchase_order_id'] ?? 0));
            if ($poId > 0) {
                $poIds[] = $poId;
            }

            $metaPoNumber = strtoupper(trim((string) ($meta['po_number'] ?? '')));
            if ($metaPoNumber !== '') {
                $poNumbers[] = $metaPoNumber;
            }

            if (preg_match('/(PO-\d{4}-\d+)/i', (string) ($expense->description ?? ''), $matches) === 1) {
                $poNumbers[] = strtoupper(trim((string) $matches[1]));
            }
        }

        $poIds = array_values(array_unique(array_filter($poIds)));
        $poNumbers = array_values(array_unique(array_filter($poNumbers)));

        if (empty($poIds) && empty($poNumbers)) {
            return;
        }

        $poQuery = PurchaseOrder::query()
            ->with(['supplier:id,name'])
            ->where('shop_owner_id', $shopId)
            ->where(function ($query) use ($poIds, $poNumbers) {
                if (!empty($poIds)) {
                    $query->whereIn('id', $poIds);
                }

                if (!empty($poNumbers)) {
                    if (!empty($poIds)) {
                        $query->orWhereIn('po_number', $poNumbers);
                    } else {
                        $query->whereIn('po_number', $poNumbers);
                    }
                }
            });

        $purchaseOrders = $poQuery->get();
        $poById = $purchaseOrders->keyBy('id');
        $poByNumber = $purchaseOrders->keyBy(function ($po) {
            return strtoupper((string) $po->po_number);
        });

        foreach ($expenses as $expense) {
            $meta = is_array($expense->meta) ? $expense->meta : [];
            $poId = (int) ($expense->purchase_order_id ?? ($meta['purchase_order_id'] ?? 0));
            $poNumber = strtoupper(trim((string) ($meta['po_number'] ?? '')));

            if ($poNumber === '' && preg_match('/(PO-\d{4}-\d+)/i', (string) ($expense->description ?? ''), $matches) === 1) {
                $poNumber = strtoupper(trim((string) $matches[1]));
            }

            $purchaseOrder = null;
            if ($poId > 0 && $poById->has($poId)) {
                $purchaseOrder = $poById->get($poId);
            } elseif ($poNumber !== '' && $poByNumber->has($poNumber)) {
                $purchaseOrder = $poByNumber->get($poNumber);
            }

            if (!$purchaseOrder) {
                continue;
            }

            $expense->setAttribute('procurement_details', [
                'purchase_order_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'supplier_name' => $purchaseOrder->supplier?->name,
                'product_name' => $purchaseOrder->product_name,
                'quantity' => $purchaseOrder->quantity,
                'requested_size' => $purchaseOrder->requested_size,
                'requested_color' => $purchaseOrder->requested_color,
                'unit_cost' => $purchaseOrder->unit_cost,
                'total_cost' => $purchaseOrder->total_cost,
                'expected_delivery_date' => $purchaseOrder->expected_delivery_date,
                'actual_delivery_date' => $purchaseOrder->actual_delivery_date,
            ]);
        }
    }

    public function store(Request $request)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $data = $request->validate([
            'reference' => 'nullable|string|max:191',
            'date' => 'required|date',
            'due_date' => 'nullable|date',
            'category' => 'required|string|max:191',
            'vendor' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'tax_amount' => 'nullable|numeric|min:0',
            'expense_account_id' => 'nullable|integer',
            'payment_account_id' => 'nullable|integer',
            'payment_mode' => ['nullable', Rule::in(['paid_now', 'pay_later'])],
            'paid_at' => 'nullable|date',
            'payment_method' => ['nullable', Rule::in(ExpenseSettlementService::PAYMENT_METHODS)],
            'payment_reference' => 'nullable|string|max:191',
            'idempotency_key' => 'nullable|string|max:191',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ]);

        $paymentMode = (string) ($data['payment_mode'] ?? 'paid_now');

        try {
            DB::beginTransaction();

            if ($paymentMode === 'pay_later' && empty($data['due_date'])) {
                throw new FinanceDomainException('A due date is required for a pay-later expense.', 'INVALID_STATE', 422);
            }
            if ($paymentMode === 'paid_now' && empty($data['payment_method'])) {
                throw new FinanceDomainException('A payment method is required for a paid-now expense.', 'INVALID_STATE', 422);
            }

            $reference = $data['reference'] ?? ('EXP-' . now()->format('YmdHis') . '-' . random_int(100, 999));
            $actor = Auth::user();
            $requestKey = $this->resolveRequestKey($data['idempotency_key'] ?? null);

            // A paid-now request is identified by the settlement key. If a
            // concurrent/retried request reaches this point after the first
            // transaction commits, replay the original expense instead of
            // creating another cash fact.
            if ($paymentMode === 'paid_now') {
                $existingSettlement = ExpenseSettlement::query()
                    ->where('shop_owner_id', $shopId)
                    ->where('entry_type', ExpenseSettlement::ENTRY_SETTLEMENT)
                    ->where('idempotency_key', $requestKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingSettlement) {
                    $existingExpense = Expense::where('shop_id', $shopId)->findOrFail($existingSettlement->expense_id);
                    $result = $this->expenseSettlementService->record($existingExpense, $actor, [
                        'amount' => $data['amount'],
                        'payment_method' => $data['payment_method'],
                        'reference' => $data['payment_reference'] ?? null,
                        'paid_at' => $data['paid_at'] ?? null,
                        'idempotency_key' => $requestKey,
                    ], true);
                    $existingExpense->setAttribute('settlement_state', $result['expense']);
                    DB::commit();

                    return response()->json($existingExpense, 200);
                }
            }

            $expenseData = [
                'reference' => $reference,
                'date' => $data['date'],
                'due_date' => $data['due_date'] ?? null,
                'category' => $data['category'],
                'vendor' => $data['vendor'] ?? null,
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'],
                'tax_amount' => $data['tax_amount'] ?? 0,
                'status' => 'submitted',
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'payment_account_id' => $data['payment_account_id'] ?? null,
                'shop_id' => $shopId,
                'meta' => [
                    'created_by' => $this->actorUserId(),
                    'payment_mode' => $paymentMode,
                ],
            ];

            $expense = Expense::create($expenseData);

            // Receipts are private, server-named objects. The original name is
            // metadata only and is never used as a path component.
            if ($request->hasFile('receipt')) {
                $file = $request->file('receipt');
                $path = $this->storePrivateReceipt($file, (int) $shopId, (int) $expense->id);
                $expense->update([
                    'receipt_path' => $path,
                    'receipt_original_name' => $file->getClientOriginalName(),
                    'receipt_mime_type' => $file->getMimeType(),
                    'receipt_size' => $file->getSize(),
                ]);
            }

            // Create 4-step approval workflow for the expense
            $shopOwner = User::find($shopId);
            if ($shopOwner) {
                try {
                    $this->expenseApprovalService->createExpenseApproval($expense, $shopOwner);
                } catch (\Exception $e) {
                    Log::error('Failed to create expense approval workflow', [
                        'expense_id' => $expense->id,
                        'error' => $e->getMessage()
                    ]);
                    // Continue anyway - approval workflow is optional
                }
            }

            $settlementState = $this->expenseSettlementService->state($expense, (int) $shopId);
            if ($paymentMode === 'paid_now') {
                $settlementResult = $this->expenseSettlementService->record($expense, $actor, [
                    'amount' => $data['amount'],
                    'payment_method' => $data['payment_method'],
                    'reference' => $data['payment_reference'] ?? null,
                    'paid_at' => $data['paid_at'] ?? null,
                    'idempotency_key' => $requestKey,
                ], true);
                $settlementState = $settlementResult['expense'];
            }
            $expense->setAttribute('settlement_state', $settlementState);

            $this->audit('create_expense', $expense->id, $expense->toArray());

            DB::commit();

            // Live notification to all Finance users in this shop
            try {
                $this->notificationService->notifyExpenseSubmitted($shopId, [
                    'expense_id' => $expense->id,
                    'reference'  => $expense->reference ?? "EXP-{$expense->id}",
                    'amount'     => number_format((float) $expense->amount, 2),
                    'category'   => $expense->category ?? 'General',
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send live expense notification', ['error' => $e->getMessage()]);
            }

            return response()->json($expense, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'expense.create', 500, ['shop_id' => $shopId]);
        }
    }

    public function listSettlements(Request $request, $id)
    {
        $shopId = $this->shopContext->id($request);
        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        return response()->json($this->expenseSettlementService->state($expense, (int) $shopId));
    }

    public function recordSettlement(Request $request, $id)
    {
        $shopId = $this->shopContext->id($request);
        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::in(ExpenseSettlementService::PAYMENT_METHODS)],
            'reference' => 'nullable|string|max:191',
            'paid_at' => 'nullable|date',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        try {
            $result = $this->expenseSettlementService->record($expense, Auth::user(), $data);

            return response()->json([
                'settlement' => $result['settlement'],
                'expense' => $result['expense'],
                'replayed' => $result['replayed'],
            ], $result['replayed'] ? 200 : 201);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'expense.settlement_create', 500, [
                'shop_id' => $shopId,
                'record_id' => $id,
            ]);
        }
    }

    public function reverseSettlement(Request $request, $id, $settlementId)
    {
        $shopId = $this->shopContext->id($request);
        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);
        $settlement = ExpenseSettlement::query()
            ->where('shop_owner_id', $shopId)
            ->where('expense_id', $expense->id)
            ->findOrFail($settlementId);

        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $reversal = $this->expenseSettlementService->reverse($settlement, Auth::user(), $data['reason']);

            return response()->json([
                'settlement' => $reversal,
                'expense' => $this->expenseSettlementService->state($expense->fresh(), (int) $shopId),
            ], 201);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'expense.settlement_reverse', 500, [
                'shop_id' => $shopId,
                'record_id' => $id,
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        if (!in_array($expense->status, ['draft', 'submitted'])) {
            return response()->json(['message' => 'Only draft/submitted expenses can be edited'], 422);
        }

        if ((float) $expense->validSettledAmount() > 0 && $request->hasAny(['amount', 'tax_amount'])) {
            return response()->json([
                'message' => 'Settled expense amounts cannot be edited until the cash settlement is reversed.',
                'code' => 'SETTLEMENT_REQUIRES_RESOLUTION',
            ], 422);
        }

        $data = $request->validate([
            'date' => 'sometimes|date',
            'due_date' => 'sometimes|nullable|date',
            'category' => 'sometimes|string|max:191',
            'vendor' => 'sometimes|nullable|string|max:191',
            'description' => 'sometimes|nullable|string',
            'amount' => 'sometimes|numeric|min:0.01',
            'tax_amount' => 'sometimes|numeric|min:0',
            'expense_account_id' => 'sometimes|nullable|integer',
            'payment_account_id' => 'sometimes|nullable|integer',
        ]);

        $expense->update($data);
        $this->audit('update_expense', $expense->id, $data);

        return response()->json($expense);
    }

    public function approve(Request $request, $id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        if ($expense->procurement_receipt_id) {
            $this->expenseApprovalService->clearProcurementApprovalWorkflow($expense);

            return response()->json([
                'message' => 'Procurement receipt expenses are review-only and do not require approval.',
            ], 422);
        }

        // If expense has a 4-step approval workflow, use it
        if ($expense->approval_id) {
            $result = $this->expenseApprovalService->approveExpense(
                $expense,
                Auth::user(),
                $request->input('approval_notes')
            );

            if (!$result['success']) {
                return response()->json([
                    'message' => $result['message'],
                    'details' => $result
                ], 422);
            }

            $expense->refresh();

            // Activity log
            activity()
                ->causedBy(Auth::user())
                ->performedOn($expense)
                ->withProperties([
                    'reference' => $expense->reference,
                    'category' => $expense->category,
                    'vendor' => $expense->vendor,
                    'amount' => $expense->amount,
                    'approval_level' => $expense->current_approval_level,
                    'approval_notes' => $request->input('approval_notes'),
                    'approved_by_name' => Auth::user()->name,
                    'is_final' => $result['is_final'] ?? false,
                ])
                ->log('Expense approved at level ' . $expense->current_approval_level);

            $this->audit('approve_expense', $expense->id, [
                'approval_level' => $expense->current_approval_level,
                'status' => $expense->status
            ]);

            return response()->json([
                'message' => $result['message'],
                'expense' => $expense,
                'is_final' => $result['is_final'] ?? false
            ]);
        }

        // Fallback to old single-level approval for legacy expenses
        if ($expense->status !== 'submitted') {
            return response()->json(['message' => 'Only submitted expenses can be approved'], 422);
        }

        $user = Auth::user();
        $approvalLimit = $user->approval_limit;
        
        if ($approvalLimit !== null && $expense->amount > $approvalLimit) {
            return response()->json([
                'message' => 'Insufficient approval authority',
                'details' => [
                    'expense_amount' => $expense->amount,
                    'your_approval_limit' => $approvalLimit,
                    'required_approver' => 'This expense requires approval from a user with higher authority'
                ]
            ], 403);
        }

        $expense->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $request->input('approval_notes'),
        ]);

        activity()
            ->causedBy(Auth::user())
            ->performedOn($expense)
            ->withProperties([
                'reference' => $expense->reference,
                'category' => $expense->category,
                'vendor' => $expense->vendor,
                'amount' => $expense->amount,
                'approval_notes' => $request->input('approval_notes'),
                'approved_by_name' => Auth::user()->name,
                'approved_by_role' => Auth::user()->role ?? 'Finance Staff',
            ])
            ->log('Expense approved');

        $this->audit('approve_expense', $expense->id, ['status' => 'approved']);

        return response()->json($expense);
    }

    public function reject(Request $request, $id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $request->validate([
            'approval_notes' => 'required|string|max:1000'
        ]);

        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        if ($expense->procurement_receipt_id) {
            $this->expenseApprovalService->clearProcurementApprovalWorkflow($expense);

            return response()->json([
                'message' => 'Procurement receipt expenses are review-only and do not require approval.',
            ], 422);
        }

        // If expense has a 4-step approval workflow, use it
        if ($expense->approval_id) {
            $result = $this->expenseApprovalService->rejectExpense(
                $expense,
                Auth::user(),
                $request->input('approval_notes')
            );

            if (!$result['success']) {
                return response()->json([
                    'message' => $result['message'],
                    'details' => $result
                ], 422);
            }

            $expense->refresh();

            // Activity log
            activity()
                ->causedBy(Auth::user())
                ->performedOn($expense)
                ->withProperties([
                    'reference' => $expense->reference,
                    'category' => $expense->category,
                    'vendor' => $expense->vendor,
                    'amount' => $expense->amount,
                    'rejection_reason' => $request->input('approval_notes'),
                    'rejected_by_name' => Auth::user()->name,
                    'rejected_by_role' => Auth::user()->role ?? 'Finance Staff',
                    'rejection_level' => $expense->current_approval_level,
                ])
                ->log('Expense rejected at level ' . $expense->current_approval_level);

            $this->audit('reject_expense', $expense->id, [
                'approval_level' => $expense->current_approval_level,
                'status' => $expense->status
            ]);

            return response()->json([
                'message' => $result['message'],
                'expense' => $expense,
                'settlement_state' => $this->expenseSettlementService->state($expense, (int) $shopId),
            ]);
        }

        // Fallback to old single-level rejection for legacy expenses
        if ($expense->status !== 'submitted') {
            return response()->json(['message' => 'Only submitted expenses can be rejected'], 422);
        }

        $user = Auth::user();
        $approvalLimit = $user->approval_limit;
        
        if ($approvalLimit !== null && $expense->amount > $approvalLimit) {
            return response()->json([
                'message' => 'Insufficient approval authority',
                'details' => [
                    'expense_amount' => $expense->amount,
                    'your_approval_limit' => $approvalLimit,
                    'required_approver' => 'This expense requires approval/rejection from a user with higher authority'
                ]
            ], 403);
        }

        $expense->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $request->input('approval_notes'),
        ]);

        activity()
            ->causedBy(Auth::user())
            ->performedOn($expense)
            ->withProperties([
                'reference' => $expense->reference,
                'category' => $expense->category,
                'vendor' => $expense->vendor,
                'amount' => $expense->amount,
                'rejection_reason' => $request->input('approval_notes'),
                'rejected_by_name' => Auth::user()->name,
                'rejected_by_role' => Auth::user()->role ?? 'Finance Staff',
            ])
            ->log('Expense rejected');

        $this->audit('reject_expense', $expense->id, ['status' => 'rejected']);

        $expense->setAttribute('settlement_state', $this->expenseSettlementService->state($expense, (int) $shopId));

        return response()->json($expense);
    }

    public function destroy($id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        if (!in_array($expense->status, ['draft', 'submitted', 'rejected', 'approved'])) {
            return response()->json(['message' => 'Only unfinalized expenses can be deleted'], 422);
        }

        if ((float) $expense->validSettledAmount() > 0) {
            return response()->json([
                'message' => 'Settled expenses cannot be archived until the cash settlement is reversed.',
                'code' => 'SETTLEMENT_REQUIRES_RESOLUTION',
            ], 422);
        }

        // Store expense details before deletion for logging
        $expenseDetails = [
            'reference' => $expense->reference,
            'category' => $expense->category,
            'vendor' => $expense->vendor,
            'amount' => $expense->amount,
            'status' => $expense->status,
            'date' => $expense->date,
        ];

        // Activity log before deletion
        activity()
            ->causedBy(Auth::user())
            ->performedOn($expense)
            ->withProperties($expenseDetails)
            ->log('Expense archived');

        $expense->delete();
        $this->audit('archive_expense', $expense->id, ['status' => $expense->status]);

        return response()->json(['message' => 'Expense archived']);
    }

    /**
     * Restore archived expense.
     */
    public function restore($id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::withTrashed()
            ->where('shop_id', $shopId)
            ->onlyTrashed()
            ->where('id', $id)
            ->firstOrFail();

        $expenseDetails = [
            'reference' => $expense->reference,
            'category' => $expense->category,
            'vendor' => $expense->vendor,
            'amount' => $expense->amount,
            'status' => $expense->status,
            'date' => $expense->date,
        ];

        activity()
            ->causedBy(Auth::user())
            ->performedOn($expense)
            ->withProperties($expenseDetails)
            ->log('Expense restored');

        $expense->restore();
        $this->audit('restore_expense', $expense->id, ['status' => $expense->status]);

        return response()->json([
            'message' => 'Expense restored',
            'expense' => $expense->fresh(),
        ]);
    }

    private function audit(string $action, int $targetId, array $metadata = []): void
    {
        $actorUserId = $this->actorUserId();
        $shopOwnerId = $this->shopOwnerId();
        if (! $shopOwnerId) {
            return; // No shop context — skip audit rather than writing to shop #1
        }
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => 'expense',
            'target_id' => $targetId,
            'metadata' => $metadata,
        ]);
    }

    private function resolveRequestKey(mixed $key): string
    {
        $key = trim((string) $key);
        if ($key !== '') {
            return $key;
        }

        $requestKey = trim((string) request()->header('X-Request-ID'));

        return $requestKey !== '' ? $requestKey : Str::uuid()->toString();
    }

    private function storePrivateReceipt($file, int $shopId, int $expenseId): string
    {
        $extension = Str::lower((string) ($file->extension() ?: 'bin'));
        $directory = "finance/shops/{$shopId}/expenses/{$expenseId}/receipts";
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, 'local');

        if (! $path) {
            throw new \RuntimeException('Receipt storage failed.');
        }

        return $path;
    }

    /**
     * Upload or replace receipt for an existing expense
     */
    public function uploadReceipt(Request $request, $id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        $request->validate([
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ]);

        try {
            DB::beginTransaction();

            // Upload new receipt
            $file = $request->file('receipt');
            $oldPath = $expense->receipt_path;
            $path = $this->storePrivateReceipt($file, (int) $shopId, (int) $expense->id);

            $expense->update([
                'receipt_path' => $path,
                'receipt_original_name' => $file->getClientOriginalName(),
                'receipt_mime_type' => $file->getMimeType(),
                'receipt_size' => $file->getSize(),
            ]);

            $this->audit('upload_receipt', $expense->id, [
                'receipt_name' => $file->getClientOriginalName(),
                'receipt_size' => $file->getSize(),
            ]);

            DB::commit();

            if ($oldPath) {
                Storage::disk('local')->delete($oldPath);
                Storage::disk('public')->delete($oldPath);
            }

            return response()->json([
                'message' => 'Receipt uploaded successfully',
                'expense' => $expense,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'expense.receipt_upload', 500, ['record_id' => $id, 'shop_id' => $shopId]);
        }
    }

    /**
     * Download receipt file
     */
    public function downloadReceipt($id)
    {
        $shopId = $this->shopOwnerId();
        if (! $shopId) {
            return response()->json(['message' => 'No shop association found for this account.'], 403);
        }

        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'No receipt attached to this expense'], 404);
        }

        $disk = Storage::disk('local');
        $path = $expense->receipt_path;

        // Legacy public paths remain readable only through this authorized
        // endpoint until the migration command has copied them privately.
        if (! $disk->exists($path)) {
            $disk = Storage::disk('public');
        }

        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Receipt file not found'], 404);
        }

        $downloadName = preg_replace('/[\r\n]+/', '', basename((string) $expense->receipt_original_name)) ?: 'receipt';

        return $disk->download($path, $downloadName);
    }

    /**
     * Delete receipt file
     */
    public function deleteReceipt(Request $request, $id)
    {
        $shopId = $this->shopContext->id($request);
        $expense = Expense::where('shop_id', $shopId)->findOrFail($id);

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'No receipt to delete'], 404);
        }

        try {
            DB::beginTransaction();

            $receiptPath = $expense->receipt_path;
            $receiptName = $expense->receipt_original_name;

            // Delete file from either private or legacy storage.
            Storage::disk('local')->delete($receiptPath);
            Storage::disk('public')->delete($receiptPath);

            // Update expense record
            $expense->update([
                'receipt_path' => null,
                'receipt_original_name' => null,
                'receipt_mime_type' => null,
                'receipt_size' => null,
            ]);

            $this->audit('delete_receipt', $expense->id, [
                'deleted_receipt' => $receiptName,
                'receipt_path' => $receiptPath,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Receipt deleted successfully',
                'expense' => $expense,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'expense.receipt_delete', 500, ['record_id' => $id, 'shop_id' => $shopId]);
        }
    }

    private function shopOwnerId(): ?int
    {
        return $this->shopContext->id(request());
    }

    private function actorUserId(): ?int
    {
        return Auth::guard('user')->id();
    }
}
