<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryIncidentService
{
    public const RESOLUTIONS = [
        'dismissed',
        'retry',
        'return_required',
        'loss_confirmed',
    ];

    public function __construct(
        private LogisticsMovementEligibility $movements,
        private DeliveryEventService $events,
        private ShipmentLegService $legs,
    ) {}

    public function report(ShipmentLeg $leg, RiderProfile $rider, array $data): DeliveryIncident
    {
        $photoPaths = $data['photo_paths'] ?? [];
        if (!in_array($data['type'] ?? null, ['damaged', 'lost', 'vehicle_problem', 'customer_dispute', 'other'], true)
            || !filled($data['notes'] ?? null)
            || !is_array($photoPaths)
            || empty($photoPaths)
            || count(array_filter($photoPaths, fn ($path) => $this->isSafeEvidencePath($path))) !== count($photoPaths)) {
            throw ValidationException::withMessages(['incident' => 'Incident type, notes, and evidence are required.']);
        }
        return DB::transaction(function () use ($leg, $rider, $data, $photoPaths) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if (!$leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()
                || $rider->shop_owner_id !== $leg->shipment->shop_owner_id) abort(403);
            $existing = $leg->incidents()
                ->whereIn('status', ['reported', 'under_review'])
                ->latest('id')
                ->first();
            if ($existing) {
                if ($existing->type === $data['type']) return $existing;

                throw ValidationException::withMessages([
                    'incident' => 'This delivery already has an incident awaiting dispatcher review.',
                ]);
            }
            $incident = $leg->incidents()->create([
                'shop_owner_id' => $leg->shipment->shop_owner_id, 'reporting_rider_profile_id' => $rider->id,
                'type' => $data['type'], 'notes' => $data['notes'], 'photo_paths' => array_values($photoPaths),
                'interrupted_state' => [
                    'status' => $leg->status->value,
                    'assignment_id' => $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->latest('id')->value('id'),
                    'resolution_type' => $leg->resolution_type,
                ],
            ]);
            $leg->update(['status' => 'needs_resolution']);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_reported', 'message' => 'A delivery incident requires review.', 'metadata' => ['incident_id' => $incident->id, 'type' => $incident->type]]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_reported', 'visibility' => 'customer',
                'message' => 'A delivery issue is being reviewed by the shop.', 'metadata' => ['incident_id' => $incident->id]]);
            return $incident;
        }, 3);
    }

    public function resolve(DeliveryIncident $incident, ShopOwner $shop, string $resolution, string $note, array $evidence = [], ?Authenticatable $actor = null, array $retryOptions = [], ?string $responsibleParty = null): DeliveryIncident
    {
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => 'Choose a supported incident resolution.']);
        }
        if ($responsibleParty !== null && ! in_array($responsibleParty, ['undetermined', 'customer', 'rider', 'shop', 'third_party'], true)) {
            throw ValidationException::withMessages(['responsible_party' => 'Choose an investigation finding.']);
        }
        if (! filled($note)) {
            throw ValidationException::withMessages(['note' => 'A resolution note is required.']);
        }
        if (count(array_filter($evidence, fn ($path) => $this->isSafeEvidencePath($path))) !== count($evidence)) {
            throw ValidationException::withMessages(['evidence' => 'Incident evidence must be stored by the logistics upload flow.']);
        }

        return DB::transaction(function () use ($incident, $shop, $resolution, $note, $evidence, $actor, $retryOptions, $responsibleParty) {
            ShopOwner::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $incident = DeliveryIncident::query()->with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            if ($incident->shop_owner_id !== $shop->id) abort(403);
            if ($incident->status === 'resolved' && $incident->resolution === $resolution) return $incident;
            if ($incident->status === 'resolved') {
                throw ValidationException::withMessages(['resolution' => 'A resolved incident cannot be changed.']);
            }
            if ($resolution === 'loss_confirmed' && ($incident->type !== 'lost' || !filled($note) || !$evidence)) {
                throw ValidationException::withMessages(['resolution' => 'Confirmed loss requires a lost incident, investigation note, and evidence.']);
            }
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($incident->shipment_leg_id);
            if ($resolution === 'dismissed') {
                $snapshot = $incident->interrupted_state;
                $assignmentId = $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->latest('id')->value('id');
                if (! $snapshot || $leg->status->value !== 'needs_resolution'
                    || $leg->resolution_type !== ($snapshot['resolution_type'] ?? null)
                    || (int) $assignmentId !== (int) ($snapshot['assignment_id'] ?? 0)
                    || ! in_array($snapshot['status'] ?? null, ['assigned', 'pickup_scheduled', 'picked_up', 'in_transit', 'delivery_attempted'], true)) {
                    throw ValidationException::withMessages(['incident' => 'The interrupted delivery changed or is unknown. Resolve its current state explicitly.']);
                }
                $leg->update(['status' => $snapshot['status']]);
            }
            $incident->update(['status' => 'resolved', 'resolution' => $resolution, 'resolution_note' => $note, 'responsible_party' => $responsibleParty ?? 'undetermined', 'photo_paths' => array_values(array_unique([...($incident->photo_paths ?? []), ...$evidence])), 'resolved_at' => now()]);
            match ($resolution) {
                'loss_confirmed' => $this->legs->confirmLoss($leg, $note),
                'retry' => $this->legs->resolveRetry($leg, $note, $retryOptions, $actor ?? $shop),
                'return_required' => $this->legs->requireReturn($leg, $note, $actor ?? $shop),
                default => null,
            };
            $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'delivery_incident_resolved',
                    'message' => 'Delivery incident resolved.',
                    'metadata' => ['resolution' => $resolution, 'incident_id' => $incident->id, 'resolution_note' => $note, 'responsible_party' => $responsibleParty ?? 'undetermined'],
                    'created_by_type' => ($actor ?? $shop)::class,
                    'created_by_id' => ($actor ?? $shop)->getAuthIdentifier(),
                ]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_resolved', 'visibility' => 'customer',
                'message' => 'The shop has recorded a resolution for the delivery issue.', 'metadata' => ['incident_id' => $incident->id, 'resolution' => $resolution]]);
            return $incident->fresh();
        }, 3);
    }

    private function isSafeEvidencePath(mixed $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'incident-evidence/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
