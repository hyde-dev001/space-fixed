<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $table = 'attendance_records';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'date',
        'check_in_time',
        'check_out_time',
        'expected_check_in',
        'expected_check_out',
        'minutes_late',
        'minutes_early_departure',
        'is_late',
        'is_early_departure',
        'lateness_reason',
        'status',
        'biometric_id',
        'notes',
        'working_hours',
        'overtime_hours',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_time' => 'datetime:H:i',
        'check_out_time' => 'datetime:H:i',
        'expected_check_in' => 'datetime:H:i',
        'expected_check_out' => 'datetime:H:i',
        'working_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'minutes_late' => 'integer',
        'minutes_early_departure' => 'integer',
        'is_late' => 'boolean',
        'is_early_departure' => 'boolean',
    ];

    /**
     * Get the employee this attendance record belongs to
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the shop owner this record belongs to
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
     * Scope to filter by date range
     */
    public function scopeDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter between dates
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by status
     */
    public function scopeWithStatus(Builder $query, $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Calculate lateness based on shop operating hours
     */
    public function calculateLateness(): void
    {
        if (!$this->check_in_time || !$this->expected_check_in) {
            $this->minutes_late = 0;
            $this->is_late = false;
            return;
        }

        $checkIn = \Carbon\Carbon::parse($this->check_in_time);
        $expectedCheckIn = \Carbon\Carbon::parse($this->expected_check_in);
        
        if ($checkIn->gt($expectedCheckIn)) {
            $this->minutes_late = $checkIn->diffInMinutes($expectedCheckIn);
            $this->is_late = true;
            // Auto-update status to 'late' if more than 15 minutes
            if ($this->minutes_late > 15 && $this->status !== 'absent') {
                $this->status = 'late';
            }
        } else {
            $this->minutes_late = 0;
            $this->is_late = false;
        }
    }

    /**
     * Calculate early departure
     */
    public function calculateEarlyDeparture(): void
    {
        if (!$this->check_out_time || !$this->expected_check_out) {
            $this->minutes_early_departure = 0;
            $this->is_early_departure = false;
            return;
        }

        $checkOut = \Carbon\Carbon::parse($this->check_out_time);
        $expectedCheckOut = \Carbon\Carbon::parse($this->expected_check_out);
        
        if ($checkOut->lt($expectedCheckOut)) {
            $this->minutes_early_departure = $expectedCheckOut->diffInMinutes($checkOut);
            $this->is_early_departure = true;
        } else {
            $this->minutes_early_departure = 0;
            $this->is_early_departure = false;
        }
    }

    /**
     * Set expected times based on shop operating hours
     */
    public function setExpectedTimesFromShopHours(): void
    {
        $shopOwner = $this->shopOwner;
        if (!$shopOwner) {
            return;
        }

        $dayOfWeek = strtolower(\Carbon\Carbon::parse($this->date)->format('l'));
        $openKey = $dayOfWeek . '_open';
        $closeKey = $dayOfWeek . '_close';

        $this->expected_check_in = $shopOwner->$openKey;
        $this->expected_check_out = $shopOwner->$closeKey;
    }

    /**
     * Calculate working hours automatically
     */
    public function calculateWorkingHours(): float
    {
        if (!$this->check_in_time || !$this->check_out_time) {
            return 0;
        }

        $checkIn = \Carbon\Carbon::parse($this->check_in_time);
        $checkOut = \Carbon\Carbon::parse($this->check_out_time);
        
        $hours = $checkOut->diffInMinutes($checkIn) / 60;
        
        // Subtract lunch break if working more than 6 hours
        if ($hours > 6) {
            $hours -= 1; // 1 hour lunch break
        }

        return round($hours, 2);
    }

    /**
     * Auto-calculate working hours and lateness before saving
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($attendance) {
            // Set expected times from shop operating hours if not already set
            if (!$attendance->expected_check_in || !$attendance->expected_check_out) {
                $attendance->setExpectedTimesFromShopHours();
            }

            // Calculate lateness
            if ($attendance->check_in_time) {
                $attendance->calculateLateness();
            }

            // Calculate early departure
            if ($attendance->check_out_time) {
                $attendance->calculateEarlyDeparture();
            }

            // Calculate working hours
            if ($attendance->check_in_time && $attendance->check_out_time) {
                $attendance->working_hours = $attendance->calculateWorkingHours();
            }
        });
    }
}