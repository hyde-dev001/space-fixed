<?php

namespace App\Models\Logistics;

use App\Enums\Logistics\ShipmentStatus;
use App\Models\ShopOwner;
use App\Models\DeliveryDispute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_number',
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
        'shipment_number' => 'integer',
        'status' => ShipmentStatus::class,
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function moduleForSourceType(string $sourceType): ?string
    {
        return match ($sourceType) {
            'order', 'order_refund' => 'retail',
            'repair_request' => 'repair',
            default => null,
        };
    }

    public static function sourceTypesForModule(string $module): array
    {
        return match ($module) {
            'retail' => ['order', 'order_refund'],
            'repair' => ['repair_request'],
            default => [],
        };
    }

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

    public function deliveryDisputes(): HasMany
    {
        return $this->hasMany(DeliveryDispute::class);
    }
}
