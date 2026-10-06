<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReviewReport extends Model
{
    protected $table = 'review_reports';

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_UNDER_INVESTIGATION = 'under_investigation';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ACCOUNT_SUSPENDED = 'account_suspended';
    public const STATUS_LEGACY_BANNED = 'banned';

    protected $fillable = [
        'review_type',
        'review_id',
        'shop_owner_id',
        'user_id',
        'reason',
        'notes',
        'review_snapshot',
        'status',
        'admin_notes',
        'resolved_at',
    ];

    protected $casts = [
        'review_snapshot' => 'array',
        'resolved_at'     => 'datetime',
    ];

    public static function reportedReviewKeys(array $shopOwnerIds): Collection
    {
        return static::query()->whereIn('shop_owner_id', $shopOwnerIds)
            ->get(['review_type', 'review_id'])
            ->mapWithKeys(fn (self $report): array => [$report->review_type.'_'.$report->review_id => true]);
    }

    public static function createForReview(Model $review, array $attributes): self
    {
        return DB::transaction(function () use ($review, $attributes): self {
            // Lock the existing review, including when no report exists yet.
            // Both CRM and owner writes use this lock to serialize duplicates.
            $review->newQuery()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            if (static::query()->where('review_type', $attributes['review_type'])
                ->where('review_id', $review->getKey())
                ->where('shop_owner_id', $attributes['shop_owner_id'])->exists()) {
                throw new HttpException(409, 'You have already reported this review.');
            }

            return static::create($attributes);
        });
    }

    /** Human-readable reason labels */
    public static array $reasonLabels = [
        'fake_review'          => 'Fake Review',
        'harassment'           => 'Harassment',
        'spam'                 => 'Spam',
        'inappropriate_content'=> 'Inappropriate Content',
        'other'                => 'Other',
    ];

    public function getReasonLabelAttribute(): string
    {
        return self::$reasonLabels[$this->reason] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /** The customer whose review was reported */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending_review');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_DISMISSED,
            self::STATUS_LEGACY_BANNED,
        ]);
    }

    public function getDomainStatusAttribute(): string
    {
        return $this->status === self::STATUS_LEGACY_BANNED
            ? self::STATUS_ACCOUNT_SUSPENDED
            : (string) $this->status;
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->getRawOriginal('status'), [
            self::STATUS_DISMISSED,
            self::STATUS_LEGACY_BANNED,
        ], true);
    }

    public function isPendingReview(): bool
    {
        return (string) $this->getRawOriginal('status') === self::STATUS_PENDING_REVIEW;
    }

    public function isUnderInvestigation(): bool
    {
        return (string) $this->getRawOriginal('status') === self::STATUS_UNDER_INVESTIGATION;
    }
}
