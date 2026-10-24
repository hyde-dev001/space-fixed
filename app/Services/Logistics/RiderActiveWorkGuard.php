<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Validation\ValidationException;

final class RiderActiveWorkGuard
{
    public function assertNotHeld(ShipmentLeg $leg): void
    {
        if (\App\Models\Logistics\DeliveryIncidentStop::where('shipment_leg_id', $leg->id)->where('held', true)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['batch_interruption' => 'This batch is interrupted. Wait for dispatcher recovery and all required handoffs.']);
        }
    }

    public function assertBatchNotHeld(DeliveryBatch $batch): void
    {
        if (DeliveryBatch::whereKey($batch->id)->whereNotNull('active_incident_id')->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['batch_interruption' => 'This batch is interrupted. Dispatcher authorization and all parcel handoffs are required.']);
        }
    }

    private function hasInterruptionResponsibility(RiderProfile $rider): bool
    {
        return DeliveryBatch::where('rider_profile_id', $rider->id)->where('status', 'interrupted')->whereNotNull('active_incident_id')->exists();
    }
    private const ACTIVE_STANDALONE_STATUSES = [
        'picked_up',
        'in_transit',
        'delivery_attempted',
    ];

    public function assertCanStartBatch(RiderProfile $rider, DeliveryBatch $batch): void
    {
        $this->assertBatchNotHeld($batch);
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = $this->activeBatchQuery($rider)
            ->whereKeyNot($batch->id)
            ->exists();

        $this->reject($hasBatch || $this->hasInterruptionResponsibility($rider)
            || $this->activeStandaloneQuery($rider)->exists()
            || $this->custodyHoldQuery($rider)->exists());
    }

    public function assertCanStartStandalone(RiderProfile $rider, ShipmentLeg $leg): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = $this->activeBatchQuery($rider)->exists();

        $hasStandalone = $this->activeStandaloneQuery($rider)
            ->whereKeyNot($leg->id)
            ->exists();

        $this->reject($hasBatch || $hasStandalone || $this->custodyHoldQuery($rider)->exists() || $this->hasInterruptionResponsibility($rider));
    }

    public function assertCanAdvanceLeg(RiderProfile $rider, ShipmentLeg $leg): void
    {
        $this->assertNotHeld($leg);
        if ($leg->customer_appointment_date && ($reason = app(DeliveryStartEligibility::class)->blockedReason($leg))) {
            throw ValidationException::withMessages(['customer_appointment' => $reason]);
        }
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        if ($leg->rider_progress_state !== RiderProgressState::ACTIVE
            || $leg->status->value === 'proof_correction_required') {
            $this->reject(true);
        }

        $batchCandidates = $this->activeBatchQuery($rider)
            ->get(['id', 'rider_profile_id', 'started_at'])
            ->map(function (DeliveryBatch $batch): ?array {
                $next = $this->firstActiveBatchLeg($batch->id, (int) $batch->rider_profile_id);
                if (! $next) {
                    return null;
                }

                return [
                    'key' => "batch:{$batch->id}",
                    'started_at' => $batch->started_at?->format('Y-m-d H:i:s.u') ?? '9999-12-31',
                    'kind' => 'batch',
                    'id' => $batch->id,
                    'next_leg_id' => $next->id,
                ];
            })
            ->filter()
            ->values();

        $candidates = $batchCandidates
            ->concat(
                $this->activeStandaloneQuery($rider)
                    ->with('latestAssignment')
                    ->get()
                    ->map(fn (ShipmentLeg $activeLeg) => [
                        'key' => "single:{$activeLeg->id}",
                        'started_at' => (
                            $activeLeg->out_for_delivery_at
                            ?? $activeLeg->picked_up_at
                            ?? $activeLeg->latestAssignment?->accepted_at
                            ?? $activeLeg->latestAssignment?->assigned_at
                        )?->format('Y-m-d H:i:s.u') ?? '9999-12-31',
                        'kind' => 'single',
                        'id' => $activeLeg->id,
                        'next_leg_id' => $activeLeg->id,
                    ])
            )
            ->sortBy(fn (array $item) => [$item['started_at'], $item['kind'], $item['id']])
            ->values();

        if ($candidates->isEmpty()) {
            return;
        }

        if ($leg->delivery_batch_id) {
            $next = $this->firstActiveBatchLeg($leg->delivery_batch_id, $rider->id);
            if (! $next || (int) $next->id !== (int) $leg->id) {
                $this->reject(true);
            }
        }

        $targetKey = $leg->delivery_batch_id
            ? "batch:{$leg->delivery_batch_id}"
            : "single:{$leg->id}";
        if ($candidates->first()['key'] !== $targetKey) {
            throw ValidationException::withMessages([
                'active_work' => 'This is not your current delivery. Refresh My Deliveries and continue only the highlighted Current delivery.',
            ]);
        }
    }

    private function activeStandaloneQuery(RiderProfile $rider)
    {
        return ShipmentLeg::query()
            ->whereNull('delivery_batch_id')
            ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
            ->whereIn('status', self::ACTIVE_STANDALONE_STATUSES)
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('rider_profile_id', $rider->id)
                ->whereIn('status', ['assigned', 'accepted']));
    }

    private function activeBatchQuery(RiderProfile $rider)
    {
        return DeliveryBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'in_progress')
            ->whereHas('legs', fn ($query) => $query
                ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
                ->whereNotIn('status', ['delivered', 'cancelled', 'failed', 'proof_correction_required'])
                ->whereHas('latestAssignment', fn ($assignments) => $assignments
                    ->where('rider_profile_id', $rider->id)
                    ->whereIn('status', ['assigned', 'accepted'])));
    }

    private function firstActiveBatchLeg(int $batchId, int $riderId): ?ShipmentLeg
    {
        return ShipmentLeg::query()
            ->where('delivery_batch_id', $batchId)
            ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
            ->whereNotIn('status', ['delivered', 'cancelled', 'failed', 'proof_correction_required'])
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('rider_profile_id', $riderId)
                ->whereIn('status', ['assigned', 'accepted']))
            ->orderByRaw("CASE WHEN leg_type = 'return_to_shop' THEN 1 ELSE 0 END")
            ->orderByRaw('stop_sequence IS NULL')
            ->orderBy('stop_sequence')
            ->orderBy('id')
            ->first();
    }

    private function custodyHoldQuery(RiderProfile $rider)
    {
        return ShipmentLeg::query()
            ->where('status', 'needs_resolution')
            ->where(function ($query) use ($rider) {
                $query->where(fn ($held) => $held->where('custody_state', 'rider')->where('custody_rider_profile_id', $rider->id))
                    ->orWhere(fn ($legacy) => $legacy->where('custody_state', 'unknown')
                        ->whereHas('latestAssignment', fn ($assignment) => $assignment->where('rider_profile_id', $rider->id)->where('status', 'accepted')));
            });
    }

    private function reject(bool $blocked): void
    {
        if ($blocked) {
            throw ValidationException::withMessages([
                'active_work' => 'Finish your current delivery before starting another.',
            ]);
        }
    }
}
