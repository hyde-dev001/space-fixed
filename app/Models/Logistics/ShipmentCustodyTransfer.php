<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentCustodyTransfer extends Model
{
    protected $fillable = [
        'shop_owner_id', 'shipment_leg_id', 'delivery_assignment_id', 'request_key',
        'from_type', 'from_rider_profile_id', 'to_type', 'to_rider_profile_id', 'status',
        'requested_by_user_id', 'released_by_user_id', 'received_by_user_id',
        'release_proof_id', 'receipt_proof_id', 'released_at', 'received_at',
    ];

    protected $attributes = ['status' => 'requested'];

    protected $casts = ['released_at' => 'datetime', 'received_at' => 'datetime'];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }

    public function fromRider(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class, 'from_rider_profile_id');
    }

    public function toRider(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class, 'to_rider_profile_id');
    }
}
