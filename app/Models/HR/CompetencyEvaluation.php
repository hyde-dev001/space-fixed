<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Competency Evaluation Model
 * 
 * Represents competency-based assessments within performance reviews.
 * Tracks self-ratings, manager ratings, and calibrated final ratings.
 */
class CompetencyEvaluation extends Model
{
    use HasFactory;

    protected $table = 'hr_competency_evaluations';

    protected $fillable = [
        'shop_owner_id',
        'review_id',
        'cycle_id',
        'competency_name',
        'competency_description',
        'self_rating',
        'manager_rating',
        'calibrated_rating',
        'self_comments',
        'manager_comments',
        'weight',
    ];

    protected $casts = [
        'self_rating' => 'integer',
        'manager_rating' => 'integer',
        'calibrated_rating' => 'integer',
        'weight' => 'decimal:2',
    ];

    // Rating constants (1-5 scale)
    const RATING_POOR = 1;
    const RATING_BELOW_EXPECTATIONS = 2;
    const RATING_MEETS_EXPECTATIONS = 3;
    const RATING_EXCEEDS_EXPECTATIONS = 4;
    const RATING_OUTSTANDING = 5;

    /**
     * Relationships
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForReview(Builder $query, $reviewId): Builder
    {
        return $query->where('review_id', $reviewId);
    }

    public function scopeForCycle(Builder $query, $cycleId): Builder
    {
        return $query->where('cycle_id', $cycleId);
    }

    public function scopeByCompetency(Builder $query, string $competencyName): Builder
    {
        return $query->where('competency_name', $competencyName);
    }

    public function scopeWithSelfRating(Builder $query): Builder
    {
        return $query->whereNotNull('self_rating');
    }

    public function scopeWithManagerRating(Builder $query): Builder
    {
        return $query->whereNotNull('manager_rating');
    }

    public function scopeCalibrated(Builder $query): Builder
    {
        return $query->whereNotNull('calibrated_rating');
    }

    /**
     * Helper Methods
     */
    public function hasSelfRating(): bool
    {
        return $this->self_rating !== null;
    }

    public function hasManagerRating(): bool
    {
        return $this->manager_rating !== null;
    }

    public function isCalibrated(): bool
    {
        return $this->calibrated_rating !== null;
    }

    public function getAverageRating(): ?float
    {
        $ratings = array_filter([
            $this->self_rating,
            $this->manager_rating,
            $this->calibrated_rating,
        ]);

        if (empty($ratings)) {
            return null;
        }

        return round(array_sum($ratings) / count($ratings), 2);
    }

    public function getRatingGap(): ?int
    {
        if (!$this->hasSelfRating() || !$this->hasManagerRating()) {
            return null;
        }

        return abs($this->self_rating - $this->manager_rating);
    }

    public function getRatingLabel(int $rating): string
    {
        return match($rating) {
            self::RATING_POOR => 'Poor',
            self::RATING_BELOW_EXPECTATIONS => 'Below Expectations',
            self::RATING_MEETS_EXPECTATIONS => 'Meets Expectations',
            self::RATING_EXCEEDS_EXPECTATIONS => 'Exceeds Expectations',
            self::RATING_OUTSTANDING => 'Outstanding',
            default => 'Not Rated',
        };
    }

    public function getSelfRatingLabel(): string
    {
        return $this->self_rating ? $this->getRatingLabel($this->self_rating) : 'Not Rated';
    }

    public function getManagerRatingLabel(): string
    {
        return $this->manager_rating ? $this->getRatingLabel($this->manager_rating) : 'Not Rated';
    }

    public function getCalibratedRatingLabel(): string
    {
        return $this->calibrated_rating ? $this->getRatingLabel($this->calibrated_rating) : 'Not Calibrated';
    }

    public function submitSelfRating(int $rating, string $comments = null): bool
    {
        if ($rating >= 1 && $rating <= 5) {
            $this->self_rating = $rating;
            if ($comments !== null) {
                $this->self_comments = $comments;
            }
            return $this->save();
        }
        return false;
    }

    public function submitManagerRating(int $rating, string $comments = null): bool
    {
        if ($rating >= 1 && $rating <= 5) {
            $this->manager_rating = $rating;
            if ($comments !== null) {
                $this->manager_comments = $comments;
            }
            return $this->save();
        }
        return false;
    }

