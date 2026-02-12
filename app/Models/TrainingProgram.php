<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingProgram extends Model
{
    protected $table = 'hr_training_programs';

    protected $fillable = [
        'title',
        'description',
        'category',
        'delivery_method',
        'duration_hours',
        'cost',
        'max_participants',
        'prerequisites',
        'learning_objectives',
        'instructor_name',
        'instructor_email',
        'is_mandatory',
        'is_active',
        'issues_certificate',
        'certificate_validity_months',
        'shop_owner_id',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'issues_certificate' => 'boolean',
    ];

    /**
     * Get all sessions for this program
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class, 'training_program_id');
    }

    /**
     * Get all enrollments for this program
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class, 'training_program_id');
    }

    /**
     * Get active enrollments
     */
    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->whereIn('status', ['enrolled', 'in_progress']);
    }

    /**
     * Get completed enrollments
     */
    public function completedEnrollments(): HasMany
    {
        return $this->enrollments()->where('status', 'completed');
    }

    /**
     * Scope for active programs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for mandatory programs
     */
    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Scope for specific category
     */
    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}
