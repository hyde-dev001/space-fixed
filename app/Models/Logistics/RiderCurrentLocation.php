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
        'route_geometry',
        'route_distance_m',
        'route_duration_s',
        'route_source',
        'route_version',
        'route_updated_at',
        'route_off_route_samples',
        'route_off_route_state',
        'route_cooldown_until',
        'route_target_latitude',
        'route_target_longitude',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_m' => 'float',
        'speed_mps' => 'float',
        'heading_deg' => 'float',
        'recorded_at' => 'datetime',
        'received_at' => 'datetime',
        'route_geometry' => 'array',
        'route_distance_m' => 'float',
        'route_duration_s' => 'integer',
        'route_version' => 'integer',
        'route_updated_at' => 'datetime',
        'route_off_route_samples' => 'integer',
        'route_cooldown_until' => 'datetime',
        'route_target_latitude' => 'float',
        'route_target_longitude' => 'float',
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
