<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairReview extends Model
{
    protected $fillable = [
        'repair_request_id',
        'user_id',
        'shop_owner_id',
        'repairer_id',
        'rating',
        'review_text',
        'review_images',
        'shop_response',
        'shop_responded_at',
        'is_verified',
        'is_visible',
        'helpful_count',
    ];

    protected $casts = [
        'review_images' => 'array',
        'shop_responded_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_visible' => 'boolean',
    ];

    /**
     * Get the repair request this review belongs to
     */
    public function repairRequest()
    {
        return $this->belongsTo(RepairRequest::class);
    }

    /**
     * Get the customer who wrote the review
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shop owner being reviewed
     */
    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the repairer who did the work
     */
    public function repairer()
    {
        return $this->belongsTo(User::class, 'repairer_id');
    }

    /**
     * Scope to only get visible reviews
     */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope to get reviews for a specific shop
     */
    public function scopeForShop($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }
}
