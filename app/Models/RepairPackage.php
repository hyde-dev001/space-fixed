<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class RepairPackage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shop_owner_id',
        'name',
        'description',
        'package_price',
        'old_package_price',
        'change_reason',
        'status',
        'approval_status',
        'starts_at',
        'ends_at',
        'created_by',
        'updated_by',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'finance_notes',
        'owner_reviewed_by',
        'owner_reviewed_at',
        'owner_notes',
        'approval_workflow_version',
        'current_approval_level',
        'approval_id',
    ];

    protected $casts = [
        'package_price' => 'decimal:2',
        'old_package_price' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'finance_reviewed_at' => 'datetime',
        'owner_reviewed_at' => 'datetime',
    ];

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }

    public function ownerReviewer()
    {
        return $this->belongsTo(ShopOwner::class, 'owner_reviewed_by');
    }

    public function approval()
    {
        return $this->morphOne(Approval::class, 'approvable');
    }

    public function services()
    {
        return $this->belongsToMany(RepairService::class, 'repair_package_service')
            ->withTimestamps();
    }

    /**
     * Attach/sync included services with strict validation:
     * - service must exist
     * - service must belong to the same shop owner as the package
     */
    public function syncIncludedServices(array $serviceIds): void
    {
        $serviceIds = collect($serviceIds)
            ->filter(fn ($id) => !is_null($id) && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($serviceIds->isEmpty()) {
            $this->services()->sync([]);
            return;
        }

        $validServiceIds = RepairService::query()
            ->whereIn('id', $serviceIds)
            ->where('shop_owner_id', $this->shop_owner_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $invalidServiceIds = $serviceIds->diff($validServiceIds)->values()->all();

        if (!empty($invalidServiceIds)) {
            throw ValidationException::withMessages([
                'service_ids' => 'Some services are invalid for this package. Only uploaded services from this shop can be included.',
            ]);
        }

        $this->services()->sync($validServiceIds->all());
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }
}