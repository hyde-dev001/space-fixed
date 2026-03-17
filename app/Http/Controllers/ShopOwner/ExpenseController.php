<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ExpenseController extends Controller
{
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
     * Shop owner approves a submitted expense.
     */
    public function approve(Request $request, $id)
    {
        $shopOwner = $this->shopOwner();

        $expense = Expense::where('shop_id', $shopOwner->id)->findOrFail($id);

        if ($expense->status !== 'submitted') {
            return response()->json([
                'message' => 'Only submitted expenses can be approved.',
                'current_status' => $expense->status,
            ], 422);
        }

        $request->validate([
            'approval_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $expense->update([
                'status'         => 'approved',
                'approved_by'    => $shopOwner->id,
                'approved_at'    => now(),
                'approval_notes' => $request->approval_notes,
                'meta'           => array_merge((array) $expense->meta, [
                    'approved_by_type' => 'shop_owner',
                    'approved_by_name' => $shopOwner->business_name ?? $shopOwner->name ?? 'Shop Owner',
                ]),
            ]);

            $this->audit('approve_expense', $shopOwner->id, $expense->id, [
                'reference'      => $expense->reference,
                'category'       => $expense->category,
                'amount'         => $expense->amount,
                'approval_notes' => $request->approval_notes,
            ]);

            return response()->json([
                'message' => 'Expense approved successfully.',
                'expense' => $expense->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('ShopOwner expense approval failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to approve expense.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Shop owner rejects a submitted expense.
     */
    public function reject(Request $request, $id)
    {
        $shopOwner = $this->shopOwner();

        $expense = Expense::where('shop_id', $shopOwner->id)->findOrFail($id);

        if ($expense->status !== 'submitted') {
            return response()->json([
                'message' => 'Only submitted expenses can be rejected.',
                'current_status' => $expense->status,
            ], 422);
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $expense->update([
                'status'         => 'rejected',
                'approved_by'    => $shopOwner->id,
                'approved_at'    => now(),
                'approval_notes' => $request->rejection_reason,
                'meta'           => array_merge((array) $expense->meta, [
                    'approved_by_type' => 'shop_owner',
                    'approved_by_name' => $shopOwner->business_name ?? $shopOwner->name ?? 'Shop Owner',
                    'rejection_reason' => $request->rejection_reason,
                ]),
            ]);

            $this->audit('reject_expense', $shopOwner->id, $expense->id, [
                'reference'        => $expense->reference,
                'category'         => $expense->category,
                'amount'           => $expense->amount,
                'rejection_reason' => $request->rejection_reason,
            ]);

            return response()->json([
                'message' => 'Expense rejected.',
                'expense' => $expense->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('ShopOwner expense rejection failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to reject expense.', 'error' => $e->getMessage()], 500);
        }
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
