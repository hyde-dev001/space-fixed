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

    public function linkedRecovery(): ?self
    {
        if ($this->type !== 'lost') return null;
        if ($this->leg?->relationLoaded('incidents')) {
            return $this->leg->incidents->filter(fn ($row) => (int) data_get($row->recovery_context, 'recovered_loss.parent_id') === (int) $this->id)
                ->sortBy(fn ($row) => $row->status === 'resolved')->first();
        }
        return self::where('shop_owner_id', $this->shop_owner_id)->where('shipment_leg_id', $this->shipment_leg_id)
            ->where('recovery_context->recovered_loss->parent_id', $this->id)->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")->orderBy('id')->first();
    }

    public function lossStage(): ?string
    {
        return data_get($this->recovery_context, 'loss.stage')
            ?? ($this->type === 'lost' && ! $this->decided_at && $this->status !== 'resolved' ? 'reported' : null);
    }

    public function lossWorkflow(?\App\Models\User $actor): ?array
    {
        $child = $this->linkedRecovery();
        $subject = $child ?? $this;
        $recovery = data_get($subject->recovery_context, 'recovered_loss');
        if ($this->type !== 'lost' && ! $recovery) return null;
        $stage = $recovery ? $recovery['stage'] : $this->lossStage();
        $sameShop = $actor && (int) $actor->shop_owner_id === (int) $this->shop_owner_id;
        $reporter = $sameShop && $this->reportingRider?->linked_type === \App\Models\User::class
            && (int) $this->reportingRider?->linked_id === (int) $actor->id;
        $dispatcher = $sameShop && $actor->can('resolve-logistics-exceptions');
        $actions = [];
        if (! $recovery) {
            if ($stage === 'reported' && $dispatcher) $actions[] = 'acknowledge';
            if ($stage === 'acknowledged' && $reporter) $actions[] = 'confirm';
            if ($stage === 'confirmation_requested' && $dispatcher) $actions[] = 'approve';
            if ($this->type === 'lost' && $reporter && ($this->status !== 'resolved' || $this->resolution === 'loss_confirmed')) $actions[] = 'report_recovery';
        } elseif (! $subject->decided_at && ! ($recovery['confirmed'] ?? false)) {
            $stage = 'awaiting_rider_confirmation';
            if ($reporter) $actions[] = 'confirm_recovery';
        } elseif (! $subject->decided_at && $dispatcher) {
            $actions[] = 'return';
            if (! $recovery['after_final'] && $recovery['condition'] === 'safe') $actions[] = 'continue';
        }
        $reviewReason = null;
        if ($recovery) {
            $siblings = $this->leg?->relationLoaded('incidents') ? $this->leg->incidents
                : self::where('shop_owner_id', $this->shop_owner_id)->where('shipment_leg_id', $this->shipment_leg_id)->get();
            $parentId = (int) $recovery['parent_id'];
            if (! $siblings->contains(fn ($row) => (int) $row->id === $parentId && $row->type === 'lost')
                || $siblings->filter(fn ($row) => (int) data_get($row->recovery_context, 'recovered_loss.parent_id') === $parentId && $row->status !== 'resolved')->count() > 1) {
                $stage = 'manual_review'; $actions = []; $reviewReason = 'Historical recovery links are ambiguous. Dispatcher review is required; no acknowledgement or receipt is inferred.';
            }
        }
        return ['stage' => $stage ?? 'legacy_review', 'actions' => $actions, 'review_reason' => $reviewReason,
            'parent_id' => $recovery['parent_id'] ?? null, 'after_final' => (bool) ($recovery['after_final'] ?? false),
            'action_incident_id' => $subject->id, 'action_revision' => $subject->revision,
            'recovery' => $recovery ? ['parent_id' => $recovery['parent_id'], 'condition' => $recovery['condition'],
                'notes' => $subject->notes, 'reported_at' => $recovery['reported_at'] ?? null,
                'confirmed' => (bool) ($recovery['confirmed'] ?? false), 'status' => $subject->status] : null,
            'reporting_rider_profile_id' => $this->reporting_rider_profile_id];
    }

    public function evidencePaths(): array
    {
        $paths = $this->photo_paths ?? [];
        if ($child = $this->linkedRecovery()) {
            $child->setRelation('leg', $this->leg);
            $paths = [...$paths, ...$child->evidencePaths()];
            $child->unsetRelation('leg');
        }
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
