<?php

namespace App\Services;

use App\Models\OrderRefund;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderRefundRecoveryService
{
    public function initializeFailure(OrderRefund $refund): OrderRefund
    {
        return DB::transaction(function () use ($refund): OrderRefund {
            $locked = $this->lock($refund);

            if ((string) $locked->status !== 'failed') {
                throw $this->invalidState('Only a failed refund can enter recovery.');
            }

            if (in_array((string) $locked->recovery_status, [
                OrderRefund::RECOVERY_STATUS_RESOLVED,
                OrderRefund::RECOVERY_STATUS_SUPERSEDED,
            ], true)) {
                throw $this->invalidState('A terminal recovery cannot be initialized again.');
            }

            $locked->update([
                'recovery_status' => $locked->recovery_status ?: OrderRefund::RECOVERY_STATUS_UNRESOLVED,
                'recovery_responsible_party' => $locked->recovery_responsible_party ?: OrderRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_attempt_count' => (int) ($locked->recovery_attempt_count ?? 0),
            ]);

            return $locked->fresh();
        });
    }

    public function recordFailure(OrderRefund $refund, string $reason, ?int $processedBy = null): OrderRefund
    {
        return DB::transaction(function () use ($refund, $reason, $processedBy): OrderRefund {
            $locked = $this->lock($refund);
            $now = now();
            $cleanReason = Str::limit(trim($reason), 1000, '');

            if (in_array((string) $locked->recovery_status, [
                OrderRefund::RECOVERY_STATUS_RESOLVED,
                OrderRefund::RECOVERY_STATUS_SUPERSEDED,
            ], true)) {
                throw $this->invalidState('A terminal recovery cannot be failed again.');
            }

            $payload = [
                'status' => 'failed',
                'recovery_status' => $locked->recovery_status ?: OrderRefund::RECOVERY_STATUS_UNRESOLVED,
                'recovery_responsible_party' => $locked->recovery_responsible_party ?: OrderRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_attempt_count' => (int) ($locked->recovery_attempt_count ?? 0),
            ];

            if (!$locked->failed_at) {
                $payload['failed_at'] = $now;
            }

            if (trim((string) $locked->failure_reason) === '' && $cleanReason !== '') {
                $payload['failure_reason'] = $cleanReason;
            }

            if ($processedBy !== null) {
                $payload['processed_by'] = $processedBy > 0 ? $processedBy : null;
            }

            $locked->update($payload);

            return $locked->fresh();
        });
    }

    public function claim(OrderRefund $refund, string $responsibleParty): OrderRefund
    {
        $this->assertResponsibleParty($responsibleParty);

        return DB::transaction(function () use ($refund, $responsibleParty): OrderRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before recovery ownership.');
            }

            $samePartyInProgress = (string) $locked->recovery_status === OrderRefund::RECOVERY_STATUS_IN_PROGRESS
                && (string) $locked->recovery_responsible_party === $responsibleParty;

            if (! $samePartyInProgress) {
                $locked->update([
                    'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
                    'recovery_responsible_party' => $responsibleParty,
                    'recovery_assigned_at' => now(),
                ]);
            }

            return $locked->fresh();
        });
    }

    public function recordRetry(OrderRefund $refund, ?CarbonInterface $attemptedAt = null): OrderRefund
    {
        return DB::transaction(function () use ($refund, $attemptedAt): OrderRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before recovery retry.');
            }

            if ((string) $locked->recovery_status !== OrderRefund::RECOVERY_STATUS_IN_PROGRESS
                || (string) $locked->recovery_responsible_party === OrderRefund::RECOVERY_RESPONSIBLE_NONE) {
                throw $this->invalidState('Recovery must be claimed before a retry is recorded.');
            }

            $locked->update([
                'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
                'recovery_attempt_count' => (int) ($locked->recovery_attempt_count ?? 0) + 1,
                'recovery_last_attempted_at' => $attemptedAt ?? now(),
            ]);

            return $locked->fresh();
        });
    }

    public function replace(OrderRefund $refund, OrderRefund $replacement): OrderRefund
    {
        return DB::transaction(function () use ($refund, $replacement): OrderRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before replacement.');
            }

            if (!$replacement->exists || !$replacement->getKey() || $locked->is($replacement)) {
                throw $this->invalidState('A distinct persisted replacement refund is required.');
            }

            if ((int) $locked->shop_owner_id !== (int) $replacement->shop_owner_id
                || (int) $locked->order_id !== (int) $replacement->order_id) {
                throw $this->invalidState('A replacement must belong to the same shop and order.');
            }

            if ((string) $replacement->status === 'failed'
                || $replacement->recovery_status !== null) {
                throw $this->invalidState('A replacement must be a new non-failed refund.');
            }

            $locked->update([
                'recovery_status' => OrderRefund::RECOVERY_STATUS_SUPERSEDED,
                'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
                'replacement_refund_id' => $replacement->getKey(),
            ]);

            return $locked->fresh();
        });
    }

    public function resolve(
        OrderRefund $refund,
        string $resolvedByType,
        int $resolvedById,
        CarbonInterface $resolvedAt,
        string $outcome,
        string $reason,
    ): OrderRefund {
        $resolvedByType = strtolower(trim($resolvedByType));
        $outcome = strtolower(trim($outcome));
        $reason = trim($reason);

        if (!in_array($resolvedByType, OrderRefund::RECOVERY_RESOLVER_TYPES, true)
            || $resolvedByType === 'system'
            || $resolvedById <= 0) {
            throw $this->invalidState('A valid resolver actor is required.');
        }

        if (!in_array($outcome, OrderRefund::RECOVERY_OUTCOMES, true)
            || $outcome === OrderRefund::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS) {
            throw $this->invalidState('A valid manual recovery outcome is required.');
        }

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw $this->invalidState('A non-empty recovery resolution reason is required.');
        }

        return DB::transaction(function () use ($refund, $resolvedByType, $resolvedById, $resolvedAt, $outcome, $reason): OrderRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before manual recovery resolution.');
            }

            $locked->update([
                'recovery_status' => OrderRefund::RECOVERY_STATUS_RESOLVED,
                'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_resolved_at' => $resolvedAt,
                'recovery_resolved_by_type' => $resolvedByType,
                'recovery_resolved_by_id' => $resolvedById,
                'recovery_resolution_outcome' => $outcome,
                'recovery_resolution_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    public function recordSuccessfulExecution(OrderRefund $refund, ?int $actorId = null, ?CarbonInterface $resolvedAt = null): OrderRefund
    {
        return DB::transaction(function () use ($refund, $actorId, $resolvedAt): OrderRefund {
            $locked = $this->lock($refund);

            if ($locked->recovery_status === null
                || in_array((string) $locked->recovery_status, [
                    OrderRefund::RECOVERY_STATUS_RESOLVED,
                    OrderRefund::RECOVERY_STATUS_SUPERSEDED,
                ], true)) {
                return $locked->fresh();
            }

            $locked->update([
                'recovery_status' => OrderRefund::RECOVERY_STATUS_RESOLVED,
                'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_resolved_at' => $resolvedAt ?? now(),
                'recovery_resolved_by_type' => $actorId && $actorId > 0 ? 'user' : 'system',
                'recovery_resolved_by_id' => $actorId && $actorId > 0 ? $actorId : null,
                'recovery_resolution_outcome' => OrderRefund::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS,
                'recovery_resolution_reason' => 'Refund execution completed successfully after recovery processing.',
            ]);

            return $locked->fresh();
        });
    }

    private function lock(OrderRefund $refund): OrderRefund
    {
        return OrderRefund::query()
            ->whereKey($refund->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertOpenRecovery(OrderRefund $refund): void
    {
        if (!in_array((string) $refund->recovery_status, [
            OrderRefund::RECOVERY_STATUS_UNRESOLVED,
            OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
        ], true)) {
            throw $this->invalidState('This refund recovery is not open.');
        }
    }

    private function ownerDecisionRequired(OrderRefund $refund): bool
    {
        return strtolower(trim((string) ($refund->shop_owner_status ?? ''))) === 'pending';
    }

    private function assertResponsibleParty(string $responsibleParty): void
    {
        if (!in_array($responsibleParty, [
            OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            OrderRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
        ], true)) {
            throw $this->invalidState('An approved recovery responsibility is required.');
        }
    }

    private function invalidState(string $message): ValidationException
    {
        return ValidationException::withMessages(['recovery_status' => [$message]]);
    }
}
