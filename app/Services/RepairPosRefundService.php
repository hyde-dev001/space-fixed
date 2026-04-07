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
    private const REPAIR_VAT_RATE_PERCENT = 12.0;

    public function computeRepairRefundableAmount(int $repairId): float
    {
        $paid = $this->resolveRepairPaidAmount($repairId);

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
        $workflowSource = strtolower(trim((string) ($payload['workflow_source'] ?? 'pos')));

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

        if ($this->shouldUseRepairWideLimit($source, $workflowSource, $reasonCode)) {
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
            'workflow_source' => $workflowSource,
            'request_type' => $payload['request_type'],
            'requested_amount' => $requested,
            'reason_code' => $payload['reason_code'],
            'reason_notes' => $payload['reason_notes'] ?? null,
            'paymongo_payment_id' => $payload['paymongo_payment_id'] ?? null,
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'requested_by' => $actorId > 0 ? $actorId : null,
            'requested_at' => now(),
        ]);
    }

    public function createRefundWithSplitLegs(PosTransaction $source, array $payload, int $actorId): PosRefund
    {
        $refund = $this->requestRefund($source, $payload, $actorId);
        $workflowSource = strtolower(trim((string) ($refund->workflow_source ?? $payload['workflow_source'] ?? 'pos')));

        $paymongoPaymentId = trim((string) ($payload['paymongo_payment_id'] ?? ''));
        if ($paymongoPaymentId === '' && $workflowSource === 'online_myrepair') {
            $paymongoPaymentId = trim((string) (RepairRequest::query()
                ->whereKey((int) $source->module_reference_id)
                ->value('paymongo_payment_id') ?? ''));
        }

        $refund->update([
            'preferred_return_channel' => $payload['preferred_return_channel'] ?? null,
            'preferred_return_account_name' => $payload['preferred_return_account_name'] ?? null,
            'preferred_return_account_ref' => $payload['preferred_return_account_ref'] ?? null,
            'customer_payout_consent' => (bool) ($payload['customer_payout_consent'] ?? false),
            'paymongo_payment_id' => $paymongoPaymentId !== '' ? $paymongoPaymentId : null,
        ]);

        if (!config('orders.repair_split_refund_enabled', true)) {
            return $refund->fresh();
        }

        $requestedAmount = round((float) ($payload['requested_amount'] ?? 0), 2);
        if ($requestedAmount <= 0) {
            return $refund->fresh();
        }

        $gatewayAmount = array_key_exists('gateway_amount', $payload)
            ? round((float) $payload['gateway_amount'], 2)
            : $this->inferGatewayAmount($source, $requestedAmount, $workflowSource);

        $gatewayAmount = max(0.0, min($requestedAmount, $gatewayAmount));
        $posAmount = max(0.0, round($requestedAmount - $gatewayAmount, 2));

        $sourceReceiptNo = (string) ($source->receipt?->receipt_no ?? $source->transaction_no);

        if ($gatewayAmount > 0) {
            $refund->legs()->create([
                'leg_type' => 'gateway',
                'requested_amount' => $gatewayAmount,
                'status' => 'requested',
                'source_transaction_id' => $source->id,
                'source_receipt_no' => $sourceReceiptNo,
            ]);
        }

        if ($posAmount > 0) {
            $refund->legs()->create([
                'leg_type' => 'pos_manual',
                'requested_amount' => $posAmount,
                'status' => 'requested',
                'source_transaction_id' => $source->id,
                'source_receipt_no' => $sourceReceiptNo,
            ]);
        }

        return $refund->fresh('legs');
    }

    private function inferGatewayAmount(PosTransaction $source, float $requestedAmount, string $workflowSource = 'pos'): float
    {
        $sourceGatewayPaid = (float) $source->paymentLines()
            ->whereIn('tender_type', ['paymongo_card', 'paymongo_wallet'])
            ->where('status', 'paid')
            ->sum('amount');

        if ($workflowSource === 'online_myrepair' && (string) $source->module_type === 'repair') {
            if ($sourceGatewayPaid > 0) {
                return max(0.0, min($requestedAmount, round($sourceGatewayPaid, 2)));
            }

            $repairId = (int) $source->module_reference_id;
            $repairPaid = $this->resolveRepairPaidAmount($repairId);
            $posPaid = $this->sumRepairPosPaidAmount($repairId);
            $gatewayPaid = max(0.0, round($repairPaid - $posPaid, 2));

            return max(0.0, min($requestedAmount, $gatewayPaid));
        }

        return max(0.0, min($requestedAmount, round($sourceGatewayPaid, 2)));
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
        $resolvedApprovedAmount = $approvedAmount;
        if ($resolvedApprovedAmount === null && strtolower(trim($stage)) === 'shop_owner') {
            $existingApprovedAmount = (float) ($refund->approved_amount ?? 0);
            if ($existingApprovedAmount > 0) {
                $resolvedApprovedAmount = $existingApprovedAmount;
            }
        }

        $amountToApprove = $resolvedApprovedAmount ?? (float) $refund->requested_amount;
        if ($amountToApprove <= 0) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount must be greater than zero.'],
            ]);
        }

        $alreadyCommittedQuery = PosRefund::query()
            ->where('id', '!=', $refund->id);

        $workflowSource = strtolower((string) ($refund->workflow_source ?? 'pos'));
        if ($this->shouldUseRepairWideLimit($source, $workflowSource, (string) ($refund->reason_code ?? ''))) {
            $alreadyCommitted = (float) $alreadyCommittedQuery
                ->where('module_type', 'repair')
                ->where('module_reference_id', (int) $source->module_reference_id)
                ->whereIn('status', ['approved', 'processing'])
                ->sum('approved_amount');

            $maxRefundable = max(0, $this->computeRepairRefundableAmount((int) $source->module_reference_id) - $alreadyCommitted);
        } else {
            $alreadyCommitted = (float) $alreadyCommittedQuery
                ->where('source_transaction_id', $source->id)
                ->whereIn('status', ['approved', 'processing', 'succeeded'])
                ->sum('approved_amount');

            $maxRefundable = max(0, (float) $source->paid_amount - $alreadyCommitted);
        }

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
            $workflowSource = strtolower((string) ($refund->workflow_source ?? 'pos'));
            $requiresRepairerGate = $workflowSource === 'online_myrepair';

            if ($requiresRepairerGate && (string) ($refund->repairer_status ?? 'pending') !== 'approved') {
                throw ValidationException::withMessages([
                    'repairer_status' => ['Finance approval requires repairer endorsement first.'],
                ]);
            }

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
                'approved_by' => $actorId > 0 ? $actorId : null,
                'approved_at' => now(),
                'finance_status' => $requiresOwnerApproval ? 'approved_initial' : 'approved',
                'shop_owner_status' => $requiresOwnerApproval ? 'pending' : 'skipped',
                'reason_notes' => $notes !== '' ? Str::limit($notes, 2000, '') : null,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            return $refund->fresh();
        }

        $isIndividualRegistration = $this->isIndividualShopOwner((int) $refund->shop_owner_id);

        if (!$isIndividualRegistration && (string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
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
            'approved_by' => $actorId > 0 ? $actorId : null,
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

        $isIndividualRegistration = $this->isIndividualShopOwner((int) $refund->shop_owner_id);

        if ($stage === 'shop_owner' && !$isIndividualRegistration && (string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
            throw ValidationException::withMessages([
                'finance_status' => ['Shop owner rejection requires finance initial approval first.'],
            ]);
        }

        $payload = [
            'status' => 'rejected',
            'approved_by' => $actorId > 0 ? $actorId : null,
            'approved_at' => now(),
            'failure_reason' => Str::limit(trim($rejectionReason), 255, ''),
            'failed_at' => now(),
        ];

        if ($stage === 'finance') {
            $payload['finance_status'] = 'rejected';
        } else {
            $payload['shop_owner_status'] = 'rejected';

            if ($isIndividualRegistration) {
                $payload['finance_status'] = 'rejected';
            }
        }

        $refund->update($payload);

        return $refund->fresh();
    }

    public function execute(
        PosRefund $refund,
        int $actorId,
        string $executionMode = 'manual',
        ?string $executionNote = null,
        array $executionContext = []
    ): PosRefund
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

        $hasPosManualLeg = $refund->legs()->where('leg_type', 'pos_manual')->exists();
        if ($executionMode === 'manual' && $hasPosManualLeg) {
            if (empty($executionContext['execution_channel'])) {
                throw ValidationException::withMessages([
                    'execution_channel' => ['Execution channel is required for POS manual refund execution.'],
                ]);
            }

            if (empty($executionContext['execution_reference'])) {
                throw ValidationException::withMessages([
                    'execution_reference' => ['Execution reference is required for POS manual refund execution.'],
                ]);
            }

            if (empty($executionContext['execution_proof_urls']) || !is_array($executionContext['execution_proof_urls'])) {
                throw ValidationException::withMessages([
                    'execution_proof_urls' => ['At least one execution proof URL is required for POS manual refund execution.'],
                ]);
            }
        }

        $refund->update([
            'execution_channel' => $executionContext['execution_channel'] ?? null,
            'execution_reference' => $executionContext['execution_reference'] ?? null,
            'execution_amount' => isset($executionContext['execution_amount'])
                ? round((float) $executionContext['execution_amount'], 2)
                : null,
            'execution_proof_urls' => $executionContext['execution_proof_urls'] ?? null,
        ]);

        return $this->markRefundSucceeded($refund->fresh(), $source, $actorId, $approvedAmount, 'manual', $executionNote, null, null);
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
            'executed_by' => $actorId > 0 ? $actorId : null,
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
            'executed_by' => $actorId > 0 ? $actorId : null,
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
            'executed_by' => $actorId > 0 ? $actorId : null,
            'executed_at' => now(),
            'failure_reason' => Str::limit(trim($reason), 255, ''),
            'failed_at' => now(),
        ]);

        return $refund->fresh();
    }

    private function shouldUseRepairWideLimit(PosTransaction $source, string $workflowSource, string $reasonCode): bool
    {
        if ((string) $source->module_type !== 'repair') {
            return false;
        }

        if ($workflowSource === 'online_myrepair') {
            return true;
        }

        return $reasonCode === 'customer_cancelled_repair';
    }

    private function isIndividualShopOwner(int $shopOwnerId): bool
    {
        if ($shopOwnerId <= 0) {
            return false;
        }

        return (string) (ShopOwner::query()->whereKey($shopOwnerId)->value('registration_type') ?? '') === 'individual';
    }

    private function sumRepairPosPaidAmount(int $repairId): float
    {
        return round((float) PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repairId)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->sum('paid_amount'), 2);
    }

    private function resolveRepairPaidAmount(int $repairId): float
    {
        $posPaid = $this->sumRepairPosPaidAmount($repairId);
        $repair = RepairRequest::query()->find($repairId);

        if (!$repair) {
            return max(0.0, $posPaid);
        }

        $storedPaid = round((float) ($repair->total_paid_amount ?? 0), 2);
        $paymentStatus = strtolower(trim((string) ($repair->payment_status ?? 'pending')));
        $policy = app(PaymentSettlementService::class)->normalizeRepairPaymentPolicy((string) ($repair->payment_policy ?? 'deposit_50'));
        $repairGrandTotal = $this->resolveRepairGrandTotal($repair);

        $resolved = max(0.0, $storedPaid, $posPaid);

        if ($paymentStatus === 'completed' || ($policy === 'full_upfront' && $paymentStatus === 'paid')) {
            return round(max($resolved, $repairGrandTotal), 2);
        }

        if ($paymentStatus === 'paid' && $policy === 'deposit_50') {
            return round(max($resolved, round($repairGrandTotal * 0.5, 2)), 2);
        }

        return round($resolved, 2);
    }

    private function resolveRepairGrandTotal(RepairRequest $repair): float
    {
        $finalTotal = round(max((float) ($repair->final_total ?? 0), (float) ($repair->total ?? 0)), 2);
        if ($finalTotal <= 0) {
            return 0.0;
        }

        $taxMode = $this->resolveRepairTaxMode($repair);
        if ($taxMode === 'vat_inclusive') {
            return $finalTotal;
        }

        return round($finalTotal + ($finalTotal * (self::REPAIR_VAT_RATE_PERCENT / 100)), 2);
    }

    private function resolveRepairTaxMode(RepairRequest $repair): string
    {
        $pricingTaxMode = strtolower((string) data_get($repair->pricing_breakdown, 'tax_mode', ''));
        if (in_array($pricingTaxMode, ['vat_inclusive', 'legacy_add_on'], true)) {
            return $pricingTaxMode;
        }

        $repair->loadMissing('latestPosTransaction:id,metadata');
        $latestPosTaxMode = strtolower((string) data_get($repair->latestPosTransaction?->metadata, 'tax_mode', ''));
        if (in_array($latestPosTaxMode, ['vat_inclusive', 'legacy_add_on'], true)) {
            return $latestPosTaxMode;
        }

        return 'legacy_add_on';
    }
}
