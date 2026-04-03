<?php

namespace App\Services;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairPosRefundService
{
    private const FINAL_STATUSES = ['succeeded', 'failed', 'rejected', 'cancelled'];

    public function computeRepairRefundableAmount(int $repairId): float
    {
        $paid = (float) PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repairId)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->sum('paid_amount');

        $refunded = (float) PosRefund::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repairId)
            ->where('status', 'succeeded')
            ->sum('approved_amount');

        return max(0.0, round($paid - $refunded, 2));
    }

    public function requestRefund(PosTransaction $source, array $payload, int $actorId): PosRefund
    {
        $requested = (float) $payload['requested_amount'];
        $reasonCode = (string) ($payload['reason_code'] ?? '');

        $activeRequest = PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->latest('id')
            ->first();

        if ($activeRequest) {
            throw ValidationException::withMessages([
                'source_transaction_id' => ['A refund request is already in progress for this transaction.'],
            ]);
        }

        if ($reasonCode === 'customer_cancelled_repair' && (string) $source->module_type === 'repair') {
            $maxRefundable = $this->computeRepairRefundableAmount((int) $source->module_reference_id);
        } else {
            $alreadyRefunded = (float) PosRefund::query()
                ->where('source_transaction_id', $source->id)
                ->whereIn('status', ['approved', 'processing', 'succeeded'])
                ->sum('approved_amount');

            $maxRefundable = max(0, (float) $source->paid_amount - $alreadyRefunded);
        }

        if ($requested > $maxRefundable) {
            throw ValidationException::withMessages([
                'requested_amount' => ['Requested amount exceeds refundable balance.'],
            ]);
        }

        return PosRefund::create([
            'refund_no' => 'RFD-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'shop_owner_id' => $source->shop_owner_id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $source->module_reference_id,
            'request_type' => $payload['request_type'],
            'requested_amount' => $requested,
            'reason_code' => $payload['reason_code'],
            'reason_notes' => $payload['reason_notes'] ?? null,
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'requested_by' => $actorId,
            'requested_at' => now(),
        ]);
    }

    public function approve(
        PosRefund $refund,
        int $actorId,
        ?float $approvedAmount = null,
        ?string $approvalNote = null,
        string $stage = 'finance'
    ): PosRefund
    {
        if (!in_array((string) $refund->status, ['requested', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only requested or approved refunds can be approved.'],
            ]);
        }

        $source = $refund->sourceTransaction()->firstOrFail();
        $amountToApprove = $approvedAmount ?? (float) $refund->requested_amount;
        if ($amountToApprove <= 0) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount must be greater than zero.'],
            ]);
        }

        $alreadyCommitted = (float) PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->where('id', '!=', $refund->id)
            ->whereIn('status', ['approved', 'processing', 'succeeded'])
            ->sum('approved_amount');

        $maxRefundable = max(0, (float) $source->paid_amount - $alreadyCommitted);
        if ($amountToApprove > $maxRefundable) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount exceeds refundable balance.'],
            ]);
        }

        $stage = strtolower(trim($stage));
        if (!in_array($stage, ['finance', 'shop_owner'], true)) {
            throw ValidationException::withMessages([
                'stage' => ['Invalid approval stage.'],
            ]);
        }

        $notes = trim((string) ($refund->reason_notes ?? ''));
        if ($approvalNote) {
            $notes = trim($notes . "\n\nApproval note: " . trim($approvalNote));
        }

        if ($stage === 'finance') {
            if ((string) ($refund->finance_status ?? 'pending') !== 'pending') {
                throw ValidationException::withMessages([
                    'finance_status' => ['Finance approval already recorded for this refund request.'],
                ]);
            }

            $requiresOwnerApproval = app(ShopOwnerApprovalPolicyService::class)
                ->requiresOwnerApprovalForRefund((int) $refund->shop_owner_id, $amountToApprove);

            $refund->update([
                'status' => $requiresOwnerApproval ? 'requested' : 'approved',
                'approved_amount' => round($amountToApprove, 2),
                'approved_by' => $actorId,
                'approved_at' => now(),
                'finance_status' => $requiresOwnerApproval ? 'approved_initial' : 'approved',
                'shop_owner_status' => $requiresOwnerApproval ? 'pending' : 'skipped',
                'reason_notes' => $notes !== '' ? Str::limit($notes, 2000, '') : null,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            return $refund->fresh();
        }

        if ((string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
            throw ValidationException::withMessages([
                'finance_status' => ['Shop owner approval requires finance initial approval first.'],
            ]);
        }

        if ((string) ($refund->shop_owner_status ?? 'pending') !== 'pending') {
            throw ValidationException::withMessages([
                'shop_owner_status' => ['Shop owner approval already recorded for this request.'],
            ]);
        }

        $refund->update([
            'status' => 'approved',
            'approved_amount' => round($amountToApprove, 2),
            'approved_by' => $actorId,
            'approved_at' => now(),
            'finance_status' => 'approved',
            'shop_owner_status' => 'approved',
            'reason_notes' => $notes !== '' ? Str::limit($notes, 2000, '') : null,
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        return $refund->fresh();
    }

    public function reject(PosRefund $refund, int $actorId, string $rejectionReason, string $stage = 'finance'): PosRefund
    {
        if (in_array((string) $refund->status, ['succeeded', 'processing'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Processing or succeeded refunds can no longer be rejected.'],
            ]);
        }

        $stage = strtolower(trim($stage));
        if (!in_array($stage, ['finance', 'shop_owner'], true)) {
            throw ValidationException::withMessages([
                'stage' => ['Invalid rejection stage.'],
            ]);
        }

        if ($stage === 'shop_owner' && (string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
            throw ValidationException::withMessages([
                'finance_status' => ['Shop owner rejection requires finance initial approval first.'],
            ]);
        }

        $payload = [
            'status' => 'rejected',
            'approved_by' => $actorId,
            'approved_at' => now(),
            'failure_reason' => Str::limit(trim($rejectionReason), 255, ''),
            'failed_at' => now(),
        ];

        if ($stage === 'finance') {
            $payload['finance_status'] = 'rejected';
        } else {
            $payload['shop_owner_status'] = 'rejected';
        }

        $refund->update($payload);

        return $refund->fresh();
    }

    public function execute(PosRefund $refund, int $actorId, string $executionMode = 'manual', ?string $executionNote = null): PosRefund
    {
        if (in_array((string) $refund->status, ['succeeded', 'processing'], true)) {
            return $refund->fresh();
        }

        if ((string) $refund->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => ['Only approved refunds can be executed.'],
            ]);
        }

        $financeStatus = (string) ($refund->finance_status ?? 'pending');
        $ownerStatus = (string) ($refund->shop_owner_status ?? 'pending');

        // Backward compatibility: legacy records may be fully approved without staged fields populated.
        $isLegacyApproved = $financeStatus === 'pending' && $ownerStatus === 'pending' && (string) $refund->status === 'approved';

        if ($isLegacyApproved) {
            $financeStatus = 'approved';
            $ownerStatus = 'skipped';
        }

        if ($financeStatus !== 'approved') {
            throw ValidationException::withMessages([
                'finance_status' => ['Finance final approval is required before execution.'],
            ]);
        }

        if (!in_array($ownerStatus, ['approved', 'skipped'], true)) {
            throw ValidationException::withMessages([
                'shop_owner_status' => ['Shop owner approval is required before execution for this refund.'],
            ]);
        }

        $source = $refund->sourceTransaction()->firstOrFail();

        $approvedAmount = round((float) ($refund->approved_amount ?? $refund->requested_amount), 2);
        if ($approvedAmount <= 0) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount must be greater than zero before execution.'],
            ]);
        }

        if ($executionMode === 'gateway') {
            return $this->executeViaGateway($refund, $source, $actorId, $approvedAmount, $executionNote);
        }

        return $this->markRefundSucceeded($refund, $source, $actorId, $approvedAmount, 'manual', $executionNote, null, null);
    }

    private function executeViaGateway(PosRefund $refund, PosTransaction $source, int $actorId, float $approvedAmount, ?string $executionNote): PosRefund
    {
        $shopOwner = ShopOwner::query()->find((int) $source->shop_owner_id);
        $secretKey = trim((string) ($shopOwner?->paymongo_secret_key ?? ''));
        if ($secretKey === '') {
            return $this->markRefundFailed($refund, $actorId, 'Payment gateway is not configured for this shop.', $executionNote);
        }

        $paymentReference = trim((string) ($refund->paymongo_payment_id ?? ''));
        if ($paymentReference === '') {
            $paymentReference = trim((string) ($source->paymentLines()
                ->whereIn('tender_type', ['paymongo_card', 'paymongo_wallet'])
                ->whereNotNull('provider_reference')
                ->value('provider_reference') ?? ''));
        }

        if ($paymentReference === '') {
            return $this->markRefundFailed($refund, $actorId, 'Unable to resolve payment reference for gateway refund.', $executionNote);
        }

        $refund->update([
            'status' => 'processing',
            'execution_mode' => 'gateway',
            'execution_notes' => $executionNote ? Str::limit(trim($executionNote), 1000, '') : null,
            'paymongo_payment_id' => $paymentReference,
            'executed_by' => $actorId,
            'executed_at' => now(),
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        $gatewayResult = app(PaymongoRefundService::class)->createRefund(
            secretKey: $secretKey,
            paymentId: $paymentReference,
            amountInCentavos: (int) round($approvedAmount * 100),
            reason: 'requested_by_customer'
        );

        if (!($gatewayResult['success'] ?? false)) {
            return $this->markRefundFailed($refund->fresh(), $actorId, (string) ($gatewayResult['message'] ?? 'Refund request failed'), $executionNote);
        }

        $gatewayStatus = strtolower((string) ($gatewayResult['status'] ?? 'processing'));
        $refundId = trim((string) ($gatewayResult['refund_id'] ?? ''));

        if (in_array($gatewayStatus, ['succeeded', 'completed', 'paid'], true)) {
            return $this->markRefundSucceeded($refund->fresh(), $source, $actorId, $approvedAmount, 'gateway', $executionNote, $paymentReference, $refundId !== '' ? $refundId : null);
        }

        $refund->update([
            'status' => 'processing',
            'paymongo_refund_id' => $refundId !== '' ? $refundId : null,
            'execution_mode' => 'gateway',
            'execution_notes' => $executionNote ? Str::limit(trim($executionNote), 1000, '') : null,
            'executed_by' => $actorId,
            'executed_at' => now(),
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        return $refund->fresh();
    }

    private function markRefundSucceeded(
        PosRefund $refund,
        PosTransaction $source,
        int $actorId,
        float $approvedAmount,
        string $executionMode,
        ?string $executionNote,
        ?string $paymongoPaymentId,
        ?string $paymongoRefundId,
    ): PosRefund {
        $refund->update([
            'status' => 'succeeded',
            'approved_amount' => round($approvedAmount, 2),
            'execution_mode' => $executionMode,
            'execution_notes' => $executionNote ? Str::limit(trim($executionNote), 1000, '') : null,
            'paymongo_payment_id' => $paymongoPaymentId,
            'paymongo_refund_id' => $paymongoRefundId,
            'executed_by' => $actorId,
            'executed_at' => now(),
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        $totalRefundedForTransaction = (float) PosRefund::query()
            ->where('source_transaction_id', $source->id)
            ->where('status', 'succeeded')
            ->sum('approved_amount');

        $sourceStatus = $totalRefundedForTransaction >= (float) $source->paid_amount
            ? 'refunded'
            : 'partially_refunded';

        $source->update(['status' => $sourceStatus]);

        $repair = RepairRequest::query()->find((int) $source->module_reference_id);
        if ($repair) {
            $totalRefundedForRepair = (float) PosRefund::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->where('status', 'succeeded')
                ->sum('approved_amount');

            $repair->update([
                'total_refunded_amount' => $totalRefundedForRepair,
                'payment_status' => $sourceStatus === 'refunded' ? 'refunded' : 'partially_refunded',
                'payment_status_derived' => $sourceStatus === 'refunded' ? 'refunded' : 'partially_refunded',
            ]);
        }

        return $refund->fresh();
    }

    private function markRefundFailed(PosRefund $refund, int $actorId, string $reason, ?string $executionNote): PosRefund
    {
        $refund->update([
            'status' => 'failed',
            'execution_mode' => 'gateway',
            'execution_notes' => $executionNote ? Str::limit(trim($executionNote), 1000, '') : null,
            'executed_by' => $actorId,
            'executed_at' => now(),
            'failure_reason' => Str::limit(trim($reason), 255, ''),
            'failed_at' => now(),
        ]);

        return $refund->fresh();
    }
}
