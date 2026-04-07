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
            'paymongo_payment_ids' => $this->normalizeGatewayReferences(
                is_array($payload['paymongo_payment_ids'] ?? null) ? $payload['paymongo_payment_ids'] : []
            ),
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

        $paymongoPaymentIds = $this->normalizeGatewayReferences(
            is_array($payload['paymongo_payment_ids'] ?? null) ? $payload['paymongo_payment_ids'] : []
        );
        $paymongoPaymentId = trim((string) ($payload['paymongo_payment_id'] ?? ''));
        if ($workflowSource === 'online_myrepair') {
            $repair = RepairRequest::query()->find((int) $source->module_reference_id);
            if ($repair) {
                $paymongoPaymentIds = $this->normalizeGatewayReferences(array_merge(
                    $paymongoPaymentIds,
                    is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : [],
                    [(string) ($repair->paymongo_payment_id ?? '')],
                ));

                if ($paymongoPaymentId === '') {
                    $paymongoPaymentId = trim((string) ($repair->paymongo_payment_id ?? ''));
                }
            }
        }

        if ($paymongoPaymentId !== '' && !in_array($paymongoPaymentId, $paymongoPaymentIds, true)) {
            $paymongoPaymentIds[] = $paymongoPaymentId;
        }

        $refund->update([
            'preferred_return_channel' => $payload['preferred_return_channel'] ?? null,
            'preferred_return_account_name' => $payload['preferred_return_account_name'] ?? null,
            'preferred_return_account_ref' => $payload['preferred_return_account_ref'] ?? null,
            'customer_payout_consent' => (bool) ($payload['customer_payout_consent'] ?? false),
            'paymongo_payment_id' => $paymongoPaymentId !== '' ? $paymongoPaymentId : null,
            'paymongo_payment_ids' => !empty($paymongoPaymentIds) ? $paymongoPaymentIds : null,
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
        $repairPaymongoPaymentId = '';
        if ($workflowSource === 'online_myrepair' && (string) $source->module_type === 'repair') {
            $repairPaymongoPaymentId = trim((string) (RepairRequest::query()
                ->whereKey((int) $source->module_reference_id)
                ->value('paymongo_payment_id') ?? ''));
        }

        $sourceGatewayPaid = $this->sumTrustedGatewayPaid($source, $repairPaymongoPaymentId);

        if ($workflowSource === 'online_myrepair' && (string) $source->module_type === 'repair') {
            if ($sourceGatewayPaid > 0) {
                return max(0.0, min($requestedAmount, round($sourceGatewayPaid, 2)));
            }

            if (!$this->looksLikeGatewayProviderReference($repairPaymongoPaymentId)) {
                return 0.0;
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

    public function reconcileGatewayProcessingRefund(PosRefund $refund): PosRefund
    {
        if ((string) ($refund->status ?? '') !== 'processing') {
            return $refund;
        }

        $source = $refund->sourceTransaction()->first();
        if (!$source) {
            return $refund;
        }

        $secretKey = trim((string) (ShopOwner::query()->whereKey((int) $source->shop_owner_id)->value('paymongo_secret_key') ?? ''));
        if ($secretKey === '') {
            return $refund;
        }

        $refundIds = $this->normalizeGatewayRefundReferences(array_merge(
            is_array($refund->paymongo_refund_ids) ? $refund->paymongo_refund_ids : [],
            [(string) ($refund->paymongo_refund_id ?? '')],
        ));

        if (empty($refundIds)) {
            return $refund;
        }

        $statuses = [];
        foreach ($refundIds as $refundId) {
            $gateway = app(PaymongoRefundService::class)->getRefundStatus($secretKey, $refundId);
            if (!($gateway['success'] ?? false)) {
                return $refund;
            }

            $statuses[] = strtolower((string) ($gateway['status'] ?? 'processing'));
        }

        $hasFailure = collect($statuses)
            ->contains(fn ($status) => in_array($status, ['failed', 'canceled', 'cancelled'], true));

        if ($hasFailure) {
            return $this->markRefundFailed(
                refund: $refund->fresh(),
                actorId: (int) ($refund->executed_by ?? 0),
                reason: 'paymongo_refund_failed',
                executionNote: (string) ($refund->execution_notes ?: null),
            );
        }

        $allSucceeded = !empty($statuses) && collect($statuses)
            ->every(fn ($status) => in_array($status, ['succeeded', 'completed', 'paid'], true));

        if ($allSucceeded) {
            $approvedAmount = round((float) ($refund->approved_amount ?? $refund->requested_amount), 2);
            if ($approvedAmount <= 0) {
                return $refund;
            }

            $paymentReferences = $this->normalizeGatewayReferences(array_merge(
                is_array($refund->paymongo_payment_ids) ? $refund->paymongo_payment_ids : [],
                [(string) ($refund->paymongo_payment_id ?? '')],
            ));

            $settled = $this->markRefundSucceeded(
                refund: $refund->fresh(),
                source: $source,
                actorId: (int) ($refund->executed_by ?? 0),
                approvedAmount: $approvedAmount,
                executionMode: (string) ($refund->execution_mode ?: 'gateway'),
                executionNote: (string) ($refund->execution_notes ?: null),
                paymongoPaymentId: $paymentReferences[0] ?? (string) ($refund->paymongo_payment_id ?: null),
                paymongoRefundId: $refundIds[0] ?? (string) ($refund->paymongo_refund_id ?: null),
            );

            $settled->update([
                'paymongo_payment_ids' => !empty($paymentReferences) ? $paymentReferences : null,
                'paymongo_refund_ids' => !empty($refundIds) ? $refundIds : null,
            ]);

            return $settled->fresh();
        }

        return $refund;
    }

    private function executeViaGateway(PosRefund $refund, PosTransaction $source, int $actorId, float $approvedAmount, ?string $executionNote): PosRefund
    {
        $shopOwner = ShopOwner::query()->find((int) $source->shop_owner_id);
        $secretKey = trim((string) ($shopOwner?->paymongo_secret_key ?? ''));
        if ($secretKey === '') {
            return $this->markRefundFailed($refund, $actorId, 'Payment gateway is not configured for this shop.', $executionNote);
        }

        $paymentReferences = $this->resolveGatewayPaymentReferences($refund, $source);
        if (empty($paymentReferences)) {
            return $this->markRefundFailed($refund, $actorId, 'Unable to resolve payment reference for gateway refund.', $executionNote);
        }

        $refund->loadMissing('legs');
        $gatewayLegAmount = round((float) collect($refund->legs)
            ->filter(fn ($leg) => (string) ($leg->leg_type ?? '') === 'gateway')
            ->sum(fn ($leg) => (float) ($leg->approved_amount ?? $leg->requested_amount ?? 0)), 2);

        $targetGatewayAmount = $gatewayLegAmount > 0
            ? min($approvedAmount, $gatewayLegAmount)
            : $approvedAmount;

        if ($targetGatewayAmount <= 0) {
            return $this->markRefundFailed($refund, $actorId, 'Resolved gateway refund amount is invalid.', $executionNote);
        }

        $remaining = round($targetGatewayAmount, 2);
        $allocations = [];

        foreach ($paymentReferences as $paymentReference) {
            if ($remaining <= 0) {
                break;
            }

            $referenceCap = $this->resolveGatewayReferenceRefundCap($secretKey, $source, $paymentReference);
            if ($referenceCap <= 0) {
                continue;
            }

            $allocated = round(min($remaining, $referenceCap), 2);
            if ($allocated <= 0) {
                continue;
            }

            $allocations[] = [
                'payment_reference' => $paymentReference,
                'amount' => $allocated,
            ];

            $remaining = round($remaining - $allocated, 2);
        }

        if (empty($allocations)) {
            return $this->markRefundFailed($refund, $actorId, 'Resolved gateway refund amount exceeds available PayMongo payment capacity.', $executionNote);
        }

        $effectiveExecutionNote = $executionNote;
        if ($remaining > 0) {
            $effectiveExecutionNote = $this->appendExecutionNote(
                base: (string) ($executionNote ?? ''),
                suffix: sprintf('Gateway refund amount capped by available payment capacity. Unallocated amount: %.2f.', $remaining),
            );
        }

        $refund->update([
            'status' => 'processing',
            'execution_mode' => 'gateway',
            'execution_notes' => $effectiveExecutionNote ? Str::limit(trim($effectiveExecutionNote), 1000, '') : null,
            'paymongo_payment_id' => $allocations[0]['payment_reference'],
            'paymongo_payment_ids' => collect($allocations)->pluck('payment_reference')->unique()->values()->all(),
            'paymongo_refund_id' => null,
            'paymongo_refund_ids' => null,
            'executed_by' => $actorId > 0 ? $actorId : null,
            'executed_at' => now(),
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        $submittedRefundIds = [];
        $submittedAmount = 0.0;
        $hasProcessingLeg = false;

        foreach ($allocations as $allocation) {
            $gatewayResult = app(PaymongoRefundService::class)->createRefund(
                secretKey: $secretKey,
                paymentId: (string) $allocation['payment_reference'],
                amountInCentavos: (int) round(((float) $allocation['amount']) * 100),
                reason: 'requested_by_customer'
            );

            if (!($gatewayResult['success'] ?? false)) {
                if (!empty($submittedRefundIds) && $submittedAmount > 0) {
                    $refund->update([
                        'status' => 'processing',
                        'paymongo_refund_id' => $submittedRefundIds[0],
                        'paymongo_refund_ids' => $submittedRefundIds,
                        'execution_notes' => Str::limit(trim($this->appendExecutionNote(
                            base: (string) ($refund->execution_notes ?? $executionNote ?? ''),
                            suffix: 'Partial gateway refund submitted; manual verification required. Last error: ' . (string) ($gatewayResult['message'] ?? 'Refund request failed'),
                        )), 1000, ''),
                    ]);

                    return $refund->fresh();
                }

                return $this->markRefundFailed($refund->fresh(), $actorId, (string) ($gatewayResult['message'] ?? 'Refund request failed'), $executionNote);
            }

            $submittedAmount = round($submittedAmount + (float) $allocation['amount'], 2);

            $gatewayStatus = strtolower((string) ($gatewayResult['status'] ?? 'processing'));
            $refundId = trim((string) ($gatewayResult['refund_id'] ?? ''));

            if ($refundId !== '') {
                $submittedRefundIds[] = $refundId;
            }

            if (!in_array($gatewayStatus, ['succeeded', 'completed', 'paid'], true)) {
                $hasProcessingLeg = true;
            }
        }

        if ($submittedAmount <= 0) {
            return $this->markRefundFailed($refund->fresh(), $actorId, 'Resolved gateway refund amount is invalid.', $executionNote);
        }

        if ($hasProcessingLeg) {
            $refund->update([
                'status' => 'processing',
                'paymongo_refund_id' => $submittedRefundIds[0] ?? null,
                'paymongo_refund_ids' => !empty($submittedRefundIds) ? $submittedRefundIds : null,
                'execution_mode' => 'gateway',
                'execution_notes' => $effectiveExecutionNote ? Str::limit(trim($effectiveExecutionNote), 1000, '') : null,
                'executed_by' => $actorId,
                'executed_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            return $refund->fresh();
        }

        $succeeded = $this->markRefundSucceeded(
            refund: $refund->fresh(),
            source: $source,
            actorId: $actorId,
            approvedAmount: $submittedAmount,
            executionMode: 'gateway',
            executionNote: $effectiveExecutionNote,
            paymongoPaymentId: $allocations[0]['payment_reference'] ?? null,
            paymongoRefundId: $submittedRefundIds[0] ?? null,
        );

        $succeeded->update([
            'paymongo_payment_ids' => collect($allocations)->pluck('payment_reference')->unique()->values()->all(),
            'paymongo_refund_ids' => !empty($submittedRefundIds) ? $submittedRefundIds : null,
        ]);

        return $succeeded->fresh();
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
            'execution_notes' => $executionNote
                ? Str::limit(trim($executionNote), 1000, '')
                : ($refund->execution_notes ? Str::limit(trim((string) $refund->execution_notes), 1000, '') : null),
            'paymongo_payment_id' => $paymongoPaymentId ?? $refund->paymongo_payment_id,
            'paymongo_refund_id' => $paymongoRefundId ?? $refund->paymongo_refund_id,
            'executed_by' => $actorId > 0 ? $actorId : ($refund->executed_by ?? null),
            'executed_at' => $refund->executed_at ?? now(),
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
            'execution_notes' => $executionNote
                ? Str::limit(trim($executionNote), 1000, '')
                : ($refund->execution_notes ? Str::limit(trim((string) $refund->execution_notes), 1000, '') : null),
            'executed_by' => $actorId > 0 ? $actorId : ($refund->executed_by ?? null),
            'executed_at' => $refund->executed_at ?? now(),
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

        if ($workflowSource === 'shop_pos_repair') {
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

    private function sumTrustedGatewayPaid(PosTransaction $source, ?string $repairPaymongoPaymentId = null): float
    {
        $source->loadMissing('paymentLines:id,pos_transaction_id,tender_type,provider_reference,amount,status');

        $hasStoredGatewayPaymentId = $this->looksLikeGatewayProviderReference((string) ($repairPaymongoPaymentId ?? ''));

        return round((float) collect($source->paymentLines)
            ->filter(function ($line) use ($hasStoredGatewayPaymentId) {
                if ((string) ($line->status ?? '') !== 'paid') {
                    return false;
                }

                if (!in_array((string) ($line->tender_type ?? ''), ['paymongo_card', 'paymongo_wallet'], true)) {
                    return false;
                }

                if ($hasStoredGatewayPaymentId) {
                    return true;
                }

                return $this->looksLikeGatewayProviderReference((string) ($line->provider_reference ?? ''));
            })
            ->sum(fn ($line) => (float) ($line->amount ?? 0)), 2);
    }

    private function normalizeGatewayReferences(array $references): array
    {
        return collect($references)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '' && $this->looksLikeGatewayProviderReference($value))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeGatewayRefundReferences(array $references): array
    {
        return collect($references)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => str_starts_with(strtolower($value), 're_'))
            ->unique()
            ->values()
            ->all();
    }

    private function resolveGatewayPaymentReferences(PosRefund $refund, PosTransaction $source): array
    {
        $references = $this->normalizeGatewayReferences(array_merge(
            is_array($refund->paymongo_payment_ids) ? $refund->paymongo_payment_ids : [],
            [(string) ($refund->paymongo_payment_id ?? '')],
        ));

        if (empty($references) && (string) ($source->module_type ?? '') === 'repair') {
            $repair = RepairRequest::query()->find((int) $source->module_reference_id);
            if ($repair) {
                $references = $this->normalizeGatewayReferences(array_merge(
                    is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : [],
                    [(string) ($repair->paymongo_payment_id ?? '')],
                ));
            }
        }

        if (empty($references)) {
            $source->loadMissing('paymentLines:id,pos_transaction_id,tender_type,provider_reference,status');
            $references = $this->normalizeGatewayReferences(
                collect($source->paymentLines)
                    ->filter(fn ($line) => in_array((string) ($line->tender_type ?? ''), ['paymongo_card', 'paymongo_wallet'], true)
                        && (string) ($line->status ?? '') === 'paid')
                    ->pluck('provider_reference')
                    ->all()
            );
        }

        return $references;
    }

    private function resolveGatewayReferenceRefundCap(string $secretKey, PosTransaction $source, string $paymentReference): float
    {
        $candidates = [];

        $paymongoAmountInCentavos = app(PaymongoRefundService::class)->getPaymentAmountInCentavos($secretKey, $paymentReference);
        if (is_int($paymongoAmountInCentavos) && $paymongoAmountInCentavos > 0) {
            $candidates[] = round($paymongoAmountInCentavos / 100, 2);
        }

        $trustedSourceAmount = $this->sumTrustedGatewayPaidForReference($source, $paymentReference);
        if ($trustedSourceAmount > 0) {
            $candidates[] = $trustedSourceAmount;
        }

        if (empty($candidates)) {
            return 0.0;
        }

        $resolvedCap = count($candidates) === 1 ? $candidates[0] : min(...$candidates);

        return round((float) $resolvedCap, 2);
    }

    private function sumTrustedGatewayPaidForReference(PosTransaction $source, string $paymentReference): float
    {
        $needle = strtolower(trim($paymentReference));
        if ($needle === '') {
            return 0.0;
        }

        $source->loadMissing('paymentLines:id,pos_transaction_id,tender_type,provider_reference,amount,status');

        return round((float) collect($source->paymentLines)
            ->filter(function ($line) use ($needle) {
                if ((string) ($line->status ?? '') !== 'paid') {
                    return false;
                }

                if (!in_array((string) ($line->tender_type ?? ''), ['paymongo_card', 'paymongo_wallet'], true)) {
                    return false;
                }

                return strtolower(trim((string) ($line->provider_reference ?? ''))) === $needle;
            })
            ->sum(fn ($line) => (float) ($line->amount ?? 0)), 2);
    }

    private function appendExecutionNote(string $base, string $suffix): string
    {
        $base = trim($base);
        $suffix = trim($suffix);

        if ($base === '') {
            return $suffix;
        }

        if ($suffix === '') {
            return $base;
        }

        return $base . "\n\n" . $suffix;
    }

    private function looksLikeGatewayProviderReference(?string $reference): bool
    {
        $value = strtolower(trim((string) ($reference ?? '')));
        if ($value === '') {
            return false;
        }

        return str_starts_with($value, 'pay_')
            || str_starts_with($value, 'pi_')
            || str_starts_with($value, 'src_')
            || str_starts_with($value, 'pmw_')
            || str_starts_with($value, 'pmc_');
    }
}
