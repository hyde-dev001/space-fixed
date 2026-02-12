<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Department extends Model
{
    use HasFactory;

    protected $table = 'departments';

    protected $fillable = [
        'shop_owner_id',
        'name',
        'description',
        'manager_name',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the shop owner this department belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get employees in this department
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department', 'name');
    }

    /**
     * Scope to filter by shop owner
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope to get only active departments
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get employee count for this department
     */
    public function getEmployeeCountAttribute(): int
    {
        return $this->employees()->count();
    }

    /**
     * Get active employee count for this department
     */
    public function getActiveEmployeeCountAttribute(): int
    {
        return $this->employees()->where('status', 'active')->count();
    }
}