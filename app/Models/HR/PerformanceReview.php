<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class PerformanceReview extends Model
{
    use HasFactory;

    protected $table = 'performance_reviews';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'reviewer_name',
        'review_date',
        'review_period',
        'overall_rating',
        'communication_skills',
        'teamwork_collaboration',
        'reliability_responsibility',
        'productivity_efficiency',
        'comments',
        'goals',
        'improvement_areas',
        'status',
    ];

    protected $casts = [
        'review_date' => 'date',
        'overall_rating' => 'integer',
        'communication_skills' => 'integer',
        'teamwork_collaboration' => 'integer',
        'reliability_responsibility' => 'integer',
        'productivity_efficiency' => 'integer',
    ];

    /**
     * Available statuses
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'completed' => 'Completed',
    ];

    /**
     * Rating scale options (1-5)
     */
    public const RATING_SCALE = [
        1 => 'Poor',
        2 => 'Below Average',
        3 => 'Average',
        4 => 'Above Average',
        5 => 'Excellent',
    ];

    /**
     * Get the employee this review belongs to
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the shop owner this review belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Scope to filter by shop owner
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope to filter by employee
     */
    public function scopeForEmployee(Builder $query, $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by review period
     */
    public function scopeForPeriod(Builder $query, $period): Builder
    {
        return $query->where('review_period', $period);
    }

    /**
     * Calculate average rating across all categories
     */
    public function getAverageRatingAttribute(): float
    {
        $ratings = [
            $this->communication_skills,
            $this->teamwork_collaboration,
            $this->reliability_responsibility,
            $this->productivity_efficiency,
        ];

        $validRatings = array_filter($ratings, function($rating) {
            return $rating >= 1 && $rating <= 5;
        });

        if (empty($validRatings)) {
            return 0;
        }

        return round(array_sum($validRatings) / count($validRatings), 1);
    }

    /**
     * Get rating description for a given score
     */
    public function getRatingDescription($rating): string
    {
        return self::RATING_SCALE[$rating] ?? 'Not Rated';
    }

    /**
     * Submit the review
     */
    public function submit(): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        $this->status = 'submitted';
        return $this->save();
    }

    /**
     * Complete the review
     */
    public function complete(): bool
    {
        if ($this->status !== 'submitted') {
            return false;
        }

        $this->status = 'completed';
        return $this->save();
    }

    /**
     * Check if review can be edited
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'submitted']);
    }

    /**
     * Validate all rating fields are within range
     */
    public function hasValidRatings(): bool
    {
        $ratings = [
            $this->overall_rating,
            $this->communication_skills,
            $this->teamwork_collaboration,
            $this->reliability_responsibility,
            $this->productivity_efficiency,
        ];

        foreach ($ratings as $rating) {
            if (!is_null($rating) && ($rating < 1 || $rating > 5)) {
                return false;
            }
        }

        return true;
    }
}