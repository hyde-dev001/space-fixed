<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Certification extends Model
{
    protected $table = 'hr_certifications';

    protected $fillable = [
        'employee_id',
        'training_enrollment_id',
        'certificate_name',
        'certificate_number',
        'issuing_organization',
        'issue_date',
        'expiry_date',
        'status',
        'certificate_file_path',
        'verification_url',
        'notes',
        'issued_by',
        'shop_owner_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the training enrollment
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }

    /**
     * Get the user who issued the certificate
     */
    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Check if certificate is expired
     */
    public function isExpired(): bool
    {
        if ($this->expiry_date === null) {
            return false; // No expiry
        }
        return $this->expiry_date < Carbon::now();
    }

    /**
     * Get days until expiry
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }
        return Carbon::now()->diffInDays($this->expiry_date, false);
    }

    /**
     * Check if expiring soon (within 30 days)
     */
    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilExpiry();
        return $days !== null && $days > 0 && $days <= 30;
    }

    /**
     * Scope for active certificates
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where(function($q) {
                        $q->whereNull('expiry_date')
                          ->orWhere('expiry_date', '>', Carbon::now());
                    });
    }

    /**
     * Scope for expired certificates
     */
    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', Carbon::now())
                    ->orWhere('status', 'expired');
    }

    /**
     * Scope for expiring soon
     */
    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('status', 'active')
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays($days)]);
    }

    /**
     * Scope for specific employee
     */
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }
}
