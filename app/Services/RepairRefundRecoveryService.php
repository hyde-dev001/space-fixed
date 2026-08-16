<?php

namespace App\Services;

use App\Models\PosRefund;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairRefundRecoveryService
{
    public function initializeFailure(PosRefund $refund): PosRefund
    {
        return DB::transaction(function () use ($refund): PosRefund {
            $locked = $this->lock($refund);

            if ((string) $locked->status !== 'failed') {
                throw $this->invalidState('Only a failed refund can enter recovery.');
            }

            if (in_array((string) $locked->recovery_status, [
                PosRefund::RECOVERY_STATUS_RESOLVED,
                PosRefund::RECOVERY_STATUS_SUPERSEDED,
            ], true)) {
                throw $this->invalidState('A terminal recovery cannot be initialized again.');
            }

            $locked->update([
                'recovery_status' => $locked->recovery_status ?: PosRefund::RECOVERY_STATUS_UNRESOLVED,
                'recovery_responsible_party' => $locked->recovery_responsible_party ?: PosRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_attempt_count' => (int) ($locked->recovery_attempt_count ?? 0),
            ]);

            return $locked->fresh();
        });
    }

    public function recordFailure(PosRefund $refund, int $actorId, string $reason, ?string $executionNote = null): PosRefund
    {
        return DB::transaction(function () use ($refund, $actorId, $reason, $executionNote): PosRefund {
            $locked = $this->lock($refund);
            $now = now();
            $cleanReason = Str::limit(trim($reason), 255, '');

            if (in_array((string) $locked->recovery_status, [
                PosRefund::RECOVERY_STATUS_RESOLVED,
                PosRefund::RECOVERY_STATUS_SUPERSEDED,
            ], true)) {
                throw $this->invalidState('A terminal recovery cannot be failed again.');
            }

            $payload = [
                'status' => 'failed',
                'execution_mode' => 'gateway',
                'execution_notes' => $executionNote !== null
                    ? Str::limit(trim($executionNote), 1000, '')
                    : ($locked->execution_notes ? Str::limit((string) $locked->execution_notes, 1000, '') : null),
                'executed_by' => $actorId > 0 ? $actorId : ($locked->executed_by ?? null),
                'executed_at' => $locked->executed_at ?? $now,
                'recovery_status' => $locked->recovery_status ?: PosRefund::RECOVERY_STATUS_UNRESOLVED,
                'recovery_responsible_party' => $locked->recovery_responsible_party ?: PosRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_attempt_count' => (int) ($locked->recovery_attempt_count ?? 0),
            ];

            if (!$locked->failed_at) {
                $payload['failed_at'] = $now;
            }

            if (trim((string) $locked->failure_reason) === '' && $cleanReason !== '') {
                $payload['failure_reason'] = $cleanReason;
            }

            $locked->update($payload);

            return $locked->fresh();
        });
    }

    public function claim(PosRefund $refund, string $responsibleParty): PosRefund
    {
        $this->assertResponsibleParty($responsibleParty);

        return DB::transaction(function () use ($refund, $responsibleParty): PosRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before recovery ownership.');
            }

            if ((string) $locked->recovery_status === PosRefund::RECOVERY_STATUS_IN_PROGRESS
                && (string) $locked->recovery_responsible_party !== $responsibleParty) {
                throw $this->invalidState('This recovery is already owned by another party.');
            }

            $locked->update([
                'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
                'recovery_responsible_party' => $responsibleParty,
            ]);

            return $locked->fresh();
        });
    }

    public function recordRetry(PosRefund $refund, ?CarbonInterface $attemptedAt = null): PosRefund
    {
        return DB::transaction(function () use ($refund, $attemptedAt): PosRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before recovery retry.');
            }

            if ((string) $locked->recovery_status !== PosRefund::RECOVERY_STATUS_IN_PROGRESS
                || (string) $locked->recovery_responsible_party === PosRefund::RECOVERY_RESPONSIBLE_NONE) {
                throw $this->invalidState('Recovery must be claimed before a retry is recorded.');
            }

            $locked->update([
                'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
                'recovery_attempt_count' => (int) ($locked->recovery_attempt_count ?? 0) + 1,
                'recovery_last_attempted_at' => $attemptedAt ?? now(),
            ]);

            return $locked->fresh();
        });
    }

    public function replace(PosRefund $refund, PosRefund $replacement): PosRefund
    {
        return DB::transaction(function () use ($refund, $replacement): PosRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before replacement.');
            }

            if (!$replacement->exists || !$replacement->getKey() || $locked->is($replacement)) {
                throw $this->invalidState('A distinct persisted replacement refund is required.');
            }

            if ((int) $locked->shop_owner_id !== (int) $replacement->shop_owner_id
                || (int) $locked->source_transaction_id !== (int) $replacement->source_transaction_id) {
                throw $this->invalidState('A replacement must belong to the same shop and source transaction.');
            }

            if ((string) $replacement->status === 'failed'
                || $replacement->recovery_status !== null) {
                throw $this->invalidState('A replacement must be a new non-failed refund.');
            }

            $locked->update([
                'recovery_status' => PosRefund::RECOVERY_STATUS_SUPERSEDED,
                'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
                'replacement_refund_id' => $replacement->getKey(),
            ]);

            return $locked->fresh();
        });
    }

    public function resolve(
        PosRefund $refund,
        string $resolvedByType,
        int $resolvedById,
        CarbonInterface $resolvedAt,
        string $outcome,
        string $reason,
    ): PosRefund {
        $resolvedByType = strtolower(trim($resolvedByType));
        $outcome = strtolower(trim($outcome));
        $reason = trim($reason);

        if (!in_array($resolvedByType, PosRefund::RECOVERY_RESOLVER_TYPES, true)
            || $resolvedByType === 'system'
            || $resolvedById <= 0) {
            throw $this->invalidState('A valid resolver actor is required.');
        }

        if (!in_array($outcome, PosRefund::RECOVERY_OUTCOMES, true)
            || $outcome === PosRefund::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS) {
            throw $this->invalidState('A valid manual recovery outcome is required.');
        }

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw $this->invalidState('A non-empty recovery resolution reason is required.');
        }

        return DB::transaction(function () use ($refund, $resolvedByType, $resolvedById, $resolvedAt, $outcome, $reason): PosRefund {
            $locked = $this->lock($refund);
            $this->assertOpenRecovery($locked);

            if ($this->ownerDecisionRequired($locked)) {
                throw $this->invalidState('Owner decision must be handled before manual recovery resolution.');
            }

            $locked->update([
                'recovery_status' => PosRefund::RECOVERY_STATUS_RESOLVED,
                'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_resolved_at' => $resolvedAt,
                'recovery_resolved_by_type' => $resolvedByType,
                'recovery_resolved_by_id' => $resolvedById,
                'recovery_resolution_outcome' => $outcome,
                'recovery_resolution_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    public function recordSuccessfulExecution(PosRefund $refund, ?int $actorId = null, ?CarbonInterface $resolvedAt = null): PosRefund
    {
        return DB::transaction(function () use ($refund, $actorId, $resolvedAt): PosRefund {
            $locked = $this->lock($refund);

            if ($locked->recovery_status === null
                || in_array((string) $locked->recovery_status, [
                    PosRefund::RECOVERY_STATUS_RESOLVED,
                    PosRefund::RECOVERY_STATUS_SUPERSEDED,
                ], true)) {
                return $locked->fresh();
            }

            $locked->update([
                'recovery_status' => PosRefund::RECOVERY_STATUS_RESOLVED,
                'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
                'recovery_resolved_at' => $resolvedAt ?? now(),
                'recovery_resolved_by_type' => $actorId && $actorId > 0 ? 'user' : 'system',
                'recovery_resolved_by_id' => $actorId && $actorId > 0 ? $actorId : null,
                'recovery_resolution_outcome' => PosRefund::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS,
                'recovery_resolution_reason' => 'Refund execution completed successfully after recovery processing.',
            ]);

            return $locked->fresh();
        });
    }

    private function lock(PosRefund $refund): PosRefund
    {
        return PosRefund::query()
            ->whereKey($refund->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertOpenRecovery(PosRefund $refund): void
    {
        if (!in_array((string) $refund->recovery_status, [
            PosRefund::RECOVERY_STATUS_UNRESOLVED,
            PosRefund::RECOVERY_STATUS_IN_PROGRESS,
        ], true)) {
            throw $this->invalidState('This refund recovery is not open.');
        }
    }

    private function ownerDecisionRequired(PosRefund $refund): bool
    {
        return strtolower(trim((string) ($refund->shop_owner_status ?? ''))) === 'pending';
    }

    private function assertResponsibleParty(string $responsibleParty): void
    {
        if (!in_array($responsibleParty, [
            PosRefund::RECOVERY_RESPONSIBLE_FINANCE,
            PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
        ], true)) {
            throw $this->invalidState('An approved recovery responsibility is required.');
        }
    }

    private function invalidState(string $message): ValidationException
    {
        return ValidationException::withMessages(['recovery_status' => [$message]]);
    }
}
