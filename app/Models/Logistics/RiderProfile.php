<?php

namespace App\Models\Logistics;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RiderProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'rider_type',
        'linked_type',
        'linked_id',
        'name',
        'phone',
        'availability_status',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function linked(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'linked_type', 'linked_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }
}
