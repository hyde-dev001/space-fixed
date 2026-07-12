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
    public function __construct(private DeliveryEventService $events) {}

    public function report(ShipmentLeg $leg, RiderProfile $rider, array $data): DeliveryIncident
    {
        if (!in_array($data['type'] ?? null, ['damaged', 'lost', 'vehicle_problem', 'customer_dispute', 'other'], true)
            || !filled($data['notes'] ?? null) || empty($data['photo_paths'])) {
            throw ValidationException::withMessages(['incident' => 'Incident type, notes, and evidence are required.']);
        }
        return DB::transaction(function () use ($leg, $rider, $data) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if (!$leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()
                || $rider->shop_owner_id !== $leg->shipment->shop_owner_id) abort(403);
            $existing = $leg->incidents()->where('type', $data['type'])->whereIn('status', ['reported', 'under_review'])->first();
            if ($existing) return $existing;
            $incident = $leg->incidents()->create([
                'shop_owner_id' => $leg->shipment->shop_owner_id, 'reporting_rider_profile_id' => $rider->id,
                'type' => $data['type'], 'notes' => $data['notes'], 'photo_paths' => $data['photo_paths'],
            ]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_reported', 'message' => 'A delivery incident requires review.', 'metadata' => ['incident_id' => $incident->id, 'type' => $incident->type]]);
            return $incident;
        });
    }

    public function resolve(DeliveryIncident $incident, ShopOwner $shop, string $resolution, string $note, array $evidence = []): DeliveryIncident
    {
        return DB::transaction(function () use ($incident, $shop, $resolution, $note, $evidence) {
            $incident = DeliveryIncident::query()->with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            if ($incident->shop_owner_id !== $shop->id) abort(403);
            if ($incident->status === 'resolved' && $incident->resolution === $resolution) return $incident;
            if ($resolution === 'loss_confirmed' && ($incident->type !== 'lost' || !filled($note) || !$evidence)) {
                throw ValidationException::withMessages(['resolution' => 'Confirmed loss requires a lost incident, investigation note, and evidence.']);
            }
            $incident->update(['status' => 'resolved', 'resolution' => $resolution, 'notes' => $note, 'photo_paths' => array_values(array_unique([...($incident->photo_paths ?? []), ...$evidence])), 'resolved_at' => now()]);
            if ($resolution === 'loss_confirmed') $incident->leg->update(['resolution_type' => 'loss_confirmed', 'resolution_reason' => $note]);
            $this->events->record($incident->leg->shipment, $incident->leg, ['event_type' => $resolution === 'loss_confirmed' ? 'loss_confirmed' : 'delivery_incident_resolved', 'message' => $resolution === 'loss_confirmed' ? 'Parcel loss was confirmed after investigation.' : 'Delivery incident resolved.']);
            return $incident->fresh();
        });
    }
}
