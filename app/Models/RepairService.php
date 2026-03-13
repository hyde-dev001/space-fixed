<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class RepairService extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'category',
        'price',
        'old_price',
        'duration',
        'description',
        'status',
        'rejection_reason',
        'shop_owner_id',
        'created_by',
        'updated_by',
        'finance_notes',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'owner_reviewed_by',
        'owner_reviewed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
    ];

    /**
     * Get activity log options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'price', 'duration', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Get the user who created this service
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this service
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the finance user who reviewed this service
     */
    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }

    /**
     * Get the shop owner who reviewed this service
     */
    public function ownerReviewer()
    {
        return $this->belongsTo(\App\Models\ShopOwner::class, 'owner_reviewed_by');
    }

    /**
     * Packages that include this service.
     */
    public function packages()
    {
        return $this->belongsToMany(RepairPackage::class, 'repair_package_service')
            ->withTimestamps();
    }
}