<?php

namespace App\Models\Logistics;

use App\Enums\Logistics\CarrierType;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'code',
        'name',
        'carrier_type',
        'requires_assignment',
        'requires_tracking',
        'requires_pickup_proof',
        'requires_delivery_proof',
        'active',
    ];

    protected $casts = [
        'carrier_type' => CarrierType::class,
        'requires_assignment' => 'boolean',
        'requires_tracking' => 'boolean',
        'requires_pickup_proof' => 'boolean',
        'requires_delivery_proof' => 'boolean',
        'active' => 'boolean',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(ShipmentLeg::class);
    }
}
