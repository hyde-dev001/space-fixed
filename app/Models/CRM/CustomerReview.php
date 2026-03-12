<?php

namespace App\Models\CRM;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReview extends Model
{
    use HasFactory;

    protected $table = 'customer_reviews';

    protected $fillable = [
        'customer_id',
        'shop_owner_id',
        'order_id',
        'order_type',
        'service_type',
        'rating',
        'comment',
        'feedback_images',
        'response_status',
        'staff_response',
        'responded_at',
    ];

    protected $casts = [
        'feedback_images' => 'array',
        'responded_at'    => 'datetime',
        'rating'          => 'integer',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * The customer who submitted the review.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * The shop owner this review belongs to.
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * The order associated with this review.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * Scope to filter by response status.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('response_status', $status);
    }

    /**
     * Scope to filter by order type (product / repair).
     */
    public function scopeOfType(Builder $query, string $orderType): Builder
    {
        return $query->where('order_type', $orderType);
    }

    /**
     * Scope to filter by minimum rating.
     */
    public function scopeMinRating(Builder $query, int $rating): Builder
    {
        return $query->where('rating', '>=', $rating);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether this review is still awaiting a staff response.
     */
    public function isPending(): bool
    {
        return $this->response_status === 'pending';
    }

    /**
     * Whether a staff response has been sent.
     */
    public function isResponded(): bool
    {
        return $this->response_status === 'responded';
    }
}
