<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\Supplier;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use App\Services\ExpenseApprovalService;
use App\Support\Finance\FinanceDomainException;
use App\Support\Finance\FinanceErrorResponse;
use App\Support\Finance\FinanceShopContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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

    public function showPaymentProfile(Request $request, int $supplierId)
    {
        $shopId = $this->shopContext->id($request);
        $supplier = Supplier::query()->where('shop_owner_id', $shopId)->findOrFail($supplierId);

        return response()->json([
            'data' => $supplier->paymentProfile?->toMaskedArray(),
        ]);
    }

    public function verifyPaymentProfile(Request $request, int $supplierId)
    {
        return $this->changePaymentProfileStatus($request, $supplierId, SupplierPaymentProfile::STATUS_VERIFIED);
    }

    public function disablePaymentProfile(Request $request, int $supplierId)
    {
        return $this->changePaymentProfileStatus($request, $supplierId, SupplierPaymentProfile::STATUS_DISABLED);
    }

    private function changePaymentProfileStatus(Request $request, int $supplierId, string $status)
    {
        $shopId = $this->shopContext->id($request);
        $actor = $request->user('user');

        abort_unless($actor instanceof User, 401);

        try {
            $profile = DB::transaction(function () use ($shopId, $supplierId, $status, $actor): SupplierPaymentProfile {
                $supplier = Supplier::query()
                    ->where('shop_owner_id', $shopId)
                    ->lockForUpdate()
                    ->findOrFail($supplierId);
                $profile = SupplierPaymentProfile::query()
                    ->where('shop_owner_id', $shopId)
                    ->where('supplier_id', $supplier->id)
                    ->lockForUpdate()
                    ->first();

                if (! $profile) {
                    throw new FinanceDomainException('The supplier has no payment profile.', 'INVALID_STATE', 422);
                }
                if ($status === SupplierPaymentProfile::STATUS_VERIFIED
                    && $profile->status === SupplierPaymentProfile::STATUS_DISABLED) {
                    throw new FinanceDomainException('A disabled payment profile must be updated before verification.', 'INVALID_STATE', 422);
                }

                $profile->status = $status;
                if ($status === SupplierPaymentProfile::STATUS_VERIFIED) {
                    $profile->verified_by = $actor->id;
                    $profile->verified_at = now();
                }
                $profile->save();

                return $profile->fresh();
            }, 3);

            return response()->json([
                'message' => $status === SupplierPaymentProfile::STATUS_VERIFIED
                    ? 'Supplier payment profile verified.'
                    : 'Supplier payment profile disabled.',
                'data' => $profile->toMaskedArray(),
            ]);
        } catch (\Throwable $exception) {
            if ($exception instanceof ModelNotFoundException) {
                throw $exception;
            }

            return FinanceErrorResponse::json($exception, 'supplier_payment_profile.' . $status, 500, [
                'shop_id' => $shopId,
                'record_id' => $supplierId,
            ]);
        }
    }
}
