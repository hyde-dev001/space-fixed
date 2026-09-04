<?php

namespace App\Models\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Enums\Logistics\ShipmentLegStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShipmentLeg extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'shipping_method_id',
        'sequence',
        'leg_type',
        'status',
        'rider_progress_state',
        'origin_snapshot',
        'destination_snapshot',
        'tracking_number',
        'tracking_url',
        'provider_status',
        'scheduled_pickup_at',
        'picked_up_at',
        'delivered_at',
        'failed_at',
        'requires_pickup_proof',
        'requires_delivery_proof',
        'scheduled_delivery_date',
        'delivery_window',
        'schedule_status',
        'schedule_override_reason',
        'distance_km',
        'estimated_at',
        'delivery_batch_id', 'stop_sequence', 'attempt_number', 'out_for_delivery_at', 'resolution_type', 'resolution_reason', 'return_for_leg_id', 'urgent_at',
    ];

    protected $casts = [
        'status' => ShipmentLegStatus::class,
        'rider_progress_state' => RiderProgressState::class,
        'origin_snapshot' => 'array',
        'destination_snapshot' => 'array',
        'scheduled_pickup_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'requires_pickup_proof' => 'boolean',
        'requires_delivery_proof' => 'boolean',
        'scheduled_delivery_date' => 'date',
        'distance_km' => 'decimal:2',
        'estimated_at' => 'datetime',
        'stop_sequence' => 'integer', 'attempt_number' => 'integer', 'out_for_delivery_at' => 'datetime', 'urgent_at' => 'datetime',
    ];

    protected $attributes = [
        'rider_progress_state' => RiderProgressState::ACTIVE->value,
    ];

    protected static function booted(): void
    {
        static::updated(function (ShipmentLeg $leg): void {
            if ($leg->status?->value !== ShipmentLegStatus::IN_TRANSIT->value) {
                // Tracking is delivery-scoped; pickup, proof review, failures, retries, and terminal states hide the last point.
                $leg->currentLocation()->delete();
            }
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    public function currentLocation(): HasOne
    {
        return $this->hasOne(RiderCurrentLocation::class);
    }

    public function latestAssignment(): HasOne
    {
        return $this->hasOne(DeliveryAssignment::class)->latestOfMany();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(HandoffProof::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class);
    }

    public function deliveryBatch(): BelongsTo
    {
        return $this->belongsTo(DeliveryBatch::class);
    }

    public function returnForLeg(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_for_leg_id');
    }

    public function returnLeg(): HasOne
    {
        return $this->hasOne(self::class, 'return_for_leg_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(DeliveryIncident::class);
    }
}
