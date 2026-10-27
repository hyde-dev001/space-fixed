<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryIncident extends Model
{
    use HasFactory;

    protected $fillable = ['shop_owner_id', 'shipment_leg_id', 'reporting_rider_profile_id', 'type', 'status', 'photo_paths', 'notes', 'resolution', 'responsible_party', 'resolved_at', 'interrupted_state', 'resolution_note'];
    protected $casts = ['photo_paths' => 'array', 'resolved_at' => 'datetime', 'interrupted_state' => 'array', 'decided_at' => 'datetime', 'revision' => 'integer', 'recovery_context' => 'array'];
    protected $hidden = ['photo_paths', 'interrupted_state', 'request_facts_hash', 'decision_facts_hash', 'recovery_context'];
    protected $attributes = ['revision' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $incident): void {
            if ((bool) $incident->shipment_leg_id === (bool) $incident->delivery_batch_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['incident' => 'An incident must have exactly one delivery or batch subject.']);
            }
        });
    }

    public function batch() { return $this->belongsTo(DeliveryBatch::class, 'delivery_batch_id'); }
    public function recoveryBatch() { return $this->belongsTo(DeliveryBatch::class, 'recovery_batch_id'); }
    public function affectedStops() { return $this->hasMany(DeliveryIncidentStop::class)->orderBy('original_sequence')->orderBy('id'); }
    public function leg() { return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id'); }
    public function reportingRider() { return $this->belongsTo(RiderProfile::class, 'reporting_rider_profile_id'); }

    public function evidencePaths(): array
    {
        $paths = $this->photo_paths ?? [];
        $events = $this->leg?->events ?? collect();
        foreach ($events->where('metadata.incident_id', $this->id)->sortBy('id') as $event) {
            $paths = [...$paths, ...($event->metadata['evidence_paths'] ?? [])];
            if ($path = $event->metadata['evidence_path'] ?? null) $paths[] = $path;
        }
        return array_values(array_unique([...$paths, ...data_get($this->recovery_context, 'evidence_paths', []),
            ...array_filter([data_get($this->recovery_context, 'receipt.evidence_path'), data_get($this->recovery_context, 'inspection.evidence_path'),
                data_get($this->recovery_context, 'investigation.evidence_path')])]));
    }
}
