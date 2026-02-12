<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Performance Goal Model
 * 
 * Represents SMART goals for employees within a performance cycle.
 * Tracks goal progress, ratings, and achievement status.
 */
class PerformanceGoal extends Model
{
    use HasFactory;

    protected $table = 'hr_performance_goals';

    protected $fillable = [
        'shop_owner_id',
        'cycle_id',
        'employee_id',
        'goal_description',
        'target_value',
        'weight',
        'status',
        'due_date',
        'progress_notes',
        'actual_value',
        'self_rating',
        'manager_rating',
    ];

    protected $casts = [
        'due_date' => 'date',
        'weight' => 'decimal:2',
        'actual_value' => 'decimal:2',
        'self_rating' => 'integer',
        'manager_rating' => 'integer',
    ];

    // Status constants
    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_ACHIEVED = 'achieved';
    const STATUS_NOT_ACHIEVED = 'not_achieved';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Relationships
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForEmployee(Builder $query, $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForCycle(Builder $query, $cycleId): Builder
    {
        return $query->where('cycle_id', $cycleId);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeAchieved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACHIEVED);
    }

    public function scopeNotAchieved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NOT_ACHIEVED);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
                    ->where('due_date', '<', now())
                    ->whereIn('status', [self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS]);
    }

    /**
     * Helper Methods
     */
    public function isAchieved(): bool
    {
        return $this->status === self::STATUS_ACHIEVED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isOverdue(): bool
    {
        if (!$this->due_date) {
            return false;
        }
        
        return now()->isAfter($this->due_date) && 
               !in_array($this->status, [self::STATUS_ACHIEVED, self::STATUS_CANCELLED]);
    }

    public function getAchievementPercentage(): ?float
    {
        if (!$this->target_value || !$this->actual_value) {
            return null;
        }

        // Assuming target_value and actual_value are numeric
        return round(($this->actual_value / $this->target_value) * 100, 2);
    }

    public function getAverageRating(): ?float
    {
        if (!$this->self_rating && !$this->manager_rating) {
            return null;
        }

        $ratings = array_filter([$this->self_rating, $this->manager_rating]);
        return round(array_sum($ratings) / count($ratings), 2);
    }

    public function startProgress(): bool
    {
        if ($this->status === self::STATUS_NOT_STARTED) {
            $this->status = self::STATUS_IN_PROGRESS;
            return $this->save();
        }
        return false;
    }

    public function markAchieved(float $actualValue = null): bool
    {
        if ($this->isInProgress() || $this->status === self::STATUS_NOT_STARTED) {
            $this->status = self::STATUS_ACHIEVED;
            if ($actualValue !== null) {
                $this->actual_value = $actualValue;
            }
            return $this->save();
        }
        return false;
    }

    public function markNotAchieved(float $actualValue = null, string $notes = null): bool
    {
        if ($this->isInProgress() || $this->status === self::STATUS_NOT_STARTED) {
            $this->status = self::STATUS_NOT_ACHIEVED;
            if ($actualValue !== null) {
                $this->actual_value = $actualValue;
            }
            if ($notes !== null) {
                $this->progress_notes = $notes;
            }
            return $this->save();
        }
        return false;
    }

    public function updateProgress(string $notes, float $actualValue = null): bool
    {
        $this->progress_notes = $notes;
        if ($actualValue !== null) {
            $this->actual_value = $actualValue;
        }
        
        if ($this->status === self::STATUS_NOT_STARTED) {
            $this->status = self::STATUS_IN_PROGRESS;
        }
        
        return $this->save();
    }

    public function submitSelfRating(int $rating): bool
    {
        if ($rating >= 1 && $rating <= 5) {
            $this->self_rating = $rating;
            return $this->save();
        }
        return false;
    }

    public function submitManagerRating(int $rating): bool
    {
        if ($rating >= 1 && $rating <= 5) {
            $this->manager_rating = $rating;
            return $this->save();
        }
        return false;
    }

    /**
     * Get goal statistics for a cycle
     */
    public static function getCycleStatistics($cycleId): array
    {
        $goals = self::forCycle($cycleId)->get();
        
        return [
            'total' => $goals->count(),
            'not_started' => $goals->where('status', self::STATUS_NOT_STARTED)->count(),
            'in_progress' => $goals->where('status', self::STATUS_IN_PROGRESS)->count(),
            'achieved' => $goals->where('status', self::STATUS_ACHIEVED)->count(),
            'not_achieved' => $goals->where('status', self::STATUS_NOT_ACHIEVED)->count(),
            'overdue' => $goals->filter(fn($g) => $g->isOverdue())->count(),
            'achievement_rate' => $goals->count() > 0 
                ? round(($goals->where('status', self::STATUS_ACHIEVED)->count() / $goals->count()) * 100, 2)
                : 0,
        ];
    }

    /**
     * Get employee goal statistics for a cycle
     */
    public static function getEmployeeCycleStatistics($employeeId, $cycleId): array
    {
        $goals = self::forEmployee($employeeId)->forCycle($cycleId)->get();
        
        $totalWeight = $goals->sum('weight');
        $achievedWeight = $goals->where('status', self::STATUS_ACHIEVED)->sum('weight');
        
        return [
            'total_goals' => $goals->count(),
            'achieved' => $goals->where('status', self::STATUS_ACHIEVED)->count(),
            'in_progress' => $goals->where('status', self::STATUS_IN_PROGRESS)->count(),
            'not_achieved' => $goals->where('status', self::STATUS_NOT_ACHIEVED)->count(),
            'overdue' => $goals->filter(fn($g) => $g->isOverdue())->count(),
            'total_weight' => $totalWeight,
            'achieved_weight' => $achievedWeight,
            'weight_achievement_rate' => $totalWeight > 0 
                ? round(($achievedWeight / $totalWeight) * 100, 2)
                : 0,
            'average_self_rating' => $goals->whereNotNull('self_rating')->avg('self_rating'),
            'average_manager_rating' => $goals->whereNotNull('manager_rating')->avg('manager_rating'),
        ];
    }

    /**
     * Bulk create goals for multiple employees
     */
    public static function bulkCreate(array $goalsData): array
    {
        $created = [];
        foreach ($goalsData as $data) {
            $created[] = self::create($data);
        }
        return $created;
    }
}
