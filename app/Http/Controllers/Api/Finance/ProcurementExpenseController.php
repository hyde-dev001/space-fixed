<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Services\ExpenseApprovalService;
use App\Support\Finance\FinanceErrorResponse;
use App\Support\Finance\FinanceShopContext;
use Illuminate\Http\Request;

final class ProcurementExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseApprovalService $expenseApprovalService,
        private readonly FinanceShopContext $shopContext,
    ) {}

    public function reviewAndRelease(Request $request, int $id)
    {
        $shopId = $this->shopContext->id($request);
        $data = $request->validate([
            'approval_notes' => 'nullable|string|max:1000',
        ]);
        $expense = Expense::query()->where('shop_id', $shopId)->findOrFail($id);
        $actor = $request->user('user');

        abort_unless($actor instanceof User, 401);

        try {
            $releasedExpense = $this->expenseApprovalService->reviewAndReleaseProcurementExpense(
                $expense,
                $actor,
                $data['approval_notes'] ?? null,
            );

            return response()->json([
                'message' => 'Procurement expense reviewed and released for payment.',
                'expense' => $releasedExpense,
            ]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'expense.procurement_review_release', 500, [
                'shop_id' => $shopId,
                'record_id' => $id,
            ]);
        }
    }
}
