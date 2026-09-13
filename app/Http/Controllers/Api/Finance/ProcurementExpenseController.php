<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ConfirmSupplierRefundRequest;
use App\Models\Finance\Expense;
use App\Models\Supplier;
use App\Models\SupplierAdjustment;
use App\Models\SupplierPaymentProfile;
use App\Models\SupplierPaymentAttempt;
use App\Models\User;
use App\Http\Requests\Finance\CancelSupplierPaymentRequest;
use App\Http\Requests\Finance\InitiateSupplierPaymentRequest;
use App\Http\Requests\Finance\SubmitSupplierPaymentProofRequest;
use App\Services\ExpenseApprovalService;
use App\Services\Finance\SupplierPaymentService;
use App\Services\NotificationService;
use App\Services\SupplierAdjustmentService;
use App\Support\Finance\FinanceDomainException;
use App\Support\Finance\FinanceErrorResponse;
use App\Support\Finance\FinanceShopContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class ProcurementExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseApprovalService $expenseApprovalService,
        private readonly FinanceShopContext $shopContext,
        private readonly SupplierPaymentService $supplierPaymentService,
        private readonly SupplierAdjustmentService $adjustmentService,
        private readonly NotificationService $notificationService,
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

    public function revealPaymentProfile(Request $request, int $supplierId)
    {
        $shopId = $this->shopContext->id($request);
        $supplier = Supplier::query()->where('shop_owner_id', $shopId)->findOrFail($supplierId);
        $profile = $supplier->paymentProfile;
        $actor = $request->user('user');

        abort_if(! $profile, 404);
        abort_unless($actor instanceof User, 401);

        activity('sensitive_payment_profiles')
            ->causedBy($actor)
            ->performedOn($profile)
            ->withProperties([
                'supplier_id' => (int) $supplier->id,
                'payment_profile_id' => (int) $profile->id,
                'destination_type' => (string) $profile->destination_type,
            ])
            ->log('supplier_payment_profile_revealed');

        return response()->json([
            'data' => $profile->toRevealedArray(),
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
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

    public function sendSupplierPaymentReceipt(Request $request, int $attemptId)
    {
        $shopId = $this->shopContext->id($request);
        $attempt = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopId)
            ->findOrFail($attemptId);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);

        try {
            $updated = $this->supplierPaymentService->sendConfirmation($attempt, $actor);

            return response()->json(['data' => $this->supplierPaymentService->present($updated)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.send_receipt', 500, [
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

        $response = response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                basename($media->file_name),
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function submitSupplierRefundProof(Request $request, int $id, int $adjustmentId)
    {
        $shopId = $this->shopContext->id($request);
        [$expense, $adjustment] = $this->refundAdjustmentForExpense($shopId, $id, $adjustmentId);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);
        $data = $request->validate([
            'expected_refund_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'supplier_reported_refund_amount' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'supplier_reported_refund_reference' => ['nullable', 'string', 'max:160'],
            'supplier_reported_refund_date' => ['nullable', 'date'],
            'procurement_notes' => ['nullable', 'string', 'max:2000'],
            'supplier_refund_proof' => ['nullable', 'file', 'max:10240'],
        ]);

        try {
            $updated = $this->adjustmentService->recordSupplierRefundProof(
                $adjustment,
                $actor,
                $data,
                $request->file('supplier_refund_proof'),
            );

            return response()->json(['data' => $this->adjustmentService->present($updated)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_refund.proof', 500, [
                'shop_id' => $shopId,
                'record_id' => $adjustmentId,
            ]);
        }
    }

    public function confirmSupplierRefund(ConfirmSupplierRefundRequest $request, int $id, int $adjustmentId)
    {
        $shopId = $this->shopContext->id($request);
        [$expense, $adjustment] = $this->refundAdjustmentForExpense($shopId, $id, $adjustmentId);
        $actor = $request->user('user');
        abort_unless($actor instanceof User, 401);

        try {
            $result = $this->adjustmentService->confirmSupplierRefund(
                $adjustment,
                $actor,
                $request->validated(),
                $request->file('finance_confirmation_proof'),
            );

            return response()->json([
                'data' => $this->adjustmentService->present($result['adjustment']),
                'settlement' => $result['settlement'],
                'expense' => $result['expense'],
                'replayed' => $result['replayed'],
            ], $result['replayed'] ? 200 : 201);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_refund.confirm', 500, [
                'shop_id' => $shopId,
                'record_id' => $adjustmentId,
            ]);
        }
    }

    private function changePaymentProfileStatus(Request $request, int $supplierId, string $status)
    {
        $shopId = $this->shopContext->id($request);
        $actor = $request->user('user');

        abort_unless($actor instanceof User, 401);

        try {
            $result = DB::transaction(function () use ($shopId, $supplierId, $status, $actor): array {
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

                $previousStatus = (string) $profile->status;
                $profile->status = $status;
                if ($status === SupplierPaymentProfile::STATUS_VERIFIED) {
                    $profile->verified_by = $actor->id;
                    $profile->verified_at = now();
                }
                $profile->save();

                return [
                    'profile' => $profile->fresh(),
                    'supplier' => $supplier,
                    'previous_status' => $previousStatus,
                ];
            }, 3);

            /** @var SupplierPaymentProfile $profile */
            $profile = $result['profile'];
            /** @var Supplier $supplier */
            $supplier = $result['supplier'];

            if ($status === SupplierPaymentProfile::STATUS_DISABLED
                && $result['previous_status'] !== SupplierPaymentProfile::STATUS_DISABLED) {
                try {
                    $this->notificationService->notifySupplierPaymentProfileDisabled(
                        shopId: $shopId,
                        data: [
                            'supplier_id' => (int) $supplier->id,
                            'supplier_name' => (string) $supplier->name,
                            'payment_profile_id' => (int) $profile->id,
                            'destination_type' => (string) $profile->destination_type,
                            'event_key' => $profile->updated_at?->format('YmdHisv') ?? (string) $profile->id,
                        ],
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

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

    /** @return array{0: Expense, 1: SupplierAdjustment} */
    private function refundAdjustmentForExpense(int $shopId, int $expenseId, int $adjustmentId): array
    {
        $expense = Expense::query()->where('shop_id', $shopId)->findOrFail($expenseId);
        $adjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', $shopId)
            ->with('receiptItem')
            ->findOrFail($adjustmentId);

        abort_unless((int) $adjustment->receiptItem?->purchase_order_receipt_id === (int) $expense->procurement_receipt_id, 404);

        return [$expense, $adjustment];
    }
}
