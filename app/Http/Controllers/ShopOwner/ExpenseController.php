<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\AuditLog;
use App\Services\ExpenseApprovalService;
use App\Services\Finance\ExpenseSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseApprovalService $expenseApprovalService,
        private ExpenseSettlementService $expenseSettlementService
    ) {}

    private function shopOwner()
    {
        return Auth::guard('shop_owner')->user();
    }

    /**
     * List expenses for this shop owner.
     * Supports ?status=submitted filter.
     */
    public function index(Request $request)
    {
        $shopOwner = $this->shopOwner();

        $query = Expense::where('shop_id', $shopOwner->id)
            ->whereNull('procurement_receipt_id')
            ->orderByDesc('date')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('category', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('reference', 'LIKE', "%{$search}%");
            });
        }

        $expenses = $query->paginate($request->get('per_page', 50));

        return response()->json($expenses);
    }

    /**
     * Shop owner approves a submitted expense when high-value escalation is pending.
     */
    public function approve(Request $request, $id)
    {
        $shopOwner = $this->shopOwner();

        $expense = Expense::where('shop_id', $shopOwner->id)->whereNull('procurement_receipt_id')->findOrFail($id);

        // Approval rows own the current Finance/Shop Owner state.
        if ($expense->approval_id) {
            // Convert shop_owner to user for the service
            $actor = Auth::guard('shop_owner')->user();
            
            $result = $this->expenseApprovalService->approveExpense(
                $expense,
                $actor,
                $request->input('approval_notes')
            );

            if (!$result['success']) {
                return response()->json([
                    'message' => $result['message'] ?? 'Failed to approve expense',
                    'details' => $result
                ], 422);
            }

            $expense->refresh();

            // Activity log
            activity()
                ->causedBy($shopOwner)
                ->performedOn($expense)
                ->withProperties([
                    'reference' => $expense->reference,
                    'category' => $expense->category,
                    'amount' => $expense->amount,
                    'approval_level' => $expense->current_approval_level,
                    'approval_notes' => $request->input('approval_notes'),
                    'approved_by_name' => $shopOwner->name ?? $shopOwner->business_name,
                    'is_final' => $result['is_final'] ?? false,
                ])
                ->log('Expense approved at level ' . $expense->current_approval_level);

            $this->audit('approve_expense', $shopOwner->id, $expense->id, [
                'reference' => $expense->reference,
                'category' => $expense->category,
                'amount' => $expense->amount,
                'approval_level' => $expense->current_approval_level,
            ]);

            $forwardingMessage = ($result['is_final'] ?? false)
                ? 'Expense approved.'
                : 'Expense moved to the next approval stage.';

            return response()->json([
                'message' => $forwardingMessage,
                'expense' => $expense,
                'settlement_state' => $this->expenseSettlementService->state($expense, (int) $shopOwner->id),
                'is_final' => $result['is_final'] ?? false,
                'approval_level' => $expense->current_approval_level,
            ]);
        }

        return response()->json([
            'message' => 'This expense has no active approval workflow and requires Finance review.',
            'code' => 'APPROVAL_WORKFLOW_REQUIRED',
        ], 409);
    }

    /**
     * Shop owner rejects a submitted expense.
     */
    public function reject(Request $request, $id)
    {
        $shopOwner = $this->shopOwner();

        $expense = Expense::where('shop_id', $shopOwner->id)->whereNull('procurement_receipt_id')->findOrFail($id);

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if ($expense->approval_id) {
            $result = $this->expenseApprovalService->rejectExpense(
                $expense,
                $shopOwner,
                (string) $request->input('rejection_reason', '')
            );

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'message' => $result['message'] ?? 'Failed to reject expense',
                    'details' => $result,
                ], 422);
            }

            $expense->refresh();

            return response()->json([
                'message' => 'Expense rejected.',
                'expense' => $expense,
                'settlement_state' => $this->expenseSettlementService->state($expense, (int) $shopOwner->id),
            ]);
        }

        return response()->json([
            'message' => 'This expense has no active approval workflow and requires Finance review.',
            'code' => 'APPROVAL_WORKFLOW_REQUIRED',
        ], 409);
    }

    private function audit(string $action, int $shopOwnerId, int $targetId, array $metadata = []): void
    {
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => null,
            'action'        => $action,
            'target_type'   => 'expense',
            'target_id'     => $targetId,
            'metadata'      => $metadata,
        ]);
    }
}
