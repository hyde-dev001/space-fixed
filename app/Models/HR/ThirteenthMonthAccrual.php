<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirteenthMonthAccrual extends Model
{
    protected $table = 'hr_thirteenth_month_accruals';

    protected $fillable = [
        'shop_owner_id',
        'employee_id',
        'payroll_id',
        'accrual_year',
        'accrual_month',
        'accrual_amount',
        'release_amount',
        'status',
        'released_by',
        'released_at',
        'release_reference',
        'notes',
    ];

    protected $casts = [
        'accrual_amount' => 'decimal:2',
        'release_amount' => 'decimal:2',
        'released_at' => 'datetime',
        'accrual_year' => 'integer',
        'accrual_month' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function scopeForShopOwner(Builder $query, int $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('accrual_year', $year);
    }
}
