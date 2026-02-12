<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    protected $table = 'product_reviews';

    protected $fillable = [
        'product_id',
        'user_id',
        'order_id',
        'shop_owner_id',
        'rating',
        'comment',
        'images',
        'is_verified_purchase',
        'is_approved',
    ];

    protected $casts = [
        'rating' => 'integer',
        'images' => 'array',
        'is_verified_purchase' => 'boolean',
        'is_approved' => 'boolean',
    ];

    /**
     * Get the product that this review belongs to
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who wrote this review
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order associated with this review
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the shop owner
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Check if user can review this product
     * User must have purchased the product and order must be completed/received
     */
    public static function canUserReview(int $userId, int $productId): array
    {
        // Check if user already reviewed this product
        $existingReview = self::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($existingReview) {
            return [
                'can_review' => false,
                'reason' => 'already_reviewed',
                'message' => 'You have already reviewed this product.',
                'existing_review' => $existingReview,
            ];
        }

        // Check if user has purchased this product
        $purchasedOrder = Order::where('customer_id', $userId)
            ->whereHas('items', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->whereIn('status', ['completed', 'delivered'])
            ->first();

        if (!$purchasedOrder) {
            // Check if there's a pending order
            $pendingOrder = Order::where('customer_id', $userId)
                ->whereHas('items', function ($query) use ($productId) {
                    $query->where('product_id', $productId);
                })
                ->whereIn('status', ['pending', 'processing', 'shipped'])
                ->first();

            if ($pendingOrder) {
                return [
                    'can_review' => false,
                    'reason' => 'pending_delivery',
                    'message' => 'You can leave a review once your order has been delivered.',
                    'order_status' => $pendingOrder->status,
                ];
            }

            return [
                'can_review' => false,
                'reason' => 'not_purchased',
                'message' => 'Only verified buyers can review this product.',
            ];
        }

        return [
            'can_review' => true,
            'order_id' => $purchasedOrder->id,
            'message' => 'You can write a review for this product.',
        ];
    }

    /**
     * Get approved reviews for a product
     */
    public static function getProductReviews(int $productId)
    {
        return self::where('product_id', $productId)
            ->where('is_approved', true)
            ->with(['user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($review) {
                return [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'images' => $review->images,
                    'user_name' => $review->user->name,
                    'is_verified_purchase' => $review->is_verified_purchase,
                    'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                    'formatted_date' => $review->created_at->format('F d, Y'),
                ];
            });
    }

    /**
     * Get average rating for a product
     */
    public static function getAverageRating(int $productId): float
    {
        $average = self::where('product_id', $productId)
            ->where('is_approved', true)
            ->avg('rating');

        return round($average ?? 0, 1);
    }

    /**
     * Get rating distribution for a product
     */
    public static function getRatingDistribution(int $productId): array
    {
        $distribution = [];
        
        for ($i = 5; $i >= 1; $i--) {
            $count = self::where('product_id', $productId)
                ->where('is_approved', true)
                ->where('rating', $i)
                ->count();
            
            $distribution[$i] = $count;
        }

        return $distribution;
    }
}
