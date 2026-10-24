<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'shipment_leg_id',
        'event_type',
        'visibility',
        'message',
        'metadata',
        'created_by_type',
        'created_by_id',
        'notification_requested_at', 'notifications_processed_at', 'notification_attempts', 'notification_last_error',
    ];

    protected $casts = [
        'metadata' => 'array',
        'notification_requested_at' => 'datetime', 'notifications_processed_at' => 'datetime', 'notification_attempts' => 'integer',
    ];

    protected $attributes = ['notification_attempts' => 0];

    protected $hidden = ['notification_requested_at', 'notifications_processed_at', 'notification_attempts', 'notification_last_error'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }
}
