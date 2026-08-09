<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Validation\ValidationException;

final class RiderActiveWorkGuard
{
    private const ACTIVE_LEG_STATUSES = [
        'picked_up',
        'in_transit',
        'delivery_attempted',
        'awaiting_proof_approval',
    ];

    public function assertCanStartBatch(RiderProfile $rider, DeliveryBatch $batch): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = DeliveryBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'in_progress')
            ->whereKeyNot($batch->id)
            ->exists();

        $this->reject($hasBatch
            || $this->activeStandaloneQuery($rider)->exists()
            || $this->custodyHoldQuery($rider)->exists());
    }

    public function assertCanStartStandalone(RiderProfile $rider, ShipmentLeg $leg): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = DeliveryBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'in_progress')
            ->exists();

        $hasStandalone = $this->activeStandaloneQuery($rider)
            ->whereKeyNot($leg->id)
            ->exists();

        $this->reject($hasBatch || $hasStandalone || $this->custodyHoldQuery($rider)->exists());
    }

    public function assertCanAdvanceLeg(RiderProfile $rider, ShipmentLeg $leg): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $candidates = DeliveryBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'in_progress')
            ->get(['id', 'started_at'])
            ->map(fn (DeliveryBatch $batch) => [
                'key' => "batch:{$batch->id}",
                'started_at' => $batch->started_at?->format('Y-m-d H:i:s.u') ?? '9999-12-31',
                'kind' => 'batch',
                'id' => $batch->id,
            ])
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
                    ])
            )
            ->sortBy(fn (array $item) => [$item['started_at'], $item['kind'], $item['id']])
            ->values();

        if ($candidates->isEmpty()) {
            return;
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
            ->whereIn('status', self::ACTIVE_LEG_STATUSES)
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('rider_profile_id', $rider->id)
                ->whereIn('status', ['assigned', 'accepted']));
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
