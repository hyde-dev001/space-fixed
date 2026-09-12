<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\Supplier;
use App\Models\SupplierPaymentProfile;
use App\Models\SupplierPaymentAttempt;
use App\Models\User;
use App\Http\Requests\Finance\CancelSupplierPaymentRequest;
use App\Http\Requests\Finance\InitiateSupplierPaymentRequest;
use App\Http\Requests\Finance\SubmitSupplierPaymentProofRequest;
use App\Services\ExpenseApprovalService;
use App\Services\Finance\SupplierPaymentService;
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
        private readonly SupplierPaymentService $supplierPaymentService,
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

    public function initiateSupplierPayment(InitiateSupplierPaymentRequest $request, int $id)
    {
        $shopId = $this->shopContext->id($request);
        $expense = Expense::query()->where('shop_id', $shopId)->findOrFail($id);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);

        try {
            $result = $this->supplierPaymentService->initiate(
                $expense,
                $actor,
                (string) $request->validated('payment_method'),
                (string) $request->validated('idempotency_key'),
            );

            return response()->json([
                'data' => $this->supplierPaymentService->present($result['attempt']),
                'replayed' => $result['replayed'],
            ], $result['replayed'] ? 200 : 201);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.initiate', 500, [
                'shop_id' => $shopId,
                'record_id' => $id,
            ]);
        }
    }

    public function submitSupplierPayment(
        SubmitSupplierPaymentProofRequest $request,
        int $attemptId,
    ) {
        $shopId = $this->shopContext->id($request);
        $attempt = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopId)
            ->findOrFail($attemptId);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);

        try {
            $updated = $this->supplierPaymentService->submitForVerification(
                $attempt,
                $actor,
                $request->validated(),
                $request->file('payment_proof'),
            );

            return response()->json(['data' => $this->supplierPaymentService->present($updated)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.submit', 500, [
                'shop_id' => $shopId,
                'record_id' => $attemptId,
            ]);
        }
    }

    public function cancelSupplierPayment(CancelSupplierPaymentRequest $request, int $attemptId)
    {
        $shopId = $this->shopContext->id($request);
        $attempt = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopId)
            ->findOrFail($attemptId);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);

        try {
            $cancelled = $this->supplierPaymentService->cancel(
                $attempt,
                $actor,
                (string) $request->validated('reason'),
            );

            return response()->json(['data' => $this->supplierPaymentService->present($cancelled)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.cancel', 500, [
                'shop_id' => $shopId,
                'record_id' => $attemptId,
            ]);
        }
    }

    public function resendSupplierPaymentConfirmation(Request $request, int $attemptId)
    {
        $shopId = $this->shopContext->id($request);
        $attempt = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopId)
            ->findOrFail($attemptId);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);

        try {
            $updated = $this->supplierPaymentService->resendConfirmation($attempt, $actor);

            return response()->json(['data' => $this->supplierPaymentService->present($updated)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.resend_confirmation', 500, [
                'shop_id' => $shopId,
                'record_id' => $attemptId,
            ]);
        }
    }

    public function supplierPaymentProof(Request $request, int $attemptId, int $mediaId)
    {
        $shopId = $this->shopContext->id($request);
        $attempt = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopId)
            ->findOrFail($attemptId);
        $media = $attempt->getMedia('payment_proof')->firstWhere('id', $mediaId);
        abort_unless($media, 404);

        $response = response()->download($media->getPath(), $media->file_name, [
            'Content-Type' => $media->mime_type,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
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
