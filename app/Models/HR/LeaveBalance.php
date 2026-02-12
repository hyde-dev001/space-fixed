<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $table = 'leave_balances';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'year',
        'vacation_days',
        'sick_days',
        'personal_days',
        'maternity_days',
        'paternity_days',
        'used_vacation',
        'used_sick',
        'used_personal',
        'used_maternity',
        'used_paternity',
    ];

    protected $casts = [
        'year' => 'integer',
        'vacation_days' => 'integer',
        'sick_days' => 'integer',
        'personal_days' => 'integer',
        'maternity_days' => 'integer',
        'paternity_days' => 'integer',
        'used_vacation' => 'integer',
        'used_sick' => 'integer',
        'used_personal' => 'integer',
        'used_maternity' => 'integer',
        'used_paternity' => 'integer',
    ];

    /**
     * Get the employee this leave balance belongs to
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the shop owner this leave balance belongs to
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
     * Scope to filter by year
     */
    public function scopeForYear(Builder $query, $year): Builder
    {
        return $query->where('year', $year);
    }

    /**
     * Get remaining balance for a specific leave type
     */
    public function getRemainingBalance(string $leaveType): int
    {
        $allowedField = "{$leaveType}_days";
        $usedField = "used_{$leaveType}";

        if (!property_exists($this, $allowedField) || !property_exists($this, $usedField)) {
            return 0;
        }

        return max(0, $this->$allowedField - $this->$usedField);
    }

    /**
     * Check if employee has sufficient leave balance
     */
    public function hasSufficientBalance(string $leaveType, int $daysRequested): bool
    {
        return $this->getRemainingBalance($leaveType) >= $daysRequested;
    }

    /**
     * Deduct leave days from balance
     */
    public function deductLeave(string $leaveType, int $days): bool
    {
        if (!$this->hasSufficientBalance($leaveType, $days)) {
            return false;
        }

        $usedField = "used_{$leaveType}";
        
        if (property_exists($this, $usedField)) {
            $this->$usedField += $days;
            return $this->save();
        }

        return false;
    }

    /**
     * Add leave days back to balance (for rejected/cancelled requests)
     */
    public function addBackLeave(string $leaveType, int $days): bool
    {
        $usedField = "used_{$leaveType}";
        
        if (property_exists($this, $usedField)) {
            $this->$usedField = max(0, $this->$usedField - $days);
            return $this->save();
        }

        return false;
    }

    /**
     * Get all leave balances as array
     */
    public function getAllBalances(): array
    {
        return [
            'vacation' => [
                'allowed' => $this->vacation_days,
                'used' => $this->used_vacation,
                'remaining' => $this->getRemainingBalance('vacation'),
            ],
            'sick' => [
                'allowed' => $this->sick_days,
                'used' => $this->used_sick,
                'remaining' => $this->getRemainingBalance('sick'),
            ],
            'personal' => [
                'allowed' => $this->personal_days,
                'used' => $this->used_personal,
                'remaining' => $this->getRemainingBalance('personal'),
            ],
            'maternity' => [
                'allowed' => $this->maternity_days,
                'used' => $this->used_maternity,
                'remaining' => $this->getRemainingBalance('maternity'),
            ],
            'paternity' => [
                'allowed' => $this->paternity_days,
                'used' => $this->used_paternity,
                'remaining' => $this->getRemainingBalance('paternity'),
            ],
        ];
    }

    /**
     * Create initial leave balance for new employee
     */
    public static function createForNewEmployee(int $employeeId, int $shopOwnerId, int $year): self
    {
        return self::create([
            'employee_id' => $employeeId,
            'shop_owner_id' => $shopOwnerId,
            'year' => $year,
            'vacation_days' => 15,
            'sick_days' => 10,
            'personal_days' => 5,
            'maternity_days' => 60,
            'paternity_days' => 7,
            'used_vacation' => 0,
            'used_sick' => 0,
            'used_personal' => 0,
            'used_maternity' => 0,
            'used_paternity' => 0,
        ]);
    }
}