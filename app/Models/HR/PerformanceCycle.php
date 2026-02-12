<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Performance Cycle Model
 * 
 * Represents performance review cycles (annual, quarterly, monthly).
 * Manages the timeline and workflow for performance reviews.
 */
class PerformanceCycle extends Model
{
    use HasFactory;

    protected $table = 'hr_performance_cycles';

    protected $fillable = [
        'shop_owner_id',
        'name',
        'cycle_type',
        'start_date',
        'end_date',
        'self_review_deadline',
        'manager_review_deadline',
        'status',
        'description',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'self_review_deadline' => 'date',
        'manager_review_deadline' => 'date',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Cycle type constants
    const TYPE_ANNUAL = 'annual';
    const TYPE_QUARTERLY = 'quarterly';
    const TYPE_MONTHLY = 'monthly';

    /**
     * Relationships
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class, 'cycle_id');
    }

    public function competencyEvaluations(): HasMany
    {
        return $this->hasMany(CompetencyEvaluation::class, 'cycle_id');
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('cycle_type', $type);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $now = Carbon::now();
        return $query->where('start_date', '<=', $now)
                    ->where('end_date', '>=', $now)
                    ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_IN_PROGRESS]);
    }

    /**
     * Helper Methods
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isSelfReviewOpen(): bool
    {
        $now = Carbon::now();
        return $now->isBefore($this->self_review_deadline) && $this->isActive();
    }

    public function isManagerReviewOpen(): bool
    {
        $now = Carbon::now();
        return $now->isBefore($this->manager_review_deadline) && 
               ($this->isActive() || $this->isInProgress());
    }

    public function getDurationDays(): int
    {
        return $this->start_date->diffInDays($this->end_date);
    }

    public function getProgressPercentage(): float
    {
        $now = Carbon::now();
        if ($now->isBefore($this->start_date)) {
            return 0;
        }
        if ($now->isAfter($this->end_date)) {
            return 100;
        }

        $totalDays = $this->getDurationDays();
        $elapsedDays = $this->start_date->diffInDays($now);
        
        return round(($elapsedDays / $totalDays) * 100, 2);
    }

    public function activate(): bool
    {
        if ($this->status === self::STATUS_DRAFT) {
            $this->status = self::STATUS_ACTIVE;
            return $this->save();
        }
        return false;
    }

    public function start(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            $this->status = self::STATUS_IN_PROGRESS;
            return $this->save();
        }
        return false;
    }

    public function complete(): bool
    {
        if ($this->status === self::STATUS_IN_PROGRESS) {
            $this->status = self::STATUS_COMPLETED;
            return $this->save();
        }
        return false;
    }

    public function cancel(): bool
    {
        if (!$this->isCompleted()) {
            $this->status = self::STATUS_CANCELLED;
            return $this->save();
        }
        return false;
    }

    /**
     * Get cycles dropdown options
     */
    public static function getActiveOptions($shopOwnerId): array
    {
        return self::forShopOwner($shopOwnerId)
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_IN_PROGRESS])
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($cycle) => [
                'value' => $cycle->id,
                'label' => "{$cycle->name} ({$cycle->start_date->format('M Y')} - {$cycle->end_date->format('M Y')})",
                'type' => $cycle->cycle_type,
                'status' => $cycle->status,
            ])
            ->toArray();
    }

    /**
     * Auto-update status based on dates
     */
    public function updateStatusByDate(): void
    {
        $now = Carbon::now();

        // If cycle hasn't started and is active, keep as active
        if ($now->isBefore($this->start_date) && $this->isActive()) {
            return;
        }

        // If cycle started and is active, move to in_progress
        if ($now->between($this->start_date, $this->end_date) && $this->isActive()) {
            $this->status = self::STATUS_IN_PROGRESS;
            $this->save();
            return;
        }

        // If cycle ended and is in_progress, move to completed
        if ($now->isAfter($this->end_date) && $this->isInProgress()) {
            $this->status = self::STATUS_COMPLETED;
            $this->save();
        }
    }
}
