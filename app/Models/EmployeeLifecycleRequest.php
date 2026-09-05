<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeLifecycleRequestStatus;
use App\Enums\EmployeeLifecycleRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmployeeLifecycleRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'requested_by',
        'request_type',
        'reason',
        'evidence',
        'status',
        'manager_id',
        'manager_status',
        'manager_note',
        'manager_reviewed_at',
        'owner_id',
        'owner_status',
        'owner_note',
        'owner_reviewed_at',
        'rehire_start_date',
        'rehire_position',
        'rehire_department',
        'rehire_functional_role',
        'rehire_salary',
        'rehire_role',
    ];

    protected $casts = [
        'request_type' => EmployeeLifecycleRequestType::class,
        'status' => EmployeeLifecycleRequestStatus::class,
        'manager_reviewed_at' => 'datetime',
        'owner_reviewed_at' => 'datetime',
        'rehire_start_date' => 'date',
        'rehire_salary' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'owner_id');
    }
}
