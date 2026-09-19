<?php

namespace App\Models;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CodCollection extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CASH_COLLECTED = 'cash_collected';
    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'shop_owner_id',
        'order_id',
        'shipment_id',
        'shipment_leg_id',
        'rider_profile_id',
        'rider_user_id',
        'expected_amount',
        'collected_amount',
        'status',
        'collection_reference',
        'collection_idempotency_key',
        'collected_at',
        'collected_by_user_id',
        'settled_at',
        'settled_by_user_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'collected_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function shipmentLeg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class);
    }

    public function riderProfile(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function riderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_user_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by_user_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function remittanceItem(): HasOne
    {
        return $this->hasOne(CodRemittanceItem::class);
    }
}
