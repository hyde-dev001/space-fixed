<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmployeeEmploymentPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'start_date',
        'end_date',
        'end_reason',
        'position',
        'department',
        'functional_role',
        'salary',
        'role',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'salary' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('end_date');
    }
}
