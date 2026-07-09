<?php

namespace App\Models\Logistics;

use App\Enums\Logistics\ShipmentStatus;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'source_type',
        'source_id',
        'purpose',
        'status',
        'requested_by_type',
        'requested_by_id',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'status' => ShipmentStatus::class,
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(ShipmentLeg::class)->orderBy('sequence');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class)->orderBy('created_at');
    }
}
