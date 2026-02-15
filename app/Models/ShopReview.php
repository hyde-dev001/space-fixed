<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopReview extends Model
{
    protected $fillable = [
        'shop_owner_id',
        'user_id',
        'rating',
        'comment',
        'images',
    ];

    protected $casts = [
        'images' => 'array',
        'rating' => 'integer',
    ];

    /**
     * Get the shop owner that was reviewed
     */
    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    /**
     * Get the user who wrote the review
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
