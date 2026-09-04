<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderCurrentLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_leg_id',
        'rider_profile_id',
        'delivery_assignment_id',
        'latitude',
        'longitude',
        'accuracy_m',
        'speed_mps',
        'heading_deg',
        'recorded_at',
        'received_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_m' => 'float',
        'speed_mps' => 'float',
        'heading_deg' => 'float',
        'recorded_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }

    public function riderProfile(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function deliveryAssignment(): BelongsTo
    {
        return $this->belongsTo(DeliveryAssignment::class);
    }
}
