<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSession extends Model
{
    protected $table = 'hr_training_sessions';

    protected $fillable = [
        'training_program_id',
        'session_name',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'location',
        'online_meeting_link',
        'available_seats',
        'enrolled_count',
        'status',
        'instructor_name',
        'session_notes',
        'shop_owner_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Get the training program
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id');
    }

    /**
     * Get all enrollments for this session
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class, 'training_session_id');
    }

    /**
     * Check if session has available seats
     */
    public function hasAvailableSeats(): bool
    {
        if ($this->available_seats === null) {
            return true; // Unlimited seats
        }
        return $this->enrolled_count < $this->available_seats;
    }

    /**
     * Increment enrolled count
     */
    public function incrementEnrolled(): void
    {
        $this->increment('enrolled_count');
    }

    /**
     * Decrement enrolled count
     */
    public function decrementEnrolled(): void
    {
        $this->decrement('enrolled_count');
    }

    /**
     * Scope for upcoming sessions
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now())
                    ->where('status', 'scheduled')
                    ->orderBy('start_date');
    }

    /**
     * Scope for ongoing sessions
     */
    public function scopeOngoing($query)
    {
        return $query->where('status', 'ongoing');
    }
}
