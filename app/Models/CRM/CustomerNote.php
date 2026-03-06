<?php

namespace App\Models\CRM;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNote extends Model
{
    use HasFactory;

    protected $table = 'customer_notes';

    protected $fillable = [
        'customer_id',
        'shop_owner_id',
        'author',
        'content',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * The customer this note is about.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * The shop owner this note belongs to.
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to filter records by shop owner.
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope to filter notes for a specific customer.
     */
    public function scopeForCustomer(Builder $query, $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }
}
