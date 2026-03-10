<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReport extends Model
{
    protected $table = 'review_reports';

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
        return $query->whereNotIn('status', ['dismissed', 'banned']);
    }
}
