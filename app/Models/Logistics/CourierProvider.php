<?php

namespace App\Models\Logistics;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'name',
        'provider_type',
        'tracking_url_template',
        'supports_api',
        'active',
    ];

    protected $casts = [
        'supports_api' => 'boolean',
        'active' => 'boolean',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }
}
