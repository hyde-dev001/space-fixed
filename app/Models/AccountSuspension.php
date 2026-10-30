<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class AccountSuspension extends Model
{
    use HasFactory;

    public const ACCOUNT_TYPE_SHOP_OWNER = 'shop_owner';
    public const ACCOUNT_TYPE_CUSTOMER = 'customer';
    public const SOURCE_RUNTIME = 'runtime';
    public const SOURCE_LEGACY_RECONCILIATION = 'legacy_reconciliation';

    protected $fillable = [
        'account_type',
        'account_id',
        'source',
        'reason',
        'suspended_by_super_admin_id',
        'started_at',
        'ended_at',
        'ended_by_super_admin_id',
        'end_reason',
        'linked_employee_id',
        'linked_employee_prior_status',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'suspended_by_super_admin_id' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'ended_by_super_admin_id' => 'integer',
        'linked_employee_id' => 'integer',
    ];

    public static function supportsAccountType(string $accountType): bool
    {
        return in_array($accountType, [
            self::ACCOUNT_TYPE_SHOP_OWNER,
            self::ACCOUNT_TYPE_CUSTOMER,
        ], true);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'suspended_by_super_admin_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'ended_by_super_admin_id');
    }

    public function linkedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'linked_employee_id');
    }

    public function appeal(): HasOne
    {
        return $this->hasOne(SuspensionAppeal::class, 'suspension_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }

    public function isClosed(): bool
    {
        return ! $this->isCurrent();
    }
}
