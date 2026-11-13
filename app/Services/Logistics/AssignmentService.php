<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\CarrierType;
use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\HR\EmployeeOperationalPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(
        private DeliveryEventService $events,
        private EmployeeOperationalPolicy $employeePolicy,
        private LogisticsMovementEligibility $movements,
        private RiderActiveWorkGuard $activeWork,
    )
    {
    }

    public function assignInternalRider(ShipmentLeg $leg, RiderProfile $rider, ShopOwner $actor, array $eventMetadata = [], ?Authenticatable $operator = null): DeliveryAssignment
    {
        $leg->loadMissing('shipment');

        if ((int) $rider->shop_owner_id !== (int) $leg->shipment->shop_owner_id) {
            throw ValidationException::withMessages(['rider_profile_id' => 'Rider does not belong to this shop.']);
        }

        if (!$rider->active || $rider->availability_status === 'inactive') {
            throw ValidationException::withMessages(['rider_profile_id' => 'Rider is not available for assignment.']);
        }

        if ($rider->rider_type === 'employee'
            && ($rider->linked_type !== User::class || ! $rider->linked_id)) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'Employee rider must have a linked user account before work is assigned.',
            ]);
        }

        $this->assertEmployeeRiderEligible($rider, (int) $leg->shipment->shop_owner_id);

        if ($rider->rider_type === 'shop_owner' && strtolower((string) $actor->registration_type) !== 'individual') {
            throw ValidationException::withMessages(['rider_profile_id' => 'Owner delivery is only allowed for individual shops.']);
        }

        return DB::transaction(function () use ($leg, $rider, $actor, $eventMetadata, $operator) {
            $shop = $this->movements->lockShopForLeg($leg);
            $this->movements->assertCanStart($shop);
            $leg = ShipmentLeg::query()
                ->with(['shipment', 'shippingMethod'])
                ->lockForUpdate()
                ->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            $rider = RiderProfile::query()->lockForUpdate()->findOrFail($rider->id);
            $this->assertEligibleForDate($rider, $shop, $leg->scheduled_delivery_date?->toDateString() ?? now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(), $leg->id, $eventMetadata['capacity_override_reason'] ?? null);

            $this->movements->assertInternalLeg($leg);

            if ((int) $rider->shop_owner_id !== (int) $leg->shipment->shop_owner_id) {
                throw ValidationException::withMessages(['rider_profile_id' => 'Rider does not belong to this shop.']);
            }

            $this->assertEmployeeRiderEligible(
                $rider,
                (int) $leg->shipment->shop_owner_id,
                true,
            );

            $method = $leg->shippingMethod;
            if ($method !== null
                && (! $method->active
                    || $method->carrier_type !== CarrierType::INTERNAL
                    || ! $method->requires_assignment
                    || ($method->shop_owner_id !== null
                        && (int) $method->shop_owner_id !== (int) $leg->shipment->shop_owner_id))) {
                throw ValidationException::withMessages([
                    'shipping_method_id' => 'Selected shipping method is not supported by shop-owned logistics.',
                ]);
            }

            if (in_array($leg->status->value, ['needs_resolution', 'proof_correction_required', 'delivered', 'cancelled'], true)) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'Only retryable delivery legs can be assigned.']);
            }

            if ($leg->assignments()->whereIn('status', ['assigned', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'This leg already has an active assignment.']);
            }

            $assignment = DeliveryAssignment::create([
                'shipment_leg_id' => $leg->id,
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
                'assigned_by_type' => ($operator ?? $actor)::class,
                'assigned_by_id' => ($operator ?? $actor)->getAuthIdentifier(),
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            $leg->update(['status' => 'assigned']);
            $leg->shipment->update(['status' => 'active']);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'leg_assigned',
                'visibility' => 'internal',
                'message' => "Assigned to {$rider->name}.",
                'metadata' => ['rider_profile_id' => $rider->id, 'delivery_assignment_id' => $assignment->id] + $eventMetadata,
                'created_by_type' => ($operator ?? $actor)::class,
                'created_by_id' => ($operator ?? $actor)->getAuthIdentifier(),
            ]);

            return $assignment;
        });
    }

    public function assertEligibleForDate(RiderProfile $rider, ShopOwner $shop, string $date, ?int $excludeLegId = null, ?string $overrideReason = null, bool $checkCapacity = true): void
    {
        if ($rider->rider_type === 'employee' && ($rider->linked_type !== User::class || ! $rider->linked_id)) {
            throw ValidationException::withMessages(['rider_profile_id' => 'Employee rider must have a linked user account.']);
        }
        $date = CarbonImmutable::parse($date, config('app.shop_timezone', 'Asia/Manila'));
        $this->assertEmployeeRiderEligible($rider, (int) $shop->id, true);
        $employeeId = $rider->linked_type === User::class
            ? Employee::where('shop_owner_id', $shop->id)->whereRaw('LOWER(email) = ?', [strtolower((string) User::find($rider->linked_id)?->email)])->value('id') : null;
        $onLeave = $employeeId && LeaveRequest::where('employee_id', $employeeId)->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists();
        if ((int) $rider->shop_owner_id !== (int) $shop->id || ! $rider->active || $rider->availability_status !== 'available'
            || ($rider->work_days && ! in_array($date->dayOfWeekIso, $rider->work_days, true))
            || in_array($date->toDateString(), $rider->leave_dates ?? [], true) || $onLeave) {
            throw ValidationException::withMessages(['rider_profile_id' => 'Rider is unavailable on this delivery date.']);
        }
        if (! $checkCapacity) return;
        $capacity = $rider->daily_capacity ?? $shop->logisticsSetting?->daily_rider_capacity ?? 20;
        $existing = ShipmentLeg::whereHas('shipment', fn ($query) => $query->where('shop_owner_id', $shop->id))
            ->whereDate('scheduled_delivery_date', $date->toDateString())
            ->when($excludeLegId, fn ($query) => $query->whereKeyNot($excludeLegId))
            ->where('status', '!=', 'cancelled')
            ->whereHas('latestAssignment', fn ($query) => $query->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted', 'completed']))->lockForUpdate()->get(['id'])->count();
        if ($existing >= $capacity && ! filled($overrideReason)) {
            throw ValidationException::withMessages(['capacity_override_reason' => 'Rider capacity is full. Select another rider or record an authorized override reason.']);
        }
    }

    private function assertEmployeeRiderEligible(RiderProfile $rider, int $shopOwnerId, bool $lock = false): void
    {
        if ($rider->rider_type !== 'employee') {
            return;
        }

        $linkedUser = User::query()
            ->whereKey($rider->linked_id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->first();

        if (! $linkedUser) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'Employee rider must have a linked user account in this shop.',
            ]);
        }

        $employeeQuery = Employee::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $linkedUser->email)])
            ->orderBy('id');

        if ($lock) {
            $employeeQuery->lockForUpdate();
        }

        $employees = $employeeQuery->limit(2)->get();

        if ($employees->count() > 1) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'Employee rider identity is ambiguous in this shop.',
            ]);
        }

        $employee = $employees->first();

        // Preserve legacy rider profiles that predate a corresponding Employee row.
        if ($employee && ! $this->employeePolicy->canReceiveNewAssignment($employee)) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'Employee is not eligible for a new assignment.',
            ]);
        }
    }

    public function replaceInterruptedAssignment(ShipmentLeg $leg, RiderProfile $rider, User $actor, \App\Models\Logistics\DeliveryIncident $incident): DeliveryAssignment
    {
        $shop = $this->movements->lockShopForLeg($leg);
        $leg = ShipmentLeg::with('deliveryBatch')->lockForUpdate()->findOrFail($leg->id);
        abort_unless((int) $actor->shop_owner_id === (int) $shop->id && $actor->can('resolve-logistics-exceptions')
            && (int) $incident->shop_owner_id === (int) $shop->id, 403);
        if ((int) $leg->deliveryBatch?->active_incident_id !== (int) $incident->id
            || ! $incident->affectedStops()->where('shipment_leg_id', $leg->id)->where('held', true)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['batch_interruption' => 'A current held batch stop is required.']);
        }
        $this->movements->assertCanStart($shop);
        $this->movements->assertInternalLeg($leg);
        $this->assertEligibleForDate($rider, $shop, $leg->scheduled_delivery_date->toDateString(), $leg->id, checkCapacity: false);
        $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        return DeliveryAssignment::create(['shipment_leg_id' => $leg->id, 'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id, 'assigned_by_type' => User::class, 'assigned_by_id' => $actor->id,
            'status' => 'assigned', 'assigned_at' => now(), 'recovery_key' => (string) \Illuminate\Support\Str::uuid()]);
    }

    public function respondToOffer(ShipmentLeg $leg, RiderProfile $rider, bool $accepted, ?string $reason = null): DeliveryAssignment
    {
        if (! $accepted && ! filled($reason)) {
            throw ValidationException::withMessages(['rejection_reason' => 'Rejection reason is required.']);
        }

        return DB::transaction(function () use ($leg, $rider, $accepted, $reason) {
            $shop = $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === 'proof_correction_required') {
                throw ValidationException::withMessages([
                    'shipment_leg_id' => 'Proof-correction deliveries cannot be accepted as new work.',
                ]);
            }
            if ($leg->delivery_batch_id) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'Batch offers must be answered as a batch.']);
            }

            $assignment = DeliveryAssignment::query()
                ->where('shipment_leg_id', $leg->id)
                ->where('rider_profile_id', $rider->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $accepted
                && $assignment?->status === 'rejected'
                && $assignment->rejection_reason === $reason) {
                return $assignment;
            }

            if (! $assignment || ($assignment->status !== 'assigned' && ! ($accepted && $assignment->status === 'accepted'))) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'This delivery offer is no longer available.']);
            }

            if ($assignment->status === 'accepted' && (app(DeliveryStartEligibility::class)->hasActivated($leg)
                || ! $leg->events()->where('event_type', 'leg_offer_accepted')->where('metadata->delivery_assignment_id', $assignment->id)->exists()
                || $leg->events()->where('event_type', 'leg_offer_accepted')->where('metadata->delivery_assignment_id', $assignment->id)->latest('id')->first()?->metadata['schedule_facts'] === app(DeliveryStartEligibility::class)->scheduleFacts($leg))) {
                return $assignment;
            }

            if ($accepted) {
                $this->movements->assertCanStart($shop);
                $this->movements->assertInternalLeg($leg);
            }

            $assignment->update($accepted
                ? ['status' => 'accepted', 'accepted_at' => now()]
                : ['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_at' => now()]);

            if (! $accepted) {
                if ($leg->resolution_type === 'retry') {
                    $leg->custodyTransfers()->where('delivery_assignment_id', $assignment->id)->where('status', 'requested')->update(['status' => 'cancelled']);
                    $leg->update(['status' => 'needs_resolution', 'resolution_type' => null, 'resolution_reason' => $reason]);
                } else {
                    $leg->update(['status' => 'pending']);
                }
            }

            $this->events->record($leg->shipment, $leg, [
                'event_type' => $accepted ? 'leg_offer_accepted' : 'leg_offer_rejected',
                'visibility' => 'internal',
                'message' => $accepted ? 'Delivery offer accepted.' : 'Delivery offer rejected.',
                'metadata' => [
                    'delivery_assignment_id' => $assignment->id,
                    'schedule_facts' => app(DeliveryStartEligibility::class)->scheduleFacts($leg),
                    'rider_profile_id' => $rider->id,
                    'rejection_reason' => $accepted ? null : $reason,
                ],
            ]);

            return $assignment->fresh();
        });
    }
}
