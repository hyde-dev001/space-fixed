<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BatchDispatchService
{
    public function __construct(private AssignmentService $assignments, private DeliveryEventService $events)
    {
    }

    public function schedule(ShopOwner $shop, string $date, string $window, array $legIds): void
    {
        $deliveryDate = Carbon::parse($date)->startOfDay();
        $settings = $shop->logisticsSetting()->firstOrCreate([]);
        if (!in_array($deliveryDate->dayOfWeekIso, $settings->operating_days, true)
            || in_array($deliveryDate->toDateString(), $settings->blackout_dates, true)) {
            throw ValidationException::withMessages(['delivery_date' => 'Choose an operating day that is not a blackout date.']);
        }

        DB::transaction(function () use ($shop, $date, $window, $legIds) {
            $legs = ShipmentLeg::query()->with('shipment')->whereIn('id', $legIds)->orderBy('id')->lockForUpdate()->get();
            if ($legs->count() !== count(array_unique($legIds)) || $legs->contains(fn ($leg) =>
                $leg->shipment->shop_owner_id !== $shop->id || $leg->delivery_batch_id
                || $leg->status->value !== 'pending' || $leg->schedule_status === 'scheduled')) {
                throw ValidationException::withMessages(['legs' => 'One or more deliveries cannot be scheduled.']);
            }
            foreach ($legs as $leg) {
                $leg->update([
                    'scheduled_delivery_date' => $date, 'delivery_window' => $window,
                    'schedule_status' => 'scheduled', 'estimated_at' => now(),
                ]);
            }
        });
    }

    public function createDraft(ShopOwner $shop, string $date, string $window, array $legIds, ?string $overrideReason = null): DeliveryBatch
    {
        return DB::transaction(function () use ($shop, $date, $window, $legIds, $overrideReason) {
            ShopOwner::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $legs = ShipmentLeg::query()->with('shipment')->whereIn('id', $legIds)->orderBy('id')->lockForUpdate()->get();
            if ($legs->count() !== count(array_unique($legIds)) || $legs->contains(fn ($leg) =>
                $leg->shipment->shop_owner_id !== $shop->id || $leg->delivery_batch_id
                || $leg->status->value !== 'pending' || $leg->schedule_status !== 'scheduled'
                || $leg->scheduled_delivery_date?->toDateString() !== $date || $leg->delivery_window !== $window)) {
                throw ValidationException::withMessages(['legs' => 'One or more legs are not eligible for this batch.']);
            }
            $capacity = $shop->logisticsSetting()->firstOrCreate([])->daily_rider_capacity;
            if ($legs->count() > $capacity && !filled($overrideReason)) {
                throw ValidationException::withMessages(['dispatcher_override_reason' => 'Capacity override reason is required.']);
            }
            $batch = DeliveryBatch::create([
                'shop_owner_id' => $shop->id, 'delivery_date' => $date, 'delivery_window' => $window,
                'capacity' => $capacity, 'assigned_stop_count' => $legs->count(),
                'dispatcher_override_reason' => $overrideReason,
            ]);
            foreach ($legIds as $index => $id) {
                $legs->firstWhere('id', $id)->update(['delivery_batch_id' => $batch->id, 'stop_sequence' => $index + 1]);
            }
            return $batch->fresh('legs');
        });
    }

    public function offer(DeliveryBatch $batch, RiderProfile $rider, ShopOwner $actor): DeliveryBatch
    {
        return DB::transaction(function () use ($batch, $rider, $actor) {
            $batch = DeliveryBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'offered' && $batch->rider_profile_id === $rider->id) {
                return $batch->load('legs.assignments');
            }
            if ($batch->status !== 'draft' || $rider->shop_owner_id !== $batch->shop_owner_id) {
                throw ValidationException::withMessages(['batch' => 'Batch cannot be offered to this rider.']);
            }
            $date = $batch->delivery_date;
            if (!$rider->active || $rider->availability_status !== 'available'
                || ($rider->work_days && !in_array($date->dayOfWeekIso, $rider->work_days, true))
                || in_array($date->toDateString(), $rider->leave_dates ?? [], true)) {
                throw ValidationException::withMessages(['rider_profile_id' => 'Rider is unavailable on this delivery date.']);
            }
            foreach ($batch->legs()->orderBy('id')->lockForUpdate()->get() as $leg) {
                $this->assignments->assignInternalRider($leg, $rider, $actor, ['delivery_batch_id' => $batch->id]);
            }
            $batch->update(['rider_profile_id' => $rider->id, 'status' => 'offered', 'offered_at' => now()]);
            $this->recordBatchEvent($batch, 'batch_offered', 'Delivery batch offered.', [
                'rider_profile_id' => $rider->id,
                'stop_count' => $batch->assigned_stop_count,
            ]);
            return $batch->fresh('legs.assignments');
        });
    }

    public function replaceStops(DeliveryBatch $batch, array $legIds): DeliveryBatch
    {
        return DB::transaction(function () use ($batch, $legIds) {
            $batch = DeliveryBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'draft' || count($legIds) !== count(array_unique($legIds))) {
                throw ValidationException::withMessages(['legs' => 'Only draft batches may be reordered.']);
            }
            $legs = ShipmentLeg::query()->whereIn('id', $legIds)->orderBy('id')->lockForUpdate()->get();
            if ($legs->count() !== count($legIds) || $legs->contains(fn ($leg) => $leg->delivery_batch_id !== $batch->id)) {
                throw ValidationException::withMessages(['legs' => 'Every stop must belong to this batch.']);
            }
            foreach ($legIds as $index => $id) {
                $legs->firstWhere('id', $id)->update(['stop_sequence' => $index + 1]);
            }
            return $batch->fresh(['legs' => fn ($query) => $query->orderBy('stop_sequence')]);
        });
    }

    public function removeStop(DeliveryBatch $batch, ShipmentLeg $leg): ?DeliveryBatch
    {
        return DB::transaction(function () use ($batch, $leg) {
            $batch = DeliveryBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $leg = ShipmentLeg::query()->lockForUpdate()->findOrFail($leg->id);
            if ($batch->status !== 'draft' || $leg->delivery_batch_id !== $batch->id) {
                throw ValidationException::withMessages(['leg' => 'Stop does not belong to this draft.']);
            }
            $leg->update(['delivery_batch_id' => null, 'stop_sequence' => null]);
            $remaining = $batch->legs()->orderBy('stop_sequence')->get();
            if ($remaining->isEmpty()) {
                $batch->delete();
                return null;
            }
            foreach ($remaining as $index => $remainingLeg) $remainingLeg->update(['stop_sequence' => $index + 1]);
            $batch->update(['assigned_stop_count' => $remaining->count()]);
            return $batch->fresh('legs');
        });
    }

    public function markUrgent(ShipmentLeg $leg, bool $urgent): ShipmentLeg
    {
        if (in_array($leg->status->value, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['leg' => 'Delivered or cancelled stops can no longer be changed.']);
        }
        $leg->update(['urgent_at' => $urgent ? now() : null]);
        return $leg->fresh();
    }

    public function accept(DeliveryBatch $batch, RiderProfile $rider): DeliveryBatch
    {
        if ($batch->fresh()->status === 'accepted' && $batch->rider_profile_id === $rider->id) return $batch->fresh('legs.assignments');
        return $this->riderTransition($batch, $rider, 'offered', function ($locked) use ($rider) {
            $locked->legs()->each(fn ($leg) => $leg->assignments()->where('rider_profile_id', $rider->id)
                ->where('status', 'assigned')->update(['status' => 'accepted', 'accepted_at' => now()]));
            $locked->update(['status' => 'accepted', 'accepted_at' => now()]);
            $this->recordBatchEvent($locked, 'batch_accepted', 'Delivery batch accepted.');
        });
    }

    public function reject(DeliveryBatch $batch, RiderProfile $rider, string $reason): DeliveryBatch
    {
        if (!filled($reason)) {
            throw ValidationException::withMessages(['rejection_reason' => 'Rejection reason is required.']);
        }
        return $this->riderTransition($batch, $rider, 'offered', function ($locked) use ($rider, $reason) {
            $locked->legs()->each(fn ($leg) => $leg->assignments()->where('rider_profile_id', $rider->id)
                ->where('status', 'assigned')->update(['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_at' => now()]));
            $locked->update([
                'status' => 'draft', 'rider_profile_id' => null, 'rejection_reason' => $reason,
                'rejected_at' => now(), 'offered_at' => null,
            ]);
            $this->recordBatchEvent($locked, 'batch_rejected', 'Delivery batch rejected.');
        });
    }

    public function start(DeliveryBatch $batch, RiderProfile $rider): DeliveryBatch
    {
        if ($batch->fresh()->status === 'in_progress' && $batch->rider_profile_id === $rider->id) return $batch->fresh('legs.assignments');
        return $this->riderTransition($batch, $rider, 'accepted', function ($locked) {
            $locked->update(['status' => 'in_progress', 'started_at' => now()]);
            $this->recordBatchEvent($locked, 'batch_started', 'Delivery batch started.');
        });
    }

    public function cancel(DeliveryBatch $batch, string $reason): DeliveryBatch
    {
        if (!filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Cancellation reason is required.']);
        }
        return DB::transaction(function () use ($batch, $reason) {
            $batch = DeliveryBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'cancelled') return $batch->load('legs');
            if (!in_array($batch->status, ['draft', 'offered', 'accepted'], true)) {
                throw ValidationException::withMessages(['batch' => 'Only a batch that has not started may be cancelled.']);
            }
            $batch->legs()->each(function ($leg) {
                $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                if (!in_array($leg->status->value, ['delivered', 'cancelled'], true)) {
                    $leg->update(['delivery_batch_id' => null, 'stop_sequence' => null, 'status' => 'pending']);
                }
            });
            $batch->update(['status' => 'cancelled', 'cancelled_at' => now(), 'dispatcher_override_reason' => $reason]);
            $this->recordBatchEvent($batch, 'batch_cancelled', 'Delivery batch cancelled.');
            return $batch->fresh('legs');
        });
    }

    private function riderTransition(DeliveryBatch $batch, RiderProfile $rider, string $from, callable $change): DeliveryBatch
    {
        return DB::transaction(function () use ($batch, $rider, $from, $change) {
            $batch = DeliveryBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->rider_profile_id !== $rider->id || $batch->status !== $from) {
                throw ValidationException::withMessages(['batch' => 'Batch action is stale or not assigned to this rider.']);
            }
            $change($batch);
            return $batch->fresh('legs.assignments');
        });
    }

    private function recordBatchEvent(DeliveryBatch $batch, string $type, string $message, array $metadata = []): void
    {
        $leg = $batch->legs()->with('shipment')->orderBy('stop_sequence')->first();
        if ($leg) $this->events->record($leg->shipment, $leg, [
            'event_type' => $type,
            'message' => $message,
            'metadata' => ['delivery_batch_id' => $batch->id] + $metadata,
        ]);
    }
}
