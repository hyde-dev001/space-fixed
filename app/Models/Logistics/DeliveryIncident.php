<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryIncident extends Model
{
    use HasFactory;

    protected $fillable = ['shop_owner_id', 'shipment_leg_id', 'reporting_rider_profile_id', 'type', 'status', 'photo_paths', 'notes', 'resolution', 'responsible_party', 'resolved_at'];
    protected $casts = ['photo_paths' => 'array', 'resolved_at' => 'datetime'];
    public function leg() { return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id'); }
    public function reportingRider() { return $this->belongsTo(RiderProfile::class, 'reporting_rider_profile_id'); }
}
