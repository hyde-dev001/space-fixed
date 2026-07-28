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

        $this->reject($hasBatch || $this->activeStandaloneQuery($rider)->exists());
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

        $this->reject($hasBatch || $hasStandalone);
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

    private function reject(bool $blocked): void
    {
        if ($blocked) {
            throw ValidationException::withMessages([
                'active_work' => 'Finish your current delivery before starting another.',
            ]);
        }
    }
}
