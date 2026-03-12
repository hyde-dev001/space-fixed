<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shop_owner_id',
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'payment_terms',
        'lead_time_days',
        'is_active',
        'notes',
        'products_supplied',
    ];

    protected $casts = [
        'lead_time_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the shop owner
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get all supplier orders
     */
    public function supplierOrders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    /**
     * Scope to active suppliers
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope by shop owner
     */
    public function scopeByShopOwner(Builder $query, int $shopOwnerId): void
    {
        $query->where('shop_owner_id', $shopOwnerId);
    }
}
