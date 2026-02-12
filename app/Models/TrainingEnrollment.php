<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TrainingEnrollment extends Model
{
    protected $table = 'hr_training_enrollments';

    protected $fillable = [
        'training_program_id',
        'employee_id',
        'training_session_id',
        'status',
        'enrolled_date',
        'start_date',
        'completion_date',
        'progress_percentage',
        'assessment_score',
        'passed',
        'feedback',
        'attendance_hours',
        'enrolled_by',
        'completed_by',
        'completion_notes',
        'shop_owner_id',
    ];

    protected $casts = [
        'enrolled_date' => 'date',
        'start_date' => 'date',
        'completion_date' => 'date',
        'assessment_score' => 'decimal:2',
        'passed' => 'boolean',
    ];

    /**
     * Get the training program
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id');
    }

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the training session
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /**
     * Get the user who enrolled this employee
     */
    public function enrolledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    /**
     * Get the user who completed this enrollment
     */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the certification (if issued)
     */
    public function certification(): HasOne
    {
        return $this->hasOne(Certification::class, 'training_enrollment_id');
    }

    /**
     * Mark as in progress
     */
    public function markInProgress(): void
    {
        $this->update([
            'status' => 'in_progress',
            'start_date' => now(),
        ]);
    }

    /**
     * Mark as completed
     */
    public function markCompleted(float $score = null, bool $passed = null, string $notes = null): void
    {
        $this->update([
            'status' => 'completed',
            'completion_date' => now(),
            'progress_percentage' => 100,
            'assessment_score' => $score,
            'passed' => $passed,
            'completion_notes' => $notes,
            'completed_by' => auth()->id(),
        ]);
    }

    /**
     * Scope for active enrollments
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['enrolled', 'in_progress']);
    }

    /**
     * Scope for completed enrollments
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for specific employee
     */
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }
}
