<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryIncidentStop extends Model
{
    protected $fillable = ['delivery_incident_id', 'shipment_leg_id', 'original_sequence', 'original_assignment_id', 'snapshot', 'held', 'outcome'];
    protected $casts = ['snapshot' => 'array', 'held' => 'boolean', 'original_sequence' => 'integer'];
    protected $attributes = ['held' => true];
    protected $hidden = ['snapshot'];

    public function incident(): BelongsTo { return $this->belongsTo(DeliveryIncident::class, 'delivery_incident_id'); }
    public function leg(): BelongsTo { return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id'); }
}
