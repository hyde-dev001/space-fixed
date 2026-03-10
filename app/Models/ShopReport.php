<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class ShopReport extends Model
{
    protected $fillable = [
        'user_id',
        'shop_owner_id',
        'reason',
        'description',
        'transaction_type',
        'transaction_id',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'ip_address',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // Reason labels for display
    public const REASON_LABELS = [
        'fraud'        => 'Fraud / Scam',
        'fake_products'=> 'Fake / Counterfeit Products',
        'harassment'   => 'Harassment',
        'no_show'      => 'No-Show / Never Delivered',
        'misconduct'   => 'Poor Service Misconduct',
        'other'        => 'Other',
    ];

    // Status labels for display
    public const STATUS_LABELS = [
        'submitted'    => 'Submitted',
        'under_review' => 'Under Review',
        'dismissed'    => 'Dismissed',
        'warned'       => 'Warned',
        'suspended'    => 'Suspended',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'transaction_id')
            ->when($this->transaction_type !== 'order', fn($q) => $q->whereRaw('1=0'));
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class, 'transaction_id')
            ->when($this->transaction_type !== 'repair', fn($q) => $q->whereRaw('1=0'));
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    /** Reports awaiting admin action (submitted or under_review) */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['submitted', 'under_review']);
    }

    /** Shops with 5+ open reports — high priority */
    public function scopeHighPriority(Builder $query): Builder
    {
        return $query->whereIn('status', ['submitted', 'under_review'])
            ->select('shop_owner_id')
            ->groupBy('shop_owner_id')
            ->havingRaw('COUNT(*) >= 5');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function getReasonLabelAttribute(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Detect suspicious patterns for a set of reports on the same shop.
     * Returns array of flag strings.
     */
    public static function detectPatterns(int $shopOwnerId): array
    {
        $flags = [];

        $openReports = self::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['submitted', 'under_review'])
            ->with('reporter')
            ->get();

        if ($openReports->isEmpty()) {
            return $flags;
        }

        // Flag: 5+ reports submitted within 2 hours of each other
        $recentBatch = $openReports->filter(
            fn($r) => $r->created_at->diffInHours(now()) <= 2
        );
        if ($recentBatch->count() >= 5) {
            $flags[] = 'batch_reports'; // Coordinated batch within 2h
        }

        // Flag: 3+ reporters created their account this week
        $newAccountReporters = $openReports->filter(function ($r) {
            return $r->reporter && $r->reporter->created_at->diffInDays(now()) <= 7;
        });
        if ($newAccountReporters->count() >= 3) {
            $flags[] = 'new_account_reporters'; // Reporters are newly created accounts
        }

        // Flag: 3+ reporters share the same /24 IP subnet (IPv4 clustering)
        $ipPrefixes = $openReports
            ->filter(fn($r) => $r->ip_address && str_contains($r->ip_address, '.'))
            ->map(fn($r) => implode('.', array_slice(explode('.', $r->ip_address), 0, 3)))
            ->values();

        $ipCounts = $ipPrefixes->countBy()->filter(fn($count) => $count >= 3);
        if ($ipCounts->isNotEmpty()) {
            $flags[] = 'ip_clustering'; // Multiple reporters from same IP subnet
        }

        return $flags;
    }
}
