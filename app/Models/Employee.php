<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Employee Model
 * 
 * Represents employees within a shop owner's business.
 * Each employee is linked to a specific shop via shop_owner_id
 */
class Employee extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'employees';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_owner_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'state',
        'zip_code',
        'emergency_contact',
        'emergency_phone',
        'profile_photo',
        'position',
        'department_id',
        'department',
        'branch',
        'functional_role',
        'salary',
        'sales_commission_rate',
        'performance_bonus_rate',
        'other_allowances',
        'hire_date',
        'status',
        'suspension_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:2',
        'sales_commission_rate' => 'decimal:4',
        'performance_bonus_rate' => 'decimal:4',
        'other_allowances' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the employee's full name.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Suspend the employee.
     */
    public function suspend(): void
    {
        $this->update(['status' => 'suspended']);
    }

    /**
     * Activate the employee.
     */
    public function activate(): void
    {
        $this->update(['status' => 'active']);
    }

    /**
     * Scope a query to only include active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get the shop owner this employee belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the department this employee belongs to
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Models\HR\Department::class);
    }

    /**
     * Get the user account associated with this employee
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    /**
     * Get attendance records for this employee
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(\App\Models\HR\AttendanceRecord::class);
    }

    /**
     * Get leave requests for this employee
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(\App\Models\HR\LeaveRequest::class);
    }

    /**
     * Get payroll records for this employee
     */
    public function payrolls(): HasMany
    {
        return $this->hasMany(\App\Models\HR\Payroll::class);
    }

    /**
     * Get performance reviews for this employee
     */
    public function performanceReviews(): HasMany
    {
        return $this->hasMany(\App\Models\HR\PerformanceReview::class);
    }

    /**
     * Get leave balances for this employee
     */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(\App\Models\HR\LeaveBalance::class);
    }

    /**
     * Get documents for this employee
     */
    public function documents(): HasMany
    {
        return $this->hasMany(\App\Models\HR\EmployeeDocument::class);
    }

    /**
     * Get training enrollments for this employee
     */
    public function trainingEnrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }

    /**
     * Get certifications for this employee
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class);
    }

    /**
     * Get active training enrollments
     */
    public function activeTrainings(): HasMany
    {
        return $this->trainingEnrollments()->whereIn('status', ['enrolled', 'in_progress']);
    }

    /**
     * Get completed training enrollments
     */
    public function completedTrainings(): HasMany
    {
        return $this->trainingEnrollments()->where('status', 'completed');
    }

    /**
     * Get active certifications
     */
    public function activeCertifications(): HasMany
    {
        return $this->certifications()->active();
    }

    /**
     * Scope to get employees by shop
     */
    public function scopeByShop($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope to get employees for a specific shop owner
     */
    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Verify this employee belongs to a specific shop
     */
    public function belongsToShop($shopOwnerId): bool
    {
        return $this->shop_owner_id === $shopOwnerId;
    }
}
