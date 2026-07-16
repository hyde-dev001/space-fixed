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
        'cancelled_stops', 'dispatcher_override_reason',
    ];

    protected $attributes = ['status' => 'draft', 'capacity' => 0, 'assigned_stop_count' => 0];
    protected $casts = [
        'delivery_date' => 'date', 'offered_at' => 'datetime', 'accepted_at' => 'datetime',
        'rejected_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'cancelled_at' => 'datetime', 'capacity' => 'integer', 'assigned_stop_count' => 'integer',
        'cancelled_stops' => 'array',
    ];

    public function shopOwner() { return $this->belongsTo(ShopOwner::class); }
    public function riderProfile() { return $this->belongsTo(RiderProfile::class); }
    public function legs() { return $this->hasMany(ShipmentLeg::class)->orderBy('stop_sequence'); }
}
