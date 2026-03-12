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
     * Scope to filter by employee
     */
    public function scopeForEmployee(Builder $query, $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Scope to filter by leave type (no-op on the per-column schema; kept for compatibility)
     * Returns the same query — callers should use getRemainingForType() instead.
     */
    public function scopeOfType(Builder $query, string $leaveType): Builder
    {
        // The leave_balances table stores all types in one row per employee/year.
        // This scope intentionally does nothing so chained ->first() still works.
        return $query;
    }

    /**
     * Get remaining balance for a specific leave type
     */
    public function getRemainingBalance(string $leaveType): int
    {
        return $this->getRemainingForType($leaveType);
    }

    /**
     * Get the remaining days for a given leave type from the per-column schema.
     * Supports: vacation, sick, personal, maternity, paternity
     */
    public function getRemainingForType(string $leaveType): int
    {
        $typeMap = [
            'vacation'  => ['allowed' => 'vacation_days',  'used' => 'used_vacation'],
            'sick'      => ['allowed' => 'sick_days',      'used' => 'used_sick'],
            'personal'  => ['allowed' => 'personal_days',  'used' => 'used_personal'],
            'maternity' => ['allowed' => 'maternity_days', 'used' => 'used_maternity'],
            'paternity' => ['allowed' => 'paternity_days', 'used' => 'used_paternity'],
            'unpaid'    => null, // unlimited
        ];

        if (!array_key_exists($leaveType, $typeMap)) {
            return 0;
        }

        if ($typeMap[$leaveType] === null) {
            return 999; // unpaid leave has no cap
        }

        $allowedField = $typeMap[$leaveType]['allowed'];
        $usedField    = $typeMap[$leaveType]['used'];

        return max(0, ($this->$allowedField ?? 0) - ($this->$usedField ?? 0));
    }

    /**
     * Deduct days for a given leave type.
     */
    public function deductForType(string $leaveType, float $days): bool
    {
        $typeMap = [
            'vacation'  => 'used_vacation',
            'sick'      => 'used_sick',
            'personal'  => 'used_personal',
            'maternity' => 'used_maternity',
            'paternity' => 'used_paternity',
        ];

        if (!isset($typeMap[$leaveType])) {
            return true; // unpaid or unknown — no balance to deduct
        }

        $usedField = $typeMap[$leaveType];
        $this->$usedField = ($this->$usedField ?? 0) + $days;
        return $this->save();
    }

    /**
     * Restore days for a given leave type (on rejection or cancellation).
     */
    public function restoreForType(string $leaveType, float $days): bool
    {
        $typeMap = [
            'vacation'  => 'used_vacation',
            'sick'      => 'used_sick',
            'personal'  => 'used_personal',
            'maternity' => 'used_maternity',
            'paternity' => 'used_paternity',
        ];

        if (!isset($typeMap[$leaveType])) {
            return true;
        }

        $usedField = $typeMap[$leaveType];
        $this->$usedField = max(0, ($this->$usedField ?? 0) - $days);
        return $this->save();
    }

    /**
     * Check if employee has sufficient leave balance
     */
    public function hasSufficientBalance(string $leaveType, float $daysRequested): bool
    {
        return $this->getRemainingForType($leaveType) >= $daysRequested;
    }

    /**
     * Deduct leave days from balance
     */
    public function deductLeave(string $leaveType, float $days): bool
    {
        return $this->deductForType($leaveType, $days);
    }

    /**
     * Add leave days back to balance (for rejected/cancelled requests)
     */
    public function addBackLeave(string $leaveType, float $days): bool
    {
        return $this->restoreForType($leaveType, $days);
    }

    /**
     * Get all leave balances as array
     */
    public function getAllBalances(): array
    {
        return [
            'vacation' => [
                'allowed'   => $this->vacation_days,
                'used'      => $this->used_vacation,
                'remaining' => $this->getRemainingForType('vacation'),
            ],
            'sick' => [
                'allowed'   => $this->sick_days,
                'used'      => $this->used_sick,
                'remaining' => $this->getRemainingForType('sick'),
            ],
            'personal' => [
                'allowed'   => $this->personal_days,
                'used'      => $this->used_personal,
                'remaining' => $this->getRemainingForType('personal'),
            ],
            'maternity' => [
                'allowed'   => $this->maternity_days,
                'used'      => $this->used_maternity,
                'remaining' => $this->getRemainingForType('maternity'),
            ],
            'paternity' => [
                'allowed'   => $this->paternity_days,
                'used'      => $this->used_paternity,
                'remaining' => $this->getRemainingForType('paternity'),
            ],
            'unpaid' => [
                'allowed'   => null,
                'used'      => 0,
                'remaining' => 999,
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