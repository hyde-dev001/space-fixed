<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
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
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if (!$leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()
                || $rider->shop_owner_id !== $leg->shipment->shop_owner_id) abort(403);
            $existing = $leg->incidents()->where('type', $data['type'])->whereIn('status', ['reported', 'under_review'])->first();
            if ($existing) return $existing;
            $incident = $leg->incidents()->create([
                'shop_owner_id' => $leg->shipment->shop_owner_id, 'reporting_rider_profile_id' => $rider->id,
                'type' => $data['type'], 'notes' => $data['notes'], 'photo_paths' => array_values($photoPaths),
            ]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_reported', 'message' => 'A delivery incident requires review.', 'metadata' => ['incident_id' => $incident->id, 'type' => $incident->type]]);
            return $incident;
        });
    }

    public function resolve(DeliveryIncident $incident, ShopOwner $shop, string $resolution, string $note, array $evidence = []): DeliveryIncident
    {
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => 'Choose a supported incident resolution.']);
        }
        if (! filled($note)) {
            throw ValidationException::withMessages(['note' => 'A resolution note is required.']);
        }
        if (count(array_filter($evidence, fn ($path) => $this->isSafeEvidencePath($path))) !== count($evidence)) {
            throw ValidationException::withMessages(['evidence' => 'Incident evidence must be stored by the logistics upload flow.']);
        }

        return DB::transaction(function () use ($incident, $shop, $resolution, $note, $evidence) {
            $incident = DeliveryIncident::query()->with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            if ($incident->shop_owner_id !== $shop->id) abort(403);
            if ($incident->status === 'resolved' && $incident->resolution === $resolution) return $incident;
            if ($incident->status === 'resolved') {
                throw ValidationException::withMessages(['resolution' => 'A resolved incident cannot be changed.']);
            }
            if ($resolution === 'loss_confirmed' && ($incident->type !== 'lost' || !filled($note) || !$evidence)) {
                throw ValidationException::withMessages(['resolution' => 'Confirmed loss requires a lost incident, investigation note, and evidence.']);
            }
            $incident->update(['status' => 'resolved', 'resolution' => $resolution, 'notes' => $note, 'photo_paths' => array_values(array_unique([...($incident->photo_paths ?? []), ...$evidence])), 'resolved_at' => now()]);
            match ($resolution) {
                'loss_confirmed' => $this->legs->confirmLoss($incident->leg, $note),
                'retry' => $this->legs->resolveRetry($incident->leg, $note),
                'return_required' => $this->legs->requireReturn($incident->leg, $note),
                default => $this->events->record($incident->leg->shipment, $incident->leg, [
                    'event_type' => 'delivery_incident_resolved',
                    'message' => 'Delivery incident resolved.',
                    'metadata' => ['resolution' => $resolution, 'incident_id' => $incident->id],
                ]),
            };
            return $incident->fresh();
        });
    }

    private function isSafeEvidencePath(mixed $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'incident-evidence/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
