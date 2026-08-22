<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairPosRefundService
{
    private const FINAL_STATUSES = ['succeeded', 'failed', 'rejected', 'cancelled'];
    private const REPAIR_VAT_RATE_PERCENT = 12.0;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService,
        private readonly ?RepairRefundRecoveryService $repairRefundRecoveryService = null,
    ) {}

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

    public function computeRecordedRepairRefundableAmount(int $repairId): float
    {
        $repair = RepairRequest::query()->find($repairId);
        $paid = max(
            (float) ($repair?->total_paid_amount ?? 0),
            $this->sumRepairPosPaidAmount($repairId),
        );
        $refunded = (float) PosRefund::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repairId)
            ->where('status', 'succeeded')
            ->sum('approved_amount');

        return max(0.0, round($paid - $refunded, 2));
    }

    public function computeRecordedPaidIntakeDeliveryAmount(int $repairId): float
    {
        $repair = RepairRequest::query()->find($repairId);
        if (! $repair) {
            return 0.0;
        }

        $posAmount = (float) PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repairId)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->get()
            ->sum(function (PosTransaction $transaction): float {
                $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
                $leg = $metadata['leg'] ?? null;
                if ($leg !== 'intake' && ! ($leg === null && ($metadata['phase'] ?? null) === 'initial')) {
                    return 0.0;
                }

                return (float) ($metadata['delivery_amount'] ?? 0);
            });
        $onlineAmount = (float) RepairPaymentSession::query()
            ->where('repair_request_id', $repairId)
            ->where('phase', 'initial')
            ->where('status', 'paid')
            ->sum('delivery_amount');
        $recorded = max($posAmount, $onlineAmount);

        if ($recorded <= 0
            && (string) $repair->intake_delivery_method === 'shop_pickup'
            && $repair->intake_logistics_locked_at
            && (float) $repair->total_paid_amount >= (float) $repair->intake_delivery_fee) {
            $recorded = (float) $repair->intake_delivery_fee;
        }

        $paid = max((float) $repair->total_paid_amount, $this->sumRepairPosPaidAmount($repairId));

        return max(0.0, round(min($recorded, $paid), 2));
    }

    public function resolveRecordedRefundSource(RepairRequest $repair, int $actorId): ?PosTransaction
    {
        $source = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->latest('paid_at')
            ->latest('id')
            ->first();
        if ($source) {
            return $source;
        }

        $paymentReference = collect(is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : [])
            ->push((string) ($repair->paymongo_payment_id ?? ''))
            ->map(fn ($reference) => trim((string) $reference))
            ->first(fn (string $reference): bool => $this->looksLikeGatewayProviderReference($reference));
        $paidAmount = round((float) $repair->total_paid_amount, 2);
        if (! $paymentReference || $paidAmount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($repair, $actorId, $paymentReference, $paidAmount): PosTransaction {
            $lockedRepair = RepairRequest::query()->lockForUpdate()->findOrFail($repair->id);
            $existing = PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                ->latest('paid_at')
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }

            $pickupFee = $this->computeRecordedPaidIntakeDeliveryAmount((int) $repair->id);
            $transaction = PosTransaction::create([
                'transaction_no' => 'POS-BKF-RFD-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                'shop_owner_id' => (int) $repair->shop_owner_id,
                'module_type' => 'repair',
                'module_reference_id' => (int) $repair->id,
                'customer_type' => 'registered',
                'customer_id' => (int) $repair->user_id,
                'due_type' => 'full',
                'subtotal' => $paidAmount,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $paidAmount,
                'paid_amount' => $paidAmount,
                'status' => 'paid',
                'paid_at' => now(),
                'created_by' => $actorId > 0 ? $actorId : null,
                'metadata' => [
                    'source' => 'repair_refund_online_backfill',
                    'phase' => 'initial',
                    'leg' => 'intake',
                    'service_amount' => max(0.0, round($paidAmount - $pickupFee, 2)),
                    'delivery_amount' => $pickupFee,
                ],
            ]);
            PosPaymentLine::create([
                'pos_transaction_id' => $transaction->id,
                'tender_type' => str_starts_with(strtolower($paymentReference), 'pmc_')
                    ? 'paymongo_card'
                    : 'paymongo_wallet',
                'provider_reference' => $paymentReference,
                'amount' => $paidAmount,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            $lockedRepair->update(['latest_pos_transaction_id' => $transaction->id]);

            return $transaction;
        });
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

        $requiresOwnerApproval = $workflowSource === 'delivery_reconciliation'
            ? false
            : $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRefund(
                (int) $source->shop_owner_id,
                $requested,
            );

        $refund = PosRefund::create([
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
            'requires_owner_approval' => $requiresOwnerApproval,
            'requested_by' => $actorId > 0 ? $actorId : null,
            'requested_at' => now(),
        ]);

        if ($workflowSource !== 'delivery_reconciliation') {
            $this->notifyRefundRequested($refund, $source, $requested);
        }

        return $refund;
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

    public function executeDeliveryCompensation(
        RepairRequest $repair,
        float $amount,
        int $actorId,
        ?PosRefund $existingRefund = null,
    ): PosRefund {
        if ($existingRefund) {
            if ((string) $existingRefund->status === 'processing') {
                return $this->reconcileGatewayProcessingRefund($existingRefund);
            }
            if ((string) $existingRefund->status === 'succeeded') {
                return $existingRefund->fresh();
            }
        }

        $source = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->latest('paid_at')
            ->latest('id')
            ->first();

        if (! $source) {
            $paymentReference = collect(is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : [])
                ->push((string) ($repair->paymongo_payment_id ?? ''))
                ->map(fn ($reference) => trim((string) $reference))
                ->first(fn (string $reference): bool => $this->looksLikeGatewayProviderReference($reference));

            if (! $paymentReference || (float) $repair->total_paid_amount < $amount) {
                throw ValidationException::withMessages([
                    'action' => ['No refundable original payment channel was found for this delivery fee.'],
                ]);
            }

            $source = DB::transaction(function () use ($repair, $paymentReference, $actorId): PosTransaction {
                $paidAmount = round((float) $repair->total_paid_amount, 2);
                $transaction = PosTransaction::create([
                    'transaction_no' => 'POS-BKF-DEL-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                    'shop_owner_id' => (int) $repair->shop_owner_id,
                    'module_type' => 'repair',
                    'module_reference_id' => (int) $repair->id,
                    'customer_type' => 'registered',
                    'customer_id' => (int) $repair->user_id,
                    'due_type' => 'full',
                    'subtotal' => $paidAmount,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total_amount' => $paidAmount,
                    'paid_amount' => $paidAmount,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'created_by' => $actorId > 0 ? $actorId : null,
                    'metadata' => ['source' => 'repair_delivery_reconciliation_backfill'],
                ]);
                PosPaymentLine::create([
                    'pos_transaction_id' => $transaction->id,
                    'tender_type' => str_starts_with(strtolower($paymentReference), 'pmc_')
                        ? 'paymongo_card'
                        : 'paymongo_wallet',
                    'provider_reference' => $paymentReference,
                    'amount' => $paidAmount,
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                return $transaction;
            });
        }

        $refund = $existingRefund;
        if (! $refund || in_array((string) $refund->status, self::FINAL_STATUSES, true)) {
            $refund = $this->createRefundWithSplitLegs($source, [
                'workflow_source' => 'delivery_reconciliation',
                'request_type' => 'partial',
                'requested_amount' => round($amount, 2),
                'reason_code' => 'delivery_fee_reconciliation',
                'reason_notes' => 'Finance-approved repair delivery fee compensation.',
                'paymongo_payment_id' => $repair->paymongo_payment_id,
                'paymongo_payment_ids' => is_array($repair->paymongo_payment_ids)
                    ? $repair->paymongo_payment_ids
                    : [],
            ], $actorId);
            $refund->update([
                'repairer_status' => 'approved',
                'finance_status' => 'approved',
                'shop_owner_status' => 'skipped',
                'status' => 'approved',
                'approved_amount' => round($amount, 2),
                'approved_by' => $actorId > 0 ? $actorId : null,
                'approved_at' => now(),
            ]);
        }

        $refund->loadMissing('legs');
        $hasGateway = $refund->legs->contains(
            fn ($leg): bool => (string) $leg->leg_type === 'gateway' && (float) $leg->requested_amount > 0
        );

        return $this->execute(
            $refund->fresh('legs'),
            $actorId,
            $hasGateway ? 'gateway' : 'manual',
            'Repair delivery fee compensation.',
            [
                'execution_channel' => $hasGateway ? null : 'manual_cash',
                'execution_reference' => 'DELIVERY-COMP-'.$repair->id,
                'execution_amount' => round($amount, 2),
            ],
        );
    }

    private function inferGatewayAmount(PosTransaction $source, float $requestedAmount, string $workflowSource = 'pos'): float
    {
        $repairWideInference = in_array($workflowSource, ['online_myrepair', 'shop_pos_repair'], true);
        $repairPaymongoPaymentId = '';
        if ($repairWideInference && (string) $source->module_type === 'repair') {
            $repairPaymongoPaymentId = trim((string) (RepairRequest::query()
                ->whereKey((int) $source->module_reference_id)
                ->value('paymongo_payment_id') ?? ''));
        }

        $sourceGatewayPaid = $this->sumTrustedGatewayPaid($source, $repairPaymongoPaymentId);

        if ($repairWideInference && (string) $source->module_type === 'repair') {
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

        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);

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

            $refund->update([
                'status' => $requiresOwnerApproval ? 'requested' : 'approved',
                'approved_amount' => round($amountToApprove, 2),
                'approved_by' => $actorId > 0 ? $actorId : null,
                'approved_at' => now(),
                'finance_status' => $requiresOwnerApproval ? 'approved_initial' : 'approved',
                'shop_owner_status' => $requiresOwnerApproval ? 'pending' : 'skipped',
                'reason_notes' => $notes !== '' ? Str::limit($notes, 2000, '') : null,
            ]);

            $this->notifyRefundParties(
                refund: $refund,
                source: $source,
                title: 'Repair Refund Approved',
                message: 'Your repair refund request was approved by finance.',
                actionUrl: '/my-repairs',
                ownerTitle: 'Repair Refund Approved By Finance',
                ownerMessage: "Repair refund {$refund->refund_no} was approved by finance.",
                ownerActionUrl: $this->notificationService->ownerApprovalActionUrl('repair_refund', $refund->id),
                includeOwner: $requiresOwnerApproval,
            );

            return $refund->fresh();
        }

        $isIndividualRegistration = $this->isIndividualShopOwner((int) $refund->shop_owner_id);

        if (!$requiresOwnerApproval) {
            throw ValidationException::withMessages([
                'shop_owner_status' => ['Shop owner approval is not required by policy for this refund request.'],
            ]);
        }

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
        ]);

        $this->notifyRefundParties(
            refund: $refund,
            source: $source,
            title: 'Repair Refund Approved',
            message: 'Your repair refund request was approved by the shop owner.',
            actionUrl: '/my-repairs',
            includeOwner: false,
        );

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
        $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);

        if ($stage === 'shop_owner' && !$requiresOwnerApproval) {
            throw ValidationException::withMessages([
                'shop_owner_status' => ['Shop owner approval is not required by policy for this refund request.'],
            ]);
        }

        if ($stage === 'shop_owner' && !$isIndividualRegistration && (string) ($refund->finance_status ?? 'pending') !== 'approved_initial') {
            throw ValidationException::withMessages([
                'finance_status' => ['Shop owner rejection requires finance initial approval first.'],
            ]);
        }

        $payload = [
            'status' => 'rejected',
            'approved_by' => $actorId > 0 ? $actorId : null,
            'approved_at' => now(),
            'failure_reason' => trim((string) ($refund->failure_reason ?? '')) !== ''
                ? $refund->failure_reason
                : Str::limit(trim($rejectionReason), 255, ''),
            'failed_at' => $refund->failed_at ?? now(),
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

        $source = $refund->sourceTransaction()->first();
        if ($source) {
            $this->notifyRefundParties(
                refund: $refund,
                source: $source,
                title: 'Repair Refund Rejected',
                message: 'Your repair refund request was rejected. Please review the provided reason.',
                actionUrl: '/my-repairs',
                includeOwner: $requiresOwnerApproval,
            );
        }

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
        $status = (string) ($refund->status ?? '');
        if (in_array($status, ['succeeded', 'processing'], true)) {
            return $refund->fresh();
        }

        $financeStatus = (string) ($refund->finance_status ?? 'pending');
        $ownerStatus = (string) ($refund->shop_owner_status ?? 'pending');

        // Compatibility for stage-based records where approval states are complete
        // but the aggregate status remained in 'requested'.
        if (
            $status === 'requested'
            && $financeStatus === 'approved'
            && in_array($ownerStatus, ['approved', 'skipped'], true)
        ) {
            $refund->update(['status' => 'approved']);
            $refund = $refund->fresh();
            $status = (string) ($refund->status ?? 'approved');
        }

        if ($status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => ['Only approved refunds can be executed.'],
            ]);
        }

        // Backward compatibility: legacy records may be fully approved without staged fields populated.
        $isLegacyApproved = $financeStatus === 'pending' && $ownerStatus === 'pending' && $status === 'approved';

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

        $refund->loadMissing('legs');
        $workflowSource = strtolower(trim((string) ($refund->workflow_source ?? 'pos')));

        $gatewayLegAmount = round((float) collect($refund->legs)
            ->filter(fn ($leg) => (string) ($leg->leg_type ?? '') === 'gateway')
            ->sum(fn ($leg) => (float) ($leg->approved_amount ?? $leg->requested_amount ?? 0)), 2);

        $posManualLegAmount = round((float) collect($refund->legs)
            ->filter(fn ($leg) => (string) ($leg->leg_type ?? '') === 'pos_manual')
            ->sum(fn ($leg) => (float) ($leg->approved_amount ?? $leg->requested_amount ?? 0)), 2);

        $inferredGatewayAmount = 0.0;
        if ($gatewayLegAmount <= 0 && $posManualLegAmount <= 0) {
            $inferredGatewayAmount = $this->inferGatewayAmount($source, $approvedAmount, $workflowSource);
        }

        $resolvedGatewayAmount = round(max(0.0, min($approvedAmount, $gatewayLegAmount > 0 ? $gatewayLegAmount : $inferredGatewayAmount)), 2);
        $resolvedPosManualAmount = round(max(0.0, $posManualLegAmount > 0 ? $posManualLegAmount : ($approvedAmount - $resolvedGatewayAmount)), 2);

        $hasGatewayLeg = $resolvedGatewayAmount > 0;
        $hasPosManualLeg = $resolvedPosManualAmount > 0;

        if ($executionMode === 'gateway') {
            return $this->executeViaGateway(
                refund: $refund,
                source: $source,
                actorId: $actorId,
                approvedAmount: $approvedAmount,
                executionNote: $executionNote,
                gatewayAmountOverride: $resolvedGatewayAmount > 0 ? $resolvedGatewayAmount : null,
            );
        }

        $requiresPosManualExecutionMetadata = $hasPosManualLeg && $workflowSource === 'shop_pos_repair';

        if ($executionMode === 'manual' && $requiresPosManualExecutionMetadata) {
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
                    'execution_proof_urls' => ['At least one transaction screenshot is required for POS manual refund execution.'],
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

        if ($executionMode === 'manual' && $hasGatewayLeg) {
            return $this->executeViaGateway(
                refund: $refund->fresh(),
                source: $source,
                actorId: $actorId,
                approvedAmount: $approvedAmount,
                executionNote: $executionNote,
                gatewayAmountOverride: $resolvedGatewayAmount,
                finalApprovedAmount: $hasPosManualLeg ? $approvedAmount : null,
                finalExecutionMode: $hasPosManualLeg ? 'manual' : 'gateway',
            );
        }

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

        $refund->loadMissing('legs');
        $refundIds = $this->normalizeGatewayRefundReferences(array_merge(
            is_array($refund->paymongo_refund_ids) ? $refund->paymongo_refund_ids : [],
            [(string) ($refund->paymongo_refund_id ?? '')],
        ));

        if (empty($refundIds)) {
            if ($this->canAutoSettleManualProcessingRefund($refund)) {
                $approvedAmount = round((float) ($refund->approved_amount ?? $refund->requested_amount), 2);
                if ($approvedAmount > 0) {
                    Log::info('Auto-settling manual processing repair refund without gateway references', [
                        'refund_id' => (int) $refund->id,
                        'source_transaction_id' => (int) $source->id,
                    ]);

                    return $this->markRefundSucceeded(
                        refund: $refund->fresh(),
                        source: $source,
                        actorId: (int) ($refund->executed_by ?? 0),
                        approvedAmount: $approvedAmount,
                        executionMode: 'manual',
                        executionNote: (string) ($refund->execution_notes ?: null),
                        paymongoPaymentId: null,
                        paymongoRefundId: null,
                    );
                }
            }

            return $refund;
        }

        $secretKeyCandidates = $this->resolvePaymongoSecretKeyCandidates((int) ($source->shop_owner_id ?? 0));
        if (empty($secretKeyCandidates)) {
            return $refund;
        }

        $statuses = [];
        foreach ($refundIds as $refundId) {
            $gateway = $this->fetchRefundStatusUsingAnySecret($secretKeyCandidates, $refundId);
            if (!($gateway['success'] ?? false)) {
                Log::warning('PayMongo refund status reconciliation skipped due status lookup failure', [
                    'refund_id' => $refund->id,
                    'paymongo_refund_id' => $refundId,
                    'shop_owner_id' => (int) ($source->shop_owner_id ?? 0),
                    'message' => (string) ($gateway['message'] ?? 'Refund status lookup failed'),
                ]);
                return $refund;
            }

            $statuses[] = strtolower((string) ($gateway['status'] ?? 'processing'));
        }

        $hasFailure = collect($statuses)
            ->contains(fn ($status) => $this->isGatewayRefundFailureStatus($status));

        if ($hasFailure) {
            return $this->markRefundFailed(
                refund: $refund->fresh(),
                actorId: (int) ($refund->executed_by ?? 0),
                reason: 'paymongo_refund_failed',
                executionNote: (string) ($refund->execution_notes ?: null),
            );
        }

        $allSucceeded = !empty($statuses) && collect($statuses)
            ->every(fn ($status) => $this->isGatewayRefundSuccessStatus($status));

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

    private function canAutoSettleManualProcessingRefund(PosRefund $refund): bool
    {
        if (strtolower(trim((string) ($refund->execution_mode ?? 'manual'))) !== 'manual') {
            return false;
        }

        if (!$refund->executed_at) {
            return false;
        }

        $hasGatewayPaymentReference = !empty($this->normalizeGatewayReferences(array_merge(
            is_array($refund->paymongo_payment_ids) ? $refund->paymongo_payment_ids : [],
            [(string) ($refund->paymongo_payment_id ?? '')],
        )));

        if ($hasGatewayPaymentReference) {
            return false;
        }

        $hasGatewayLeg = collect($refund->legs ?? [])->contains(function ($leg) {
            if ((string) ($leg->leg_type ?? '') !== 'gateway') {
                return false;
            }

            $approved = round((float) ($leg->approved_amount ?? 0), 2);
            $requested = round((float) ($leg->requested_amount ?? 0), 2);

            return $approved > 0 || $requested > 0;
        });

        if ($hasGatewayLeg) {
            return false;
        }

        $executionProofUrls = is_array($refund->execution_proof_urls) ? $refund->execution_proof_urls : [];
        $hasExecutionProof = !empty(array_filter($executionProofUrls, fn ($value) => trim((string) $value) !== ''));

        return trim((string) ($refund->execution_channel ?? '')) !== ''
            || trim((string) ($refund->execution_reference ?? '')) !== ''
            || $hasExecutionProof;
    }

    private function executeViaGateway(
        PosRefund $refund,
        PosTransaction $source,
        int $actorId,
        float $approvedAmount,
        ?string $executionNote,
        ?float $gatewayAmountOverride = null,
        ?float $finalApprovedAmount = null,
        ?string $finalExecutionMode = null,
    ): PosRefund
    {
        $secretKeyCandidates = $this->resolvePaymongoSecretKeyCandidates((int) ($source->shop_owner_id ?? 0));
        $secretKey = $secretKeyCandidates[0] ?? '';
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

        $targetGatewayAmount = $gatewayAmountOverride !== null
            ? min($approvedAmount, max(0.0, round($gatewayAmountOverride, 2)))
            : ($gatewayLegAmount > 0
                ? min($approvedAmount, $gatewayLegAmount)
                : $approvedAmount);

        if ($targetGatewayAmount <= 0) {
            return $this->markRefundFailed($refund, $actorId, 'Resolved gateway refund amount is invalid.', $executionNote);
        }

        $persistedExecutionMode = in_array((string) $finalExecutionMode, ['manual', 'gateway'], true)
            ? (string) $finalExecutionMode
            : 'gateway';

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
            'execution_mode' => $persistedExecutionMode,
            'execution_notes' => $effectiveExecutionNote ? Str::limit(trim($effectiveExecutionNote), 1000, '') : null,
            'paymongo_payment_id' => $allocations[0]['payment_reference'],
            'paymongo_payment_ids' => collect($allocations)->pluck('payment_reference')->unique()->values()->all(),
            'paymongo_refund_id' => null,
            'paymongo_refund_ids' => null,
            'executed_by' => $actorId > 0 ? $actorId : null,
            'executed_at' => now(),
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
                        'execution_mode' => $persistedExecutionMode,
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
                'execution_mode' => $persistedExecutionMode,
                'execution_notes' => $effectiveExecutionNote ? Str::limit(trim($effectiveExecutionNote), 1000, '') : null,
                'executed_by' => $actorId,
                'executed_at' => now(),
            ]);

            return $refund->fresh();
        }

        $settledAmount = $finalApprovedAmount !== null
            ? max($submittedAmount, round((float) $finalApprovedAmount, 2))
            : $submittedAmount;

        $succeeded = $this->markRefundSucceeded(
            refund: $refund->fresh(),
            source: $source,
            actorId: $actorId,
            approvedAmount: $settledAmount,
            executionMode: $persistedExecutionMode,
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
        ]);

        if ($refund->exists && $refund->getKey()) {
            $refund = $this->recoveryService()->recordSuccessfulExecution($refund, $actorId);
        }

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

            $repairPaidAmount = $this->resolveRepairPaidAmount((int) $repair->id);
            $repairRefundStatus = $totalRefundedForRepair > 0
                ? ($repairPaidAmount > 0 && $totalRefundedForRepair >= $repairPaidAmount ? 'refunded' : 'partially_refunded')
                : (string) ($repair->payment_status_derived ?? $repair->payment_status ?? 'unpaid');

            $repair->update([
                'total_refunded_amount' => $totalRefundedForRepair,
                'payment_status' => $repairRefundStatus,
                'payment_status_derived' => $repairRefundStatus,
            ]);
        }

        if ((string) $refund->workflow_source !== 'delivery_reconciliation') {
            $this->notifyRefundParties(
                refund: $refund,
                source: $source,
                title: 'Repair Refund Executed',
                message: 'Your repair refund has been executed successfully.',
                actionUrl: '/my-repairs',
                includeOwner: true,
            );
        }

        return $refund->fresh();
    }

    private function markRefundFailed(PosRefund $refund, int $actorId, string $reason, ?string $executionNote): PosRefund
    {
        $refund = $this->recoveryService()->recordFailure($refund, $actorId, $reason, $executionNote);

        $source = $refund->sourceTransaction()->first();
        if ($source) {
            $this->notifyRefundParties(
                refund: $refund,
                source: $source,
                title: 'Repair Refund Execution Failed',
                message: 'Repair refund execution failed and requires manual review.',
                actionUrl: '/my-repairs',
                includeOwner: true,
                includeCustomer: false,
            );
        }

        return $refund->fresh();
    }

    private function recoveryService(): RepairRefundRecoveryService
    {
        return $this->repairRefundRecoveryService ?? app(RepairRefundRecoveryService::class);
    }

    private function notifyRefundParties(
        PosRefund $refund,
        PosTransaction $source,
        string $title,
        string $message,
        string $actionUrl,
        bool $includeOwner = true,
        bool $includeCustomer = true,
        ?string $ownerTitle = null,
        ?string $ownerMessage = null,
        ?string $ownerActionUrl = null,
    ): void {
        $customerId = (int) ($source->customer_id ?? 0);

        // Some legacy or backfilled POS rows may miss customer_id even when
        // the repair request is customer-owned; fall back to repair owner.
        if ($customerId <= 0) {
            $refund->loadMissing('repairRequest:id,user_id');
            $customerId = (int) ($refund->repairRequest?->user_id ?? 0);
        }

        if ($includeCustomer && $customerId > 0) {
            $this->notificationService->sendToUser(
                userId: $customerId,
                type: NotificationType::MESSAGE_RECEIVED,
                title: $title,
                message: $message,
                data: [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'status' => (string) $refund->status,
                    'approved_amount' => (float) ($refund->approved_amount ?? 0),
                ],
                actionUrl: $actionUrl,
                shopId: (int) $refund->shop_owner_id,
                priority: 'high',
            );
        }

        if ($includeOwner) {
            $resolvedOwnerTitle = trim((string) $ownerTitle) !== '' ? trim((string) $ownerTitle) : $title;
            $resolvedOwnerMessage = trim((string) $ownerMessage) !== '' ? trim((string) $ownerMessage) : $message;
            $resolvedOwnerActionUrl = trim((string) $ownerActionUrl) !== ''
                ? trim((string) $ownerActionUrl)
                : $this->notificationService->ownerApprovalActionUrl('repair_refund', $refund->id);

            Notification::create([
                'shop_owner_id' => (int) $refund->shop_owner_id,
                'type' => NotificationType::REFUND_REQUEST->value,
                'priority' => 'high',
                'title' => $resolvedOwnerTitle,
                'message' => $resolvedOwnerMessage,
                'data' => [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'status' => (string) $refund->status,
                    'approved_amount' => (float) ($refund->approved_amount ?? 0),
                ],
                'action_url' => $resolvedOwnerActionUrl,
                'shop_id' => (int) $refund->shop_owner_id,
            ]);
        }
    }

    private function notifyRefundRequested(PosRefund $refund, PosTransaction $source, float $requestedAmount): void
    {
        try {
            $source->loadMissing('receipt');

            $orderNumber = (string) ($source->receipt?->receipt_no ?? $source->transaction_no ?? $refund->refund_no);
            $requiresOwnerApproval = (bool) ($refund->requires_owner_approval ?? true);
            $workflowSource = strtolower((string) ($refund->workflow_source ?? 'pos'));

            if (!$requiresOwnerApproval && $workflowSource === 'online_myrepair') {
                return;
            }

            if (!$requiresOwnerApproval) {
                $this->notificationService->sendToErpRole(
                    roleName: 'Finance',
                    shopId: (int) $refund->shop_owner_id,
                    type: NotificationType::REFUND_REQUEST,
                    title: 'Repair Refund Ready For Finance Review',
                    message: "Repair refund {$refund->refund_no} is ready for finance review.",
                    data: [
                        'refund_id' => (int) $refund->id,
                        'refund_no' => (string) $refund->refund_no,
                        'order_number' => $orderNumber,
                        'amount' => number_format($requestedAmount, 2, '.', ''),
                        'workflow_source' => $workflowSource,
                        'status' => (string) ($refund->status ?? 'requested'),
                    ],
                    actionUrl: '/finance?section=refund-approvals',
                    priority: 'high',
                );

                return;
            }

            $notification = $this->notificationService->notifyRefundRequest((int) $refund->shop_owner_id, [
                'refund_id' => (int) $refund->id,
                'refund_no' => (string) $refund->refund_no,
                'order_number' => $orderNumber,
                'amount' => number_format($requestedAmount, 2, '.', ''),
                'workflow_source' => $workflowSource,
                'status' => (string) ($refund->status ?? 'requested'),
                'source_type' => 'repair_refund',
            ]);

            // Governance notifications must still be visible even if preference resolution returns null.
            if (!$notification && (int) $refund->shop_owner_id > 0) {
                Notification::create([
                    'shop_owner_id' => (int) $refund->shop_owner_id,
                    'type' => NotificationType::REFUND_REQUEST->value,
                    'priority' => 'high',
                    'title' => 'Repair Refund Approval Required',
                    'message' => "Repair refund request {$refund->refund_no} requires approval.",
                    'data' => [
                        'refund_id' => (int) $refund->id,
                        'refund_no' => (string) $refund->refund_no,
                        'order_number' => $orderNumber,
                        'amount' => number_format($requestedAmount, 2, '.', ''),
                        'workflow_source' => (string) ($refund->workflow_source ?? 'pos'),
                        'status' => (string) ($refund->status ?? 'requested'),
                        'source_type' => 'repair_refund',
                    ],
                    'action_url' => $this->notificationService->ownerApprovalActionUrl('repair_refund', $refund->id),
                    'shop_id' => (int) $refund->shop_owner_id,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to dispatch repair refund request notification.', [
                'refund_id' => (int) $refund->id,
                'shop_owner_id' => (int) $refund->shop_owner_id,
                'error' => $exception->getMessage(),
            ]);
        }
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

        return in_array($reasonCode, [
            'customer_cancelled_repair',
            'pickup_attempts_exhausted',
        ], true);
    }

    private function isIndividualShopOwner(int $shopOwnerId): bool
    {
        if ($shopOwnerId <= 0) {
            return false;
        }

        $registrationType = strtolower(trim((string) (ShopOwner::query()->whereKey($shopOwnerId)->value('registration_type') ?? '')));

        if ($registrationType === 'individual') {
            return true;
        }

        if ($registrationType === '' || $registrationType === 'company') {
            return false;
        }

        return str_contains($registrationType, 'individual') || str_contains($registrationType, 'sole');
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
            ->filter(fn ($value) => $this->looksLikeGatewayRefundReference($value))
            ->unique()
            ->values()
            ->all();
    }

    private function looksLikeGatewayRefundReference(?string $reference): bool
    {
        $value = strtolower(trim((string) ($reference ?? '')));
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 're_') || str_starts_with($value, 'rfnd_') || str_starts_with($value, 'ref_')) {
            return true;
        }

        return preg_match('/^[a-z0-9][a-z0-9_-]{5,}$/i', $value) === 1;
    }

    private function isGatewayRefundSuccessStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['succeeded', 'completed', 'paid', 'refunded'], true);
    }

    private function isGatewayRefundFailureStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['failed', 'canceled', 'cancelled'], true);
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

    private function resolvePaymongoSecretKeyCandidates(int $shopOwnerId): array
    {
        $shopKey = trim((string) (ShopOwner::query()->whereKey($shopOwnerId)->value('paymongo_secret_key') ?? ''));
        $globalKey = trim((string) (config('services.paymongo.secret_key') ?? ''));

        return collect([$shopKey, $globalKey])
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function fetchRefundStatusUsingAnySecret(array $secretKeys, string $refundId): array
    {
        $lastFailure = [
            'success' => false,
            'message' => 'No PayMongo secret key available for refund status lookup.',
            'status' => null,
            'refund_id' => $refundId,
            'raw' => null,
        ];

        foreach ($secretKeys as $secretKey) {
            $result = app(PaymongoRefundService::class)->getRefundStatus((string) $secretKey, $refundId);
            if ($result['success'] ?? false) {
                return $result;
            }

            $lastFailure = $result;
        }

        return $lastFailure;
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