    public function calibrate(int $rating): bool
    {
        if ($rating >= 1 && $rating <= 5) {
            $this->calibrated_rating = $rating;
            return $this->save();
        }
        return false;
    }

    /**
     * Get weighted score for this competency
     */
    public function getWeightedScore(): ?float
    {
        $rating = $this->calibrated_rating ?? $this->manager_rating ?? $this->self_rating;
        
        if (!$rating || !$this->weight) {
            return null;
        }

        // Convert rating (1-5) to percentage and apply weight
        $ratingPercentage = ($rating / 5) * 100;
        return round(($ratingPercentage * $this->weight) / 100, 2);
    }

    /**
     * Get competency evaluation statistics for a review
     */
    public static function getReviewStatistics($reviewId): array
    {
        $evaluations = self::forReview($reviewId)->get();
        
        $totalWeight = $evaluations->sum('weight');
        $totalWeightedScore = $evaluations->sum(fn($e) => $e->getWeightedScore() ?? 0);
        
        return [
            'total_competencies' => $evaluations->count(),
            'with_self_rating' => $evaluations->whereNotNull('self_rating')->count(),
            'with_manager_rating' => $evaluations->whereNotNull('manager_rating')->count(),
            'calibrated' => $evaluations->whereNotNull('calibrated_rating')->count(),
            'average_self_rating' => $evaluations->whereNotNull('self_rating')->avg('self_rating'),
            'average_manager_rating' => $evaluations->whereNotNull('manager_rating')->avg('manager_rating'),
            'average_calibrated_rating' => $evaluations->whereNotNull('calibrated_rating')->avg('calibrated_rating'),
            'total_weight' => $totalWeight,
            'weighted_score' => $totalWeightedScore,
            'overall_percentage' => $totalWeight > 0 
                ? round(($totalWeightedScore / $totalWeight) * 100, 2)
                : 0,
            'rating_gaps' => $evaluations->filter(fn($e) => $e->getRatingGap() !== null)
                                        ->map(fn($e) => [
                                            'competency' => $e->competency_name,
                                            'gap' => $e->getRatingGap()
                                        ])
                                        ->where('gap', '>', 1) // Only significant gaps
                                        ->values()
                                        ->toArray(),
        ];
    }

    /**
     * Get competency averages across a cycle
     */
    public static function getCycleCompetencyAverages($cycleId): array
    {
        $evaluations = self::forCycle($cycleId)
            ->whereNotNull('calibrated_rating')
            ->get();
        
        $competencyGroups = $evaluations->groupBy('competency_name');
        
        $averages = [];
        foreach ($competencyGroups as $competencyName => $group) {
            $averages[] = [
                'competency' => $competencyName,
                'count' => $group->count(),
                'average_rating' => round($group->avg('calibrated_rating'), 2),
                'min_rating' => $group->min('calibrated_rating'),
                'max_rating' => $group->max('calibrated_rating'),
            ];
        }
        
        return $averages;
    }

    /**
     * Bulk create competency evaluations
     */
    public static function bulkCreate(array $evaluationsData): array
    {
        $created = [];
        foreach ($evaluationsData as $data) {
            $created[] = self::create($data);
        }
        return $created;
    }

    /**
     * Standard competency templates
     */
    public static function getStandardCompetencies(): array
    {
        return [
            [
                'name' => 'Communication Skills',
                'description' => 'Ability to communicate effectively with team members and stakeholders',
            ],
            [
                'name' => 'Teamwork & Collaboration',
                'description' => 'Working effectively with others to achieve common goals',
            ],
            [
                'name' => 'Problem Solving',
                'description' => 'Ability to identify and resolve issues efficiently',
            ],
            [
                'name' => 'Technical Skills',
                'description' => 'Proficiency in job-specific technical requirements',
            ],
            [
                'name' => 'Leadership',
                'description' => 'Ability to guide and motivate team members',
            ],
            [
                'name' => 'Adaptability',
                'description' => 'Flexibility in responding to change and new challenges',
            ],
            [
                'name' => 'Time Management',
                'description' => 'Effective prioritization and meeting deadlines',
            ],
            [
                'name' => 'Customer Focus',
                'description' => 'Understanding and meeting customer needs',
            ],
        ];
    }
}
