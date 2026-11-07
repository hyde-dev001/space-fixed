<?php

namespace App\Models\Logistics;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id', 'rider_profile_id', 'delivery_date', 'delivery_window', 'status',
        'capacity', 'assigned_stop_count', 'offered_at', 'accepted_at', 'rejected_at',
        'started_at', 'completed_at', 'cancelled_at', 'rejection_reason', 'cancellation_reason',
        'cancelled_stops', 'stop_snapshot', 'dispatcher_override_reason',
        'active_incident_id',
    ];

    protected $attributes = ['status' => 'draft', 'capacity' => 0, 'assigned_stop_count' => 0];
    protected $casts = [
        'delivery_date' => 'date', 'offered_at' => 'datetime', 'accepted_at' => 'datetime',
        'rejected_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'cancelled_at' => 'datetime', 'capacity' => 'integer', 'assigned_stop_count' => 'integer',
        'cancelled_stops' => 'array', 'stop_snapshot' => 'array',
    ];

    public function shopOwner() { return $this->belongsTo(ShopOwner::class); }
    public function riderProfile() { return $this->belongsTo(RiderProfile::class); }
    public function legs() { return $this->hasMany(ShipmentLeg::class)->orderBy('stop_sequence'); }
    public function activeIncident() { return $this->belongsTo(DeliveryIncident::class, 'active_incident_id'); }
    public function incidents() { return $this->hasMany(DeliveryIncident::class, 'delivery_batch_id'); }
    public function recoveryIncident() { return $this->hasOne(DeliveryIncident::class, 'recovery_batch_id'); }
}
