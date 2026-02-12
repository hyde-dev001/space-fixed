<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onboarding Task Model
 * 
 * Represents individual tasks within an onboarding checklist.
 * Tasks are assigned to different parties (employee, HR, manager, IT).
 */
class OnboardingTask extends Model
{
    use HasFactory;

    protected $table = 'hr_onboarding_tasks';

    protected $fillable = [
        'shop_owner_id',
        'checklist_id',
        'task_name',
        'description',
        'assigned_to',
        'due_days',
        'is_mandatory',
        'order',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'due_days' => 'integer',
        'order' => 'integer',
    ];

    // Assignee constants
    const ASSIGNED_TO_EMPLOYEE = 'employee';
    const ASSIGNED_TO_HR = 'hr';
    const ASSIGNED_TO_MANAGER = 'manager';
    const ASSIGNED_TO_IT = 'it';

    /**
     * Relationships
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(OnboardingChecklist::class, 'checklist_id');
    }

    public function employeeOnboarding(): HasMany
    {
        return $this->hasMany(EmployeeOnboarding::class, 'task_id');
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForChecklist(Builder $query, $checklistId): Builder
    {
        return $query->where('checklist_id', $checklistId);
    }

    public function scopeAssignedTo(Builder $query, string $assignee): Builder
    {
        return $query->where('assigned_to', $assignee);
    }

    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
    }

    public function scopeOptional(Builder $query): Builder
    {
        return $query->where('is_mandatory', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /**
     * Helper Methods
     */
    public function isMandatory(): bool
    {
        return $this->is_mandatory;
    }

    public function isAssignedToEmployee(): bool
    {
        return $this->assigned_to === self::ASSIGNED_TO_EMPLOYEE;
    }

    public function isAssignedToHR(): bool
    {
        return $this->assigned_to === self::ASSIGNED_TO_HR;
    }

    public function isAssignedToManager(): bool
    {
        return $this->assigned_to === self::ASSIGNED_TO_MANAGER;
    }

    public function isAssignedToIT(): bool
    {
        return $this->assigned_to === self::ASSIGNED_TO_IT;
    }

    public function getAssigneeLabel(): string
    {
        return match($this->assigned_to) {
            self::ASSIGNED_TO_EMPLOYEE => 'Employee',
            self::ASSIGNED_TO_HR => 'HR Department',
            self::ASSIGNED_TO_MANAGER => 'Direct Manager',
            self::ASSIGNED_TO_IT => 'IT Department',
            default => 'Unknown',
        };
    }

    /**
     * Calculate due date based on hire date
     */
    public function calculateDueDate(\DateTime $hireDate): \DateTime
    {
        return (clone $hireDate)->modify("+{$this->due_days} days");
    }

    /**
     * Reorder tasks in a checklist
     */
    public static function reorder($checklistId, array $taskIds): void
    {
        foreach ($taskIds as $order => $taskId) {
            self::where('id', $taskId)
                ->where('checklist_id', $checklistId)
                ->update(['order' => $order + 1]);
        }
    }

    /**
     * Get tasks grouped by assignee
     */
    public static function getGroupedByAssignee($checklistId): array
    {
        $tasks = self::forChecklist($checklistId)->ordered()->get();
        
        return [
            'employee' => $tasks->where('assigned_to', self::ASSIGNED_TO_EMPLOYEE)->values(),
            'hr' => $tasks->where('assigned_to', self::ASSIGNED_TO_HR)->values(),
            'manager' => $tasks->where('assigned_to', self::ASSIGNED_TO_MANAGER)->values(),
            'it' => $tasks->where('assigned_to', self::ASSIGNED_TO_IT)->values(),
        ];
    }

    /**
     * Get task statistics for a checklist
     */
    public static function getChecklistStatistics($checklistId): array
    {
        $tasks = self::forChecklist($checklistId)->get();
        
        return [
            'total' => $tasks->count(),
            'mandatory' => $tasks->where('is_mandatory', true)->count(),
            'optional' => $tasks->where('is_mandatory', false)->count(),
            'by_assignee' => [
                'employee' => $tasks->where('assigned_to', self::ASSIGNED_TO_EMPLOYEE)->count(),
                'hr' => $tasks->where('assigned_to', self::ASSIGNED_TO_HR)->count(),
                'manager' => $tasks->where('assigned_to', self::ASSIGNED_TO_MANAGER)->count(),
                'it' => $tasks->where('assigned_to', self::ASSIGNED_TO_IT)->count(),
            ],
            'avg_due_days' => round($tasks->avg('due_days'), 1),
        ];
    }
}
