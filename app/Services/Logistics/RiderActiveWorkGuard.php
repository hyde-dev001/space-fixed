<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Validation\ValidationException;

final class RiderActiveWorkGuard
{
    private const ACTIVE_STANDALONE_STATUSES = [
        'picked_up',
        'in_transit',
        'delivery_attempted',
    ];

    public function assertCanStartBatch(RiderProfile $rider, DeliveryBatch $batch): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = $this->activeBatchQuery($rider)
            ->whereKeyNot($batch->id)
            ->exists();

        $this->reject($hasBatch
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

        $this->reject($hasBatch || $hasStandalone || $this->custodyHoldQuery($rider)->exists());
    }

    public function assertCanAdvanceLeg(RiderProfile $rider, ShipmentLeg $leg): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        if ($leg->rider_progress_state !== RiderProgressState::ACTIVE) {
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
                ->whereNotIn('status', ['delivered', 'cancelled', 'failed'])
                ->whereHas('latestAssignment', fn ($assignments) => $assignments
                    ->where('rider_profile_id', $rider->id)
                    ->whereIn('status', ['assigned', 'accepted'])));
    }

    private function firstActiveBatchLeg(int $batchId, int $riderId): ?ShipmentLeg
    {
        return ShipmentLeg::query()
            ->where('delivery_batch_id', $batchId)
            ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
            ->whereNotIn('status', ['delivered', 'cancelled', 'failed'])
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('rider_profile_id', $riderId)
                ->whereIn('status', ['assigned', 'accepted']))
            ->orderByRaw('stop_sequence IS NULL')
            ->orderBy('stop_sequence')
            ->orderBy('id')
            ->first();
    }

    private function custodyHoldQuery(RiderProfile $rider)
    {
        return ShipmentLeg::query()
            ->where('status', 'needs_resolution')
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('rider_profile_id', $rider->id)
                ->where('status', 'accepted'));
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
