<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Finance\Invoice;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentSettlementService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RepairDeliveryService $repairDeliveryService,
        private readonly RepairPosRefundService $repairRefundService,
    ) {}

    public function repairPaymentBreakdown(RepairRequest $repair, ?string $dueType = null): array
    {
        if (data_get($repair->logistics_payment_reconciliation, 'status') === 'pending') {
            throw ValidationException::withMessages([
                'payment' => ['This repair has a payment reconciliation that must be resolved before another payment can be collected.'],
            ]);
        }

        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy_snapshot ?: $repair->payment_policy);
        $phase = $this->resolveRepairPaymentPhase($repair, $policy, $dueType);
        $serviceTotal = $this->resolveRepairServiceTotal($repair, $policy, $phase);
        $serviceDeposit = $policy === 'deposit_50' ? round($serviceTotal * 0.5, 2) : $serviceTotal;
        $serviceAmount = in_array($phase, ['redelivery', 'pickup_retry'], true)
            ? 0.0
            : ($phase === 'initial'
            ? $serviceDeposit
            : ($policy === 'deposit_50'
                ? round(max(0, $serviceTotal - $this->resolveRepairInitialServiceAmount($repair)), 2)
                : 0.0));
        $leg = in_array($phase, ['initial', 'pickup_retry'], true) ? 'intake' : 'return';
        if ($this->hasResolvedDeliveryCompensation($repair, $leg)) {
            $serviceAmount = 0.0;
        }
        $delivery = $this->repairDeliveryService->paymentDetails($repair, $leg);
        $deliveryAmount = round((float) $delivery['delivery_amount'], 2);
        $shopSponsoredWarranty = (bool) ($repair->is_warranty_job ?? false)
            || strtolower((string) ($repair->billing_mode ?? '')) === 'warranty_no_charge';
        if ($shopSponsoredWarranty) {
            $serviceTotal = $serviceAmount = 0.0;
        }
        $redelivery = $phase === 'redelivery'
            ? $this->repairDeliveryService->activeRedeliveryRequirement($repair)
            : null;
        $pickupRetry = $phase === 'pickup_retry'
            ? $this->repairDeliveryService->activePickupRecovery($repair, 'awaiting_payment')
            : null;

        return [
            'policy' => $policy,
            'phase' => $phase,
            'due_type' => match ($phase) {
                'initial' => $policy === 'deposit_50' ? 'deposit' : 'full',
                'redelivery' => 'redelivery',
                'pickup_retry' => 'pickup_retry',
                default => 'balance',
            },
            'leg' => $leg,
            'service_total' => $serviceTotal,
            'service_amount' => $serviceAmount,
            'delivery_amount' => $deliveryAmount,
            'total_amount' => round($serviceAmount + $deliveryAmount, 2),
            'snapshot_version' => $delivery['snapshot_version'],
            'delivery_method' => $delivery['method'],
            'quote' => $delivery['quote'],
            'recovery_key' => $redelivery['recovery_key'] ?? $pickupRetry['recovery_key'] ?? null,
            'plan_key' => $pickupRetry['plan_key'] ?? null,
        ];
    }

    public function repairCollectionSummary(RepairRequest $repair): array
    {
        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy_snapshot ?: $repair->payment_policy);
        $grandTotal = $this->resolveRepairServiceTotal($repair, $policy, 'initial');
        $totalPaidAmount = $this->resolveRepairTotalPaidAmount($repair, $grandTotal, $policy);
        $status = strtolower((string) ($repair->status ?? ''));
        $paymentStatus = strtolower((string) ($repair->payment_status ?? ''));
        $nonCollectible = data_get($repair->logistics_payment_reconciliation, 'status') === 'pending'
            || in_array($status, ['cancelled', 'rejected', 'repairer_rejected', 'owner_rejected'], true)
            || in_array($paymentStatus, ['refunded'], true);
        $fullyPaid = ! $nonCollectible && $this->isRepairSettled($repair, $policy);
        $serviceBalance = $nonCollectible ? 0.0 : $this->outstandingRepairServiceBalance($repair);

        $summary = [
            'collectible' => false,
            'due_type' => null,
            'phase' => null,
            'collectible_amount' => 0.0,
            'outstanding_balance' => 0.0,
            'service_amount' => 0.0,
            'delivery_amount' => 0.0,
            'total_paid_amount' => $totalPaidAmount,
            'grand_total' => $grandTotal,
            'fully_paid' => $fullyPaid,
        ];

        if ($nonCollectible || $fullyPaid) {
            return $summary;
        }

        $summary['outstanding_balance'] = round($serviceBalance, 2);
        if (! $this->isRepairPaymentDueNow($repair, $policy)) {
            return $summary;
        }

        try {
            $breakdown = $this->repairPaymentBreakdown($repair);
        } catch (ValidationException) {
            return $summary;
        }

        $collectibleAmount = round((float) ($breakdown['total_amount'] ?? 0), 2);
        $deliveryAmount = round((float) ($breakdown['delivery_amount'] ?? 0), 2);

        return [
            ...$summary,
            'collectible' => $collectibleAmount > 0,
            'due_type' => $breakdown['due_type'] ?? null,
            'phase' => $breakdown['phase'] ?? null,
            'collectible_amount' => $collectibleAmount,
            'outstanding_balance' => round($serviceBalance + $deliveryAmount, 2),
            'service_amount' => round((float) ($breakdown['service_amount'] ?? 0), 2),
            'delivery_amount' => $deliveryAmount,
        ];
    }

    public function settleRepairPhasePaid(RepairRequest $repair, array $breakdown, ?string $paymentReference = null): RepairRequest
    {
        $phase = (string) $breakdown['phase'];
        $policy = (string) $breakdown['policy'];
        $phaseAmount = round((float) $breakdown['total_amount'], 2);
        $totalPaidAmount = round((float) ($repair->total_paid_amount ?? 0) + $phaseAmount, 2);
        if ($phase === 'pickup_retry') {
            $recoveryKey = trim((string) ($breakdown['recovery_key'] ?? ''));
            $planKey = trim((string) ($breakdown['plan_key'] ?? ''));
            if ($recoveryKey === '' || $planKey === '') {
                throw ValidationException::withMessages([
                    'payment' => ['The pickup request is missing its recovery reference.'],
                ]);
            }
            $paymentReferences = $this->appendRepairPaymentReference(
                is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : null,
                $paymentReference,
            );
            $shipment = Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $repair->id)
                ->where('purpose', 'repair_pickup')
                ->first();
            $lockAt = now();
            if ($shipment?->cancelled_at && $lockAt->timestamp <= $shipment->cancelled_at->timestamp) {
                $lockAt = $shipment->cancelled_at->copy()->addSecond();
            }
            $updates = [
                'payment_status' => in_array((string) $repair->payment_status, ['paid', 'completed'], true)
                    ? $repair->payment_status
                    : 'paid',
                'payment_status_derived' => in_array((string) $repair->payment_status_derived, ['paid', 'completed'], true)
                    ? $repair->payment_status_derived
                    : 'paid',
                'total_paid_amount' => $totalPaidAmount,
                'payment_completed_at' => now(),
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
                'intake_logistics_locked_at' => $lockAt,
            ];
            if ($paymentReference !== null && trim($paymentReference) !== '') {
                $updates['paymongo_payment_id'] = $paymentReference;
                $updates['paymongo_payment_ids'] = $paymentReferences ?: null;
            }
            $repair->update($updates);
            $settledRepair = $this->repairDeliveryService->markPickupRecoveryPaid(
                $repair->fresh(),
                $recoveryKey,
                $planKey,
            );
            $this->repairDeliveryService->tryCreateIntakeShipment($settledRepair);

            return $settledRepair->fresh();
        }
        if ($phase === 'redelivery') {
            $recoveryKey = trim((string) ($breakdown['recovery_key'] ?? ''));
            if ($recoveryKey === '') {
                throw ValidationException::withMessages([
                    'payment' => ['The re-delivery request is missing its recovery reference.'],
                ]);
            }
            $paymentReferences = $this->appendRepairPaymentReference(
                is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : null,
                $paymentReference,
            );
            $updates = [
                'payment_status' => 'completed',
                'payment_status_derived' => 'completed',
                'total_paid_amount' => $totalPaidAmount,
                'payment_completed_at' => now(),
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
                'return_logistics_locked_at' => now()->addSecond(),
            ];
            if ($paymentReference !== null && trim($paymentReference) !== '') {
                $updates['paymongo_payment_id'] = $paymentReference;
                $updates['paymongo_payment_ids'] = $paymentReferences ?: null;
            }
            $repair->update($updates);
            $settledRepair = $this->repairDeliveryService->markRedeliveryPaid($repair->fresh(), $recoveryKey);
            $this->repairDeliveryService->tryCreateReturnShipment($settledRepair);
            $settledRepair = $settledRepair->fresh();
            $this->notificationService->notifyRepairReturnRecovery(
                $settledRepair,
                'ready_for_dispatch',
                $recoveryKey,
            );

            return $settledRepair;
        }

        $finalDue = $policy === 'deposit_50'
            ? round((float) $breakdown['service_total'] - round((float) $breakdown['service_total'] * 0.5, 2) + (float) $repair->return_delivery_fee, 2)
            : round((float) $repair->return_delivery_fee, 2);
        $completed = $phase === 'final' || ($phase === 'initial' && $finalDue <= 0);
        $advanceAcceptedRepair = $phase === 'initial' && (string) $repair->status === 'repairer_accepted';
        $paymentReferences = $this->appendRepairPaymentReference(
            is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : null,
            $paymentReference,
        );

        $updates = [
            'payment_status' => $completed ? 'completed' : 'paid',
            'payment_status_derived' => $completed ? 'completed' : 'paid',
            'total_paid_amount' => $totalPaidAmount,
            'payment_completed_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
            $phase === 'initial' ? 'intake_logistics_locked_at' : 'return_logistics_locked_at' => now(),
        ];

        if ($paymentReference !== null && trim($paymentReference) !== '') {
            $updates['paymongo_payment_id'] = $paymentReference;
            $updates['paymongo_payment_ids'] = $paymentReferences ?: null;
        }

        $repair->update($updates);
        $settledRepair = $repair->fresh();

        if ($phase === 'initial') {
            $this->repairDeliveryService->tryCreateIntakeShipment($settledRepair);
            if ($advanceAcceptedRepair) {
                $settledRepair->update(['status' => 'pending']);
            }
        } else {
            $this->repairDeliveryService->tryCreateReturnShipment($settledRepair);
        }

        return $settledRepair->fresh();
    }

    public function settleOrderPaid(Order $order, ?string $paymentId = null, bool $ignoreExpiry = false): array
    {
        if ($this->isOrderSettled($order)) {
            return [
                'result' => 'already_settled',
                'model' => $order,
            ];
        }

        if (! $ignoreExpiry && $this->isOrderExpired($order)) {
            return [
                'result' => 'expired',
                'model' => $order,
            ];
        }

        $order->update([
            'payment_status' => 'paid',
            'paymongo_payment_id' => $paymentId,
            'paid_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
        ]);

        if ($order->invoice_id) {
            $invoice = Invoice::find($order->invoice_id);
            if ($invoice && $invoice->status !== 'paid') {
                $invoice->update([
                    'status' => 'paid',
                    'payment_date' => now(),
                    'payment_method' => 'paymongo',
                ]);
            }
        }

        return [
            'result' => 'settled',
            'model' => $order->fresh(),
        ];
    }

    public function settleRepairPaid(
        RepairRequest $repair,
        ?string $paymentId = null,
        bool $ignoreExpiry = false,
        ?RepairPaymentSession $session = null,
    ): array {
        if (! $session && $repair->paymongo_link_id) {
            $session = RepairPaymentSession::query()
                ->where('repair_request_id', $repair->id)
                ->where('provider_link_id', $repair->paymongo_link_id)
                ->first();
        }

        if ($session) {
            return $this->settleRepairPaymentSession($repair, $session, $paymentId);
        }

        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');
        $resolvedPaymentId = trim((string) ($paymentId ?? ''));
        $grandTotal = round((float) ($repair->final_total ?? $repair->total ?? 0), 2);
        $existingPaidAmount = round((float) ($repair->total_paid_amount ?? 0), 2);
        $currentPaymentStatus = (string) ($repair->payment_status ?? 'pending');
        $isDepositPhase = in_array($currentPaymentStatus, ['pending', 'failed', 'expired', '', null], true);

        if ($this->isRepairSettled($repair, $policy)) {
            return [
                'result' => 'already_settled',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        if (! $this->isRepairPaymentDueNow($repair, $policy)) {
            return [
                'result' => 'not_due',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        if (! $ignoreExpiry && $this->isRepairExpired($repair, $policy)) {
            return [
                'result' => 'expired',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        $paymentReferenceHistory = $this->appendRepairPaymentReference(
            current: is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : null,
            paymentReference: $resolvedPaymentId,
        );

        $repair->update([
            'paymongo_payment_id' => $resolvedPaymentId !== '' ? $resolvedPaymentId : $repair->paymongo_payment_id,
            'paymongo_payment_ids' => ! empty($paymentReferenceHistory) ? $paymentReferenceHistory : null,
            'payment_completed_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
        ]);

        if ($policy === 'full_upfront') {
            $totalPaidAmount = round(max($existingPaidAmount, $grandTotal), 2);

            $repair->update([
                'payment_status' => 'completed',
                'status' => 'pending',  // Always proceed to pending after payment
                'total_paid_amount' => $totalPaidAmount,
                'payment_status_derived' => 'completed',
                'intake_logistics_locked_at' => now(),
            ]);
            $this->repairDeliveryService->tryCreateIntakeShipment($repair->fresh());

            return [
                'result' => 'settled',
                'model' => $repair->fresh(),
                'policy' => $policy,
                'phase' => 'full_upfront',
            ];
        }

        $nextPaymentStatus = $isDepositPhase ? 'paid' : 'completed';
        $phasePaidAmount = $isDepositPhase
            ? round($grandTotal * 0.5, 2)
            : $grandTotal;
        $totalPaidAmount = round(max($existingPaidAmount, $phasePaidAmount), 2);

        $repair->update([
            'payment_status' => $nextPaymentStatus,
            'total_paid_amount' => $totalPaidAmount,
            'payment_status_derived' => $nextPaymentStatus,
        ]);

        if ($isDepositPhase) {
            // After deposit payment, always proceed to pending status
            // High-value approval is for rejection decisions only, not payment workflow
            $repair->update([
                'status' => 'pending',
                'intake_logistics_locked_at' => now(),
            ]);
            $this->repairDeliveryService->tryCreateIntakeShipment($repair->fresh());
        }

        return [
            'result' => 'settled',
            'model' => $repair->fresh(),
            'policy' => $policy,
            'phase' => $isDepositPhase ? 'deposit_50' : 'remaining_balance',
        ];
    }

    public function settleRepairPaidInShop(RepairRequest $repair, ?string $reference = null): array
    {
        $paymentReference = $reference ?? ('in_shop_'.now()->format('YmdHis'));

        return $this->settleRepairPaid($repair, $paymentReference, true);
    }

    public function syncRepairDerivedPaymentStatusFromPos(RepairRequest $repair): RepairRequest
    {
        $total = (float) ($repair->final_total ?? $repair->total ?? 0);
        $paid = (float) ($repair->total_paid_amount ?? 0);
        $refunded = (float) ($repair->total_refunded_amount ?? 0);

        $derived = 'unpaid';
        if ($refunded > 0) {
            $derived = $refunded >= max($paid, 0.0) ? 'refunded' : 'partially_refunded';
        } elseif ($paid > 0) {
            $derived = $paid >= $total ? 'paid' : 'partially_paid';
        }

        $repair->update([
            'payment_status_derived' => $derived,
        ]);

        return $repair->fresh();
    }

    public function canCreditDeliveryCompensation(RepairRequest $repair, float $amount): bool
    {
        return $amount > 0 && $this->outstandingRepairServiceBalance($repair) >= round($amount, 2);
    }

    public function resolveRepairDeliveryReconciliation(
        RepairRequest $repair,
        string $compensationKey,
        string $action,
        int $actorId,
    ): array {
        $prepared = DB::transaction(function () use ($repair, $compensationKey, $action, $actorId): array {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $reconciliation = is_array($locked->logistics_payment_reconciliation)
                ? $locked->logistics_payment_reconciliation
                : [];
            $entries = collect(data_get($reconciliation, 'entries', []))
                ->filter(fn ($entry): bool => is_array($entry))
                ->values();
            $index = $entries->search(
                fn (array $entry): bool => (string) ($entry['compensation_key'] ?? '') === $compensationKey
                    && (string) ($entry['type'] ?? '') === 'delivery_compensation'
            );
            if ($index === false) {
                throw ValidationException::withMessages([
                    'compensation_key' => ['The delivery compensation item was not found.'],
                ]);
            }

            $entry = $entries->get($index);
            if ((string) ($entry['status'] ?? data_get($reconciliation, 'status')) === 'resolved') {
                return ['repair' => $locked, 'entry' => $entry, 'already_resolved' => true];
            }
            if ((string) ($entry['status'] ?? 'pending') === 'processing') {
                return [
                    'repair' => $locked,
                    'entry' => $entry,
                    'already_resolved' => false,
                    'processing' => true,
                ];
            }

            $amount = round((float) ($entry['reconciliation_amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['The delivery compensation amount is invalid.']]);
            }
            if ($action === 'credit_balance' && ! $this->canCreditDeliveryCompensation($locked, $amount)) {
                throw ValidationException::withMessages([
                    'action' => ['The exact delivery fee cannot be credited because the remaining service balance is lower. Refund the original channel instead.'],
                ]);
            }

            $entry = [
                ...$entry,
                'status' => 'processing',
                'resolution_action' => $action,
                'resolution_started_at' => now()->toISOString(),
                'resolved_by' => $actorId,
            ];
            $entries->put($index, $entry);
            $locked->update([
                'logistics_payment_reconciliation' => [
                    ...$reconciliation,
                    'status' => 'pending',
                    'entries' => $entries->all(),
                ],
            ]);

            return [
                'repair' => $locked->fresh(),
                'entry' => $entry,
                'already_resolved' => false,
                'processing' => false,
            ];
        }, 5);

        if ($prepared['already_resolved']) {
            return [
                'status' => 'resolved',
                'repair' => $prepared['repair']->fresh(),
                'entry' => $prepared['entry'],
            ];
        }

        $entry = $prepared['entry'];
        $refund = null;
        if ($action === 'refund_original') {
            $existingRefundId = (int) ($entry['pos_refund_id'] ?? 0);
            $existingRefund = $existingRefundId > 0 ? PosRefund::query()->find($existingRefundId) : null;

            try {
                $refund = $this->repairRefundService->executeDeliveryCompensation(
                    $prepared['repair']->fresh(),
                    (float) $entry['reconciliation_amount'],
                    $actorId,
                    $existingRefund,
                );
            } catch (\Throwable $exception) {
                $this->markDeliveryReconciliationRetryable(
                    $prepared['repair'],
                    $compensationKey,
                    $exception->getMessage(),
                );
                throw $exception;
            }

            if (in_array((string) $refund->status, ['failed', 'rejected', 'cancelled'], true)) {
                $message = (string) ($refund->failure_reason ?: 'The original-channel refund failed and can be retried.');
                $this->markDeliveryReconciliationRetryable(
                    $prepared['repair'],
                    $compensationKey,
                    $message,
                );
                throw ValidationException::withMessages(['action' => [$message]]);
            }

            if ((string) $refund->status !== 'succeeded') {
                $this->storeDeliveryReconciliationRefund(
                    $prepared['repair'],
                    $compensationKey,
                    $refund,
                );

                return [
                    'status' => 'processing',
                    'repair' => $prepared['repair']->fresh(),
                    'entry' => [
                        ...$entry,
                        'pos_refund_id' => (int) $refund->id,
                    ],
                ];
            }
        }

        $resolved = $this->finalizeDeliveryReconciliation(
            $prepared['repair'],
            $compensationKey,
            $action,
            $actorId,
            $refund,
        );
        $this->notificationService->notifyRepairDeliveryReconciliation(
            $resolved['repair'],
            (string) $resolved['entry']['phase'],
            'resolved',
            (float) $resolved['entry']['reconciliation_amount'],
            $compensationKey,
        );

        return [
            'status' => 'resolved',
            'repair' => $resolved['repair'],
            'entry' => $resolved['entry'],
        ];
    }

    private function finalizeDeliveryReconciliation(
        RepairRequest $repair,
        string $compensationKey,
        string $action,
        int $actorId,
        ?PosRefund $refund,
    ): array {
        return DB::transaction(function () use ($repair, $compensationKey, $action, $actorId, $refund): array {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $reconciliation = is_array($locked->logistics_payment_reconciliation)
                ? $locked->logistics_payment_reconciliation
                : [];
            $entries = collect(data_get($reconciliation, 'entries', []))
                ->filter(fn ($entry): bool => is_array($entry))
                ->values();
            $index = $entries->search(
                fn (array $entry): bool => (string) ($entry['compensation_key'] ?? '') === $compensationKey
            );
            if ($index === false) {
                throw ValidationException::withMessages([
                    'compensation_key' => ['The delivery compensation item was not found.'],
                ]);
            }
            $entry = $entries->get($index);
            if ((string) ($entry['status'] ?? '') === 'resolved') {
                return ['repair' => $locked, 'entry' => $entry];
            }
            if ($action === 'refund_original' && (string) ($refund?->status ?? '') !== 'succeeded') {
                throw ValidationException::withMessages([
                    'action' => ['The original-channel refund has not completed. The delivery plan remains locked.'],
                ]);
            }

            $amount = round((float) ($entry['reconciliation_amount'] ?? 0), 2);
            $phase = (string) $entry['phase'];
            $entry = [
                ...$entry,
                'status' => 'resolved',
                'resolution_action' => $action,
                'resolved_by' => $actorId,
                'resolved_at' => now()->toISOString(),
                'credited_amount' => $action === 'credit_balance' ? $amount : 0,
                'refunded_amount' => $action === 'refund_original' ? $amount : 0,
                'pos_refund_id' => $refund?->id,
            ];
            $entries->put($index, $entry);
            $hasPending = $entries->contains(
                fn (array $item): bool => in_array((string) ($item['status'] ?? 'pending'), ['pending', 'processing'], true)
            );
            $updates = [
                'logistics_payment_reconciliation' => [
                    ...$reconciliation,
                    ...$entry,
                    'status' => $hasPending ? 'pending' : 'resolved',
                    'entries' => $entries->all(),
                    'total_reconciliation_amount' => round((float) $entries
                        ->filter(fn (array $item): bool => in_array(
                            (string) ($item['status'] ?? 'pending'),
                            ['pending', 'processing'],
                            true,
                        ))
                        ->sum(fn (array $item): float => (float) ($item['reconciliation_amount'] ?? 0)), 2),
                    'resolved_at' => $hasPending ? null : now()->toISOString(),
                ],
                "{$phase}_delivery_fee" => 0,
                "{$phase}_logistics_quote" => null,
                "{$phase}_logistics_locked_at" => null,
            ];
            if ($phase === 'return') {
                $updates += [
                    'return_address_confirmed_at' => null,
                    'return_address_confirmed_version' => null,
                    'pickup_enabled' => false,
                    'pickup_enabled_at' => null,
                    'pickup_enabled_by' => null,
                ];
            }
            if ($action === 'refund_original') {
                $updates['total_paid_amount'] = max(0, round((float) $locked->total_paid_amount - $amount, 2));
                $updates['payment_status'] = 'paid';
            }

            $locked->update($updates);

            return ['repair' => $locked->fresh(), 'entry' => $entry];
        }, 5);
    }

    private function markDeliveryReconciliationRetryable(
        RepairRequest $repair,
        string $compensationKey,
        string $message,
    ): void {
        DB::transaction(function () use ($repair, $compensationKey, $message): void {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $reconciliation = is_array($locked->logistics_payment_reconciliation)
                ? $locked->logistics_payment_reconciliation
                : [];
            $entries = collect(data_get($reconciliation, 'entries', []))
                ->map(fn ($entry): array => is_array($entry)
                    && (string) ($entry['compensation_key'] ?? '') === $compensationKey
                        ? [
                            ...$entry,
                            'status' => 'pending',
                            'last_error' => $message,
                            'last_failed_at' => now()->toISOString(),
                        ]
                        : $entry)
                ->all();
            $locked->update([
                'logistics_payment_reconciliation' => [
                    ...$reconciliation,
                    'status' => 'pending',
                    'entries' => $entries,
                ],
            ]);
        });
    }

    private function storeDeliveryReconciliationRefund(
        RepairRequest $repair,
        string $compensationKey,
        PosRefund $refund,
    ): void {
        DB::transaction(function () use ($repair, $compensationKey, $refund): void {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $reconciliation = is_array($locked->logistics_payment_reconciliation)
                ? $locked->logistics_payment_reconciliation
                : [];
            $entries = collect(data_get($reconciliation, 'entries', []))
                ->map(fn ($entry): array => is_array($entry)
                    && (string) ($entry['compensation_key'] ?? '') === $compensationKey
                        ? [
                            ...$entry,
                            'status' => 'processing',
                            'pos_refund_id' => (int) $refund->id,
                            'refund_status' => (string) $refund->status,
                        ]
                        : $entry)
                ->all();
            $locked->update([
                'logistics_payment_reconciliation' => [
                    ...$reconciliation,
                    'status' => 'pending',
                    'entries' => $entries,
                ],
            ]);
        });
    }

    private function outstandingRepairServiceBalance(RepairRequest $repair): float
    {
        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy_snapshot ?: $repair->payment_policy);
        $serviceTotal = $this->resolveRepairServiceTotal($repair, $policy, 'initial');
        $posServicePaid = (float) PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->get()
            ->sum(fn (PosTransaction $transaction): float => (float) data_get($transaction->metadata, 'service_amount', 0));
        $sessionServicePaid = (float) RepairPaymentSession::query()
            ->where('repair_request_id', $repair->id)
            ->whereIn('status', ['paid', 'reconciliation'])
            ->get()
            ->sum(function (RepairPaymentSession $session) use ($repair): float {
                if ((string) $session->status === 'paid') {
                    return (float) $session->service_amount;
                }

                $entry = collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
                    ->firstWhere('payment_session_id', $session->id);

                return (float) data_get($entry, 'service_amount_applied', 0);
            });
        $credits = (float) collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry)
                && (string) ($entry['status'] ?? '') === 'resolved'
                && (string) ($entry['resolution_action'] ?? '') === 'credit_balance')
            ->sum(fn (array $entry): float => (float) ($entry['credited_amount'] ?? 0));
        $fallbackDelivery = ($repair->intake_logistics_locked_at ? (float) $repair->intake_delivery_fee : 0)
            + ($repair->return_logistics_locked_at ? (float) $repair->return_delivery_fee : 0);
        $fallbackServicePaid = max(0, (float) $repair->total_paid_amount - $fallbackDelivery);
        $servicePaid = max($fallbackServicePaid, $posServicePaid + $sessionServicePaid + $credits);

        return max(0, round($serviceTotal - $servicePaid, 2));
    }

    private function resolveRepairTotalPaidAmount(RepairRequest $repair, float $grandTotal, string $policy): float
    {
        $storedPaidAmount = round((float) ($repair->total_paid_amount ?? 0), 2);
        $posLedgerPaidAmount = array_key_exists('pos_paid_amount', $repair->getAttributes())
            ? round((float) ($repair->pos_paid_amount ?? 0), 2)
            : (float) PosTransaction::query()
                ->where('module_type', 'repair')
                ->where('module_reference_id', $repair->id)
                ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                ->sum('paid_amount');
        $resolved = max(0.0, $storedPaidAmount, $posLedgerPaidAmount);
        $paymentStatus = strtolower(trim((string) ($repair->payment_status ?? 'pending')));

        if ($paymentStatus === 'completed') {
            return round(max($resolved, $grandTotal), 2);
        }

        if (in_array($paymentStatus, ['paid', 'partially_paid'], true)) {
            $phaseAmount = $policy === 'full_upfront'
                ? $grandTotal
                : round($grandTotal * 0.5, 2);

            return round(max($resolved, $phaseAmount), 2);
        }

        return round($resolved, 2);
    }

    private function hasResolvedDeliveryCompensation(RepairRequest $repair, string $phase): bool
    {
        $reconciliation = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];

        return collect(data_get($reconciliation, 'entries', []))
            ->contains(fn ($entry): bool => is_array($entry)
                && (string) ($entry['type'] ?? '') === 'delivery_compensation'
                && (string) ($entry['phase'] ?? '') === $phase
                && (string) ($entry['status'] ?? data_get($reconciliation, 'status')) === 'resolved');
    }

    private function needsReplacementDeliveryPayment(RepairRequest $repair): bool
    {
        return ($this->hasResolvedDeliveryCompensation($repair, 'intake')
                && (string) $repair->intake_delivery_method === 'shop_pickup'
                && $repair->intake_logistics_locked_at === null
                && (float) $repair->intake_delivery_fee > 0)
            || ($this->hasResolvedDeliveryCompensation($repair, 'return')
                && (string) $repair->return_delivery_method === 'shop_delivery'
                && $repair->return_logistics_locked_at === null
                && (float) $repair->return_delivery_fee > 0);
    }

    public function recordOrderPaymentFailure(Order $order, string $reason): array
    {
        if ($this->isOrderSettled($order)) {
            return [
                'result' => 'already_settled',
                'model' => $order,
            ];
        }

        $payload = [
            'payment_failed_at' => now(),
            'payment_failure_reason' => $reason,
        ];

        if ($reason === 'paymongo_session_expired') {
            $payload['payment_expired_at'] = now();
        }

        $order->update($payload);

        return [
            'result' => 'recorded',
            'model' => $order->fresh(),
        ];
    }

    public function settleOrderRefunded(Order $order, ?string $refundId = null, ?string $reason = null, ?string $note = null): array
    {
        if ((string) ($order->payment_status ?? 'pending') === 'refunded') {
            if ($order->invoice_id) {
                $invoice = Invoice::find($order->invoice_id);
                if ($invoice && (string) $invoice->status !== 'cancelled') {
                    $invoice->update([
                        'status' => 'cancelled',
                        'payment_method' => $invoice->payment_method ?? 'paymongo_refund',
                    ]);
                }
            }

            return [
                'result' => 'already_refunded',
                'model' => $order,
            ];
        }

        $payload = [
            'payment_status' => 'refunded',
            'refunded_at' => now(),
            'payment_released_at' => now(),
            'payment_failed_at' => null,
            'payment_failure_reason' => null,
            'payment_expired_at' => null,
        ];

        if ($refundId) {
            $payload['paymongo_refund_id'] = $refundId;
        }

        if ($reason !== null) {
            $payload['refund_reason'] = $reason;
        }

        if ($note !== null) {
            $payload['refund_note'] = $note;
        }

        $order->update($payload);

        if ($order->invoice_id) {
            $invoice = Invoice::find($order->invoice_id);
            if ($invoice) {
                $invoice->update([
                    'status' => 'cancelled',
                    'payment_method' => $invoice->payment_method ?? 'paymongo_refund',
                ]);
            }
        }

        try {
            if ((int) ($order->customer_id ?? 0) > 0) {
                $this->notificationService->sendToUser(
                    userId: (int) $order->customer_id,
                    type: NotificationType::ORDER_STATUS_UPDATE,
                    title: 'Refund Completed',
                    message: "Your refund for order #{$order->order_number} has been completed and returned to your original payment method.",
                    data: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'refund_id' => $payload['paymongo_refund_id'] ?? null,
                        'refunded_at' => now()->toDateTimeString(),
                    ],
                    actionUrl: '/my-orders?tab=cancelled&highlightOrder='.$order->id,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send refund completed notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'result' => 'refunded',
            'model' => $order->fresh(),
        ];
    }

    public function recordOrderRefundFailure(Order $order, string $reason): array
    {
        $order->update([
            'payment_failed_at' => now(),
            'payment_failure_reason' => $reason,
        ]);

        return [
            'result' => 'recorded',
            'model' => $order->fresh(),
        ];
    }

    public function recordRepairPaymentFailure(RepairRequest $repair, string $reason): array
    {
        $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');
        if ($this->isRepairSettled($repair, $policy)) {
            return [
                'result' => 'already_settled',
                'model' => $repair,
                'policy' => $policy,
            ];
        }

        $payload = [
            'payment_failed_at' => now(),
            'payment_failure_reason' => $reason,
        ];

        if ($reason === 'paymongo_session_expired') {
            $payload['payment_expired_at'] = now();
        }

        if (in_array((string) ($repair->payment_status ?? 'pending'), ['pending', 'failed', 'expired', ''], true)) {
            $payload['payment_status'] = $reason === 'paymongo_session_expired' ? 'expired' : 'failed';
        }

        $repair->update($payload);

        return [
            'result' => 'recorded',
            'model' => $repair->fresh(),
            'policy' => $policy,
        ];
    }

    public function isOrderExpired(Order $order): bool
    {
        return $order->payment_expired_at !== null
            || ($order->payment_expires_at !== null
                && now()->greaterThan($order->payment_expires_at)
                && in_array((string) ($order->payment_status ?? 'pending'), ['pending', 'failed', 'expired', ''], true));
    }

    public function isRepairExpired(RepairRequest $repair, ?string $policy = null): bool
    {
        $resolvedPolicy = $policy ?? $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');
        if (! $this->isRepairPaymentDueNow($repair, $resolvedPolicy)) {
            return false;
        }

        return $repair->payment_expired_at !== null
            || ($repair->payment_expires_at !== null && now()->greaterThan($repair->payment_expires_at));
    }

    public function isRepairPaymentDueNow(RepairRequest $repair, ?string $policy = null): bool
    {
        $resolvedPolicy = $policy ?? $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

        if (data_get($repair->logistics_payment_reconciliation, 'status') === 'pending') {
            return false;
        }

        if ($this->repairDeliveryService->activePickupRecovery($repair, 'awaiting_payment')) {
            return true;
        }

        if ($this->repairDeliveryService->activeRedeliveryRequirement($repair)) {
            return true;
        }

        if ($this->needsReplacementDeliveryPayment($repair)) {
            return true;
        }

        if ($this->isRepairSettled($repair, $resolvedPolicy)) {
            return false;
        }

        if ((string) $repair->status === 'cancelled') {
            return false;
        }

        $paymentStatus = (string) ($repair->payment_status ?? 'pending');

        if ($resolvedPolicy === 'full_upfront') {
            if (in_array($paymentStatus, ['pending', 'failed', 'expired', ''], true)) {
                return true;
            }

            return in_array($paymentStatus, ['paid', 'partially_paid'], true)
                && (float) $repair->return_delivery_fee > 0
                && $this->isRepairRemainingBalancePhase($repair);
        }

        if (in_array($paymentStatus, ['pending', 'failed', 'expired', ''], true)) {
            return true;
        }

        if (in_array($paymentStatus, ['paid', 'partially_paid'], true)) {
            return $this->isRepairRemainingBalancePhase($repair);
        }

        return false;
    }

    public function isRepairSettled(RepairRequest $repair, ?string $policy = null): bool
    {
        $resolvedPolicy = $policy ?? $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

        if (data_get($repair->logistics_payment_reconciliation, 'status') === 'pending') {
            return false;
        }

        if ($this->repairDeliveryService->activePickupRecovery($repair, 'awaiting_payment')) {
            return false;
        }

        if ($this->repairDeliveryService->activeRedeliveryRequirement($repair)) {
            return false;
        }

        if ($this->needsReplacementDeliveryPayment($repair)) {
            return false;
        }

        return (string) $repair->payment_status === 'completed'
            || ($resolvedPolicy === 'full_upfront'
                && (string) $repair->payment_status === 'paid'
                && (float) $repair->return_delivery_fee <= 0);
    }

    public function normalizeRepairPaymentPolicy(?string $policy): string
    {
        $normalized = strtolower(trim((string) $policy));

        return $normalized === 'deposit_50' ? 'deposit_50' : 'full_upfront';
    }

    public function repairTaxMode(RepairRequest $repair): string
    {
        $pricingTaxMode = strtolower((string) data_get($repair->pricing_breakdown, 'tax_mode', ''));
        if (in_array($pricingTaxMode, ['vat_inclusive', 'legacy_add_on'], true)) {
            return $pricingTaxMode;
        }

        if (strtolower((string) data_get($repair->pricing_breakdown, 'mode', '')) === 'manual_pos') {
            return 'vat_inclusive';
        }

        $latestPosTaxMode = strtolower((string) data_get($repair->latestPosTransaction?->metadata, 'tax_mode', ''));

        return in_array($latestPosTaxMode, ['vat_inclusive', 'legacy_add_on'], true)
            ? $latestPosTaxMode
            : 'legacy_add_on';
    }

    public function isRepairPaymentPhaseSettled(RepairRequest $repair, string $phase): bool
    {
        if ($phase === 'pickup_retry') {
            return $this->repairDeliveryService->activePickupRecovery($repair, 'paid') !== null;
        }

        if ($phase === 'redelivery') {
            $requirement = $this->repairDeliveryService->activeRedeliveryRequirement($repair)
                ?? $this->repairDeliveryService->activeRedeliveryRequirement($repair, 'paid');
            $recoveryKey = (string) ($requirement['recovery_key'] ?? '');
            if ($recoveryKey === '') {
                return false;
            }

            return RepairPaymentSession::query()
                ->where('repair_request_id', $repair->id)
                ->where('phase', 'redelivery')
                ->whereIn('status', ['paid', 'reconciliation'])
                ->get()
                ->contains(fn (RepairPaymentSession $session): bool => (string) data_get($session->quote, 'recovery_key') === $recoveryKey
                );
        }

        $normalizedPhase = $phase === 'final' ? 'final' : 'initial';
        $dueTypes = $normalizedPhase === 'final' ? ['balance'] : ['deposit', 'full'];

        if (PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('due_type', $dueTypes)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->exists()) {
            return true;
        }

        if (RepairPaymentSession::query()
            ->where('repair_request_id', $repair->id)
            ->where('phase', $normalizedPhase)
            ->whereIn('status', ['paid', 'reconciliation'])
            ->exists()) {
            return true;
        }

        if ($normalizedPhase === 'final') {
            return (string) $repair->payment_status === 'completed';
        }

        return in_array((string) $repair->payment_status, ['paid', 'partially_paid', 'completed'], true)
            && ((float) ($repair->total_paid_amount ?? 0) > 0 || $repair->intake_logistics_locked_at !== null);
    }

    private function isOrderSettled(Order $order): bool
    {
        return in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed', 'refunded'], true);
    }

    private function isRepairRemainingBalancePhase(RepairRequest $repair): bool
    {
        return in_array((string) $repair->status, ['ready_for_pickup', 'ready-for-pickup'], true);
    }

    private function settleRepairPaymentSession(
        RepairRequest $repair,
        RepairPaymentSession $session,
        ?string $paymentId,
    ): array {
        $result = DB::transaction(function () use ($repair, $session, $paymentId): array {
            $lockedRepair = RepairRequest::query()->lockForUpdate()->findOrFail($repair->id);
            $lockedSession = RepairPaymentSession::query()->lockForUpdate()->findOrFail($session->id);
            $policy = $this->normalizeRepairPaymentPolicy($lockedRepair->payment_policy_snapshot ?: $lockedRepair->payment_policy);
            $phaseLabel = match ($lockedSession->phase) {
                'final' => 'remaining_balance',
                'redelivery' => 'redelivery',
                'pickup_retry' => 'pickup_retry',
                default => $policy === 'full_upfront' ? 'full_upfront' : 'deposit_50',
            };

            if ($lockedSession->status === 'paid') {
                return [
                    'result' => 'already_settled',
                    'model' => $lockedRepair,
                    'policy' => $policy,
                    'phase' => $phaseLabel,
                ];
            }

            if ($lockedSession->status === 'reconciliation') {
                return [
                    'result' => 'reconciliation',
                    'model' => $lockedRepair,
                    'policy' => $policy,
                    'phase' => $phaseLabel,
                ];
            }

            if ($this->repairPhaseSettledOutsideSession($lockedRepair, $lockedSession)) {
                return $this->reconcileRepairPaymentSession(
                    $lockedRepair,
                    $lockedSession,
                    $paymentId,
                    'phase_already_settled',
                    true,
                );
            }

            if ($lockedSession->invalidated_at || $lockedSession->status === 'invalidated') {
                return $this->reconcileRepairPaymentSession($lockedRepair, $lockedSession, $paymentId, 'invalidated_session');
            }

            try {
                $dueType = match ($lockedSession->phase) {
                    'final' => 'balance',
                    'redelivery' => 'redelivery',
                    'pickup_retry' => 'pickup_retry',
                    default => $policy === 'deposit_50' ? 'deposit' : 'full',
                };
                $current = $this->repairPaymentBreakdown(
                    $lockedRepair,
                    $dueType,
                );
            } catch (ValidationException) {
                return $this->reconcileRepairPaymentSession($lockedRepair, $lockedSession, $paymentId, 'delivery_plan_changed');
            }

            $deliveryMatches = hash_equals((string) ($lockedSession->snapshot_version ?? ''), (string) ($current['snapshot_version'] ?? ''))
                && (string) $lockedSession->delivery_method === (string) $current['delivery_method']
                && round((float) $lockedSession->delivery_amount, 2) === round((float) $current['delivery_amount'], 2);

            if (! $deliveryMatches) {
                return $this->reconcileRepairPaymentSession($lockedRepair, $lockedSession, $paymentId, 'delivery_plan_changed');
            }

            $sessionQuote = is_array($lockedSession->quote) ? $lockedSession->quote : [];
            $storedPolicy = data_get($sessionQuote, 'payment_policy');
            $storedServiceBase = data_get($sessionQuote, 'service_base_amount');
            $storedTaxMode = data_get($sessionQuote, 'tax_mode');
            $storedRecoveryKey = data_get($sessionQuote, 'recovery_key');
            $currentTaxMode = $this->repairTaxMode($lockedRepair);
            $serviceMatches = $storedServiceBase !== null
                ? round((float) $storedServiceBase, 2) === round((float) $current['service_amount'], 2)
                : round((float) $lockedSession->service_amount, 2) === round((float) $current['service_amount'], 2);
            $paymentPlanMatches = $serviceMatches
                && ($storedPolicy === null || (string) $storedPolicy === (string) $current['policy'])
                && (! in_array($lockedSession->phase, ['redelivery', 'pickup_retry'], true)
                    || ($storedRecoveryKey !== null
                        && (string) $storedRecoveryKey === (string) ($current['recovery_key'] ?? '')))
                && ($lockedSession->phase !== 'pickup_retry'
                    || (string) data_get($sessionQuote, 'plan_key', '') === (string) ($current['plan_key'] ?? ''))
                && ($storedTaxMode === null || strtolower((string) $storedTaxMode) === $currentTaxMode);

            if (! $paymentPlanMatches) {
                return $this->reconcileRepairPaymentSession($lockedRepair, $lockedSession, $paymentId, 'payment_plan_changed');
            }

            $settlementBreakdown = array_merge($current, [
                'service_amount' => round((float) $lockedSession->service_amount, 2),
                'delivery_amount' => round((float) $lockedSession->delivery_amount, 2),
                'total_amount' => round((float) $lockedSession->service_amount + (float) $lockedSession->delivery_amount, 2),
            ]);
            $settledRepair = $this->settleRepairPhasePaid($lockedRepair, $settlementBreakdown, $paymentId);
            $lockedSession->update([
                'status' => 'paid',
                'resolved_at' => now(),
            ]);

            return [
                'result' => 'settled',
                'model' => $settledRepair,
                'policy' => $policy,
                'phase' => $phaseLabel,
                'pickup_notification' => $lockedSession->phase === 'pickup_retry' ? [
                    'plan_key' => (string) ($current['plan_key'] ?? ''),
                ] : null,
            ];
        });

        if (is_array($result['pickup_notification'] ?? null)) {
            $this->notificationService->notifyRepairPickupRecovery(
                $result['model'],
                'ready_for_dispatch',
                (string) $result['pickup_notification']['plan_key'],
            );
        }

        return $result;
    }

    private function reconcileRepairPaymentSession(
        RepairRequest $repair,
        RepairPaymentSession $session,
        ?string $paymentId,
        string $reason,
        bool $serviceAlreadyApplied = false,
    ): array {
        $serviceAmount = round((float) $session->service_amount, 2);
        $serviceAmountApplied = $serviceAlreadyApplied ? 0.0 : $serviceAmount;
        $serviceBaseAmount = round((float) data_get($session->quote, 'service_base_amount', $serviceAmount), 2);
        $serviceBaseAmountApplied = $serviceAlreadyApplied ? 0.0 : $serviceBaseAmount;
        $paymentReferences = $this->appendRepairPaymentReference(
            is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : null,
            $paymentId,
        );

        $entry = [
            'reason' => $reason,
            'phase' => $session->phase,
            'payment_session_id' => $session->id,
            'provider_link_id' => $session->provider_link_id,
            'payment_id' => $paymentId,
            'service_amount_applied' => $serviceAmountApplied,
            'service_base_amount_applied' => $serviceBaseAmountApplied,
            'duplicate_service_amount' => $serviceAlreadyApplied ? $serviceAmount : 0.0,
            'delivery_amount' => round((float) $session->delivery_amount, 2),
            'reconciliation_amount' => round(
                ($serviceAlreadyApplied ? $serviceAmount : 0.0) + (float) $session->delivery_amount,
                2,
            ),
            'created_at' => now()->toISOString(),
        ];
        $currentReconciliation = is_array($repair->logistics_payment_reconciliation)
            ? $repair->logistics_payment_reconciliation
            : [];
        $entries = collect(data_get($currentReconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry));

        if ($entries->isEmpty() && data_get($currentReconciliation, 'payment_session_id')) {
            $entries->push(collect($currentReconciliation)->except(['status', 'entries', 'total_reconciliation_amount'])->all());
        }

        if (! $entries->contains(fn (array $existing): bool => (int) ($existing['payment_session_id'] ?? 0) === (int) $session->id)) {
            $entries->push($entry);
        }

        $reconciliation = [
            ...$entry,
            'status' => 'pending',
            'entries' => $entries->values()->all(),
            'total_reconciliation_amount' => round((float) $entries->sum(
                fn (array $item): float => (float) ($item['reconciliation_amount'] ?? 0)
            ), 2),
        ];
        $isRecoveryFee = in_array($session->phase, ['redelivery', 'pickup_retry'], true);

        $updates = [
            'payment_status' => ($serviceAlreadyApplied || $isRecoveryFee) ? $repair->payment_status : 'paid',
            'payment_status_derived' => ($serviceAlreadyApplied || $isRecoveryFee) ? $repair->payment_status_derived : 'paid',
            'total_paid_amount' => round((float) ($repair->total_paid_amount ?? 0) + $serviceAmountApplied, 2),
            'paymongo_payment_id' => $paymentId ?: $repair->paymongo_payment_id,
            'paymongo_payment_ids' => $paymentReferences ?: null,
            'logistics_payment_reconciliation' => $reconciliation,
        ];
        if ($session->phase !== 'pickup_retry') {
            $updates[in_array($session->phase, ['final', 'redelivery'], true)
                ? 'return_logistics_locked_at'
                : 'intake_logistics_locked_at'] = now();
        }
        $repair->update($updates);
        $session->update([
            'status' => 'reconciliation',
            'resolved_at' => now(),
        ]);

        return [
            'result' => 'reconciliation',
            'model' => $repair->fresh(),
            'policy' => $this->normalizeRepairPaymentPolicy($repair->payment_policy_snapshot ?: $repair->payment_policy),
            'phase' => match ($session->phase) {
                'final' => 'remaining_balance',
                'redelivery' => 'redelivery',
                default => 'deposit_50',
            },
        ];
    }

    private function repairPhaseSettledOutsideSession(
        RepairRequest $repair,
        RepairPaymentSession $session,
    ): bool {
        if (in_array($session->phase, ['redelivery', 'pickup_retry'], true)) {
            $recoveryKey = (string) data_get($session->quote, 'recovery_key', '');

            return $recoveryKey !== ''
                && RepairPaymentSession::query()
                    ->where('repair_request_id', $repair->id)
                    ->where('phase', 'redelivery')
                    ->where('id', '!=', $session->id)
                    ->whereIn('status', ['paid', 'reconciliation'])
                    ->get()
                    ->contains(fn (RepairPaymentSession $other): bool => (string) data_get($other->quote, 'recovery_key') === $recoveryKey
                    );
        }

        $dueType = $session->phase === 'final'
            ? 'balance'
            : ($this->normalizeRepairPaymentPolicy($repair->payment_policy_snapshot ?: $repair->payment_policy) === 'deposit_50'
                ? 'deposit'
                : 'full');

        return PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->where('due_type', $dueType)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->exists()
            || RepairPaymentSession::query()
                ->where('repair_request_id', $repair->id)
                ->where('phase', $session->phase)
                ->where('id', '!=', $session->id)
                ->whereIn('status', ['paid', 'reconciliation'])
                ->exists();
    }

    private function resolveRepairPaymentPhase(RepairRequest $repair, string $policy, ?string $dueType): string
    {
        if ($this->repairDeliveryService->activePickupRecovery($repair, 'awaiting_payment')) {
            if ($dueType !== null && $dueType !== 'pickup_retry') {
                throw ValidationException::withMessages([
                    'due_type' => ['Only the new pickup fee is payable for this repair.'],
                ]);
            }

            return 'pickup_retry';
        }
        if ($dueType === 'pickup_retry') {
            throw ValidationException::withMessages([
                'due_type' => ['This repair is no longer awaiting a pickup retry payment.'],
            ]);
        }

        if ($this->repairDeliveryService->activeRedeliveryRequirement($repair)) {
            if ($dueType !== null && $dueType !== 'redelivery') {
                throw ValidationException::withMessages([
                    'due_type' => ['Only the new re-delivery fee is payable for this repair.'],
                ]);
            }

            return 'redelivery';
        }
        if ($dueType === 'redelivery') {
            throw ValidationException::withMessages([
                'due_type' => ['This repair is no longer awaiting a re-delivery payment.'],
            ]);
        }

        $isFinal = $dueType === 'balance'
            || ($dueType === null
                && in_array((string) $repair->payment_status, ['paid', 'partially_paid'], true)
                && $this->isRepairRemainingBalancePhase($repair));
        $expectedDueType = $isFinal ? 'balance' : ($policy === 'deposit_50' ? 'deposit' : 'full');

        if ($dueType !== null && $dueType !== $expectedDueType) {
            throw ValidationException::withMessages([
                'due_type' => ['Selected due type is not allowed for the current payment phase.'],
            ]);
        }

        if ($isFinal && ! $this->isRepairRemainingBalancePhase($repair)) {
            throw ValidationException::withMessages([
                'due_type' => ['The final payment is available only when the repair is ready for return.'],
            ]);
        }

        if ($isFinal && ! $this->isRepairPaymentPhaseSettled($repair, 'initial')) {
            throw ValidationException::withMessages([
                'due_type' => ['The initial payment must be settled before the final payment can be collected.'],
            ]);
        }

        return $isFinal ? 'final' : 'initial';
    }

    private function resolveRepairServiceTotal(RepairRequest $repair, string $policy, string $phase): float
    {
        $pricingBreakdown = is_array($repair->pricing_breakdown) ? $repair->pricing_breakdown : [];
        $packagePrice = round((float) ($repair->package_price ?? ($pricingBreakdown['package_price'] ?? 0)), 2);
        $addOnsTotal = round((float) ($repair->add_ons_total ?? ($pricingBreakdown['add_ons_total'] ?? 0)), 2);
        $packagePlusAddOns = $repair->repair_package_id ? round($packagePrice + $addOnsTotal, 2) : 0.0;
        $candidates = [
            round((float) ($repair->final_total ?? 0), 2),
            round((float) ($repair->total ?? 0), 2),
            round((float) ($pricingBreakdown['base_total'] ?? 0), 2),
            round((float) ($pricingBreakdown['final_total'] ?? 0), 2),
            $packagePlusAddOns,
        ];
        $serviceTotal = max($candidates);

        if ($policy === 'deposit_50' && $phase === 'final') {
            $initialServiceAmount = $this->resolveRepairInitialServiceAmount($repair);

            if ($initialServiceAmount > 0) {
                $serviceTotal = max($serviceTotal, round($initialServiceAmount * 2, 2));
            }
        }

        return round(max($serviceTotal, 0), 2);
    }

    private function resolveRepairInitialServiceAmount(RepairRequest $repair): float
    {
        $posAmount = (float) PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('due_type', ['deposit', 'full'])
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->get()
            ->sum(fn (PosTransaction $transaction) => (float) data_get(
                $transaction->metadata,
                'service_amount',
                $transaction->total_amount
            ));

        $reconciliationEntries = collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry))
            ->keyBy(fn (array $entry): int => (int) ($entry['payment_session_id'] ?? 0));
        $onlineAmount = (float) RepairPaymentSession::query()
            ->where('repair_request_id', $repair->id)
            ->where('phase', 'initial')
            ->whereIn('status', ['paid', 'reconciliation'])
            ->get()
            ->sum(function (RepairPaymentSession $session) use ($reconciliationEntries): float {
                $serviceBaseAmount = round((float) data_get(
                    $session->quote,
                    'service_base_amount',
                    $session->service_amount
                ), 2);

                if ($session->status === 'paid') {
                    return $serviceBaseAmount;
                }

                $entry = $reconciliationEntries->get((int) $session->id);
                if (! is_array($entry) || (float) ($entry['service_amount_applied'] ?? 0) <= 0) {
                    return 0.0;
                }

                return round((float) ($entry['service_base_amount_applied'] ?? $serviceBaseAmount), 2);
            });
        $serviceCredits = (float) collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
            ->filter(fn ($entry): bool => is_array($entry)
                && (string) ($entry['status'] ?? '') === 'resolved'
                && (string) ($entry['resolution_action'] ?? '') === 'credit_balance')
            ->sum(fn (array $entry): float => (float) ($entry['credited_amount'] ?? 0));
        $recordedAmount = round($posAmount + $onlineAmount + $serviceCredits, 2);

        if ($recordedAmount <= 0
            && in_array((string) $repair->payment_status, ['paid', 'partially_paid', 'completed'], true)) {
            $recordedAmount = max(
                0,
                round((float) ($repair->total_paid_amount ?? 0) - (float) $repair->intake_delivery_fee, 2),
            );
        }

        return round($recordedAmount, 2);
    }

    private function appendRepairPaymentReference(?array $current, ?string $paymentReference): array
    {
        $history = collect($current ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '' && $this->looksLikeGatewayPaymentReference($value))
            ->values()
            ->all();

        $candidate = trim((string) ($paymentReference ?? ''));
        if ($candidate === '' || ! $this->looksLikeGatewayPaymentReference($candidate)) {
            return $history;
        }

        if (! in_array($candidate, $history, true)) {
            $history[] = $candidate;
        }

        return $history;
    }

    private function looksLikeGatewayPaymentReference(string $reference): bool
    {
        $value = strtolower(trim($reference));

        return $value !== ''
            && (
                str_starts_with($value, 'pay_')
                || str_starts_with($value, 'pi_')
                || str_starts_with($value, 'src_')
                || str_starts_with($value, 'pmw_')
                || str_starts_with($value, 'pmc_')
            );
    }
}
