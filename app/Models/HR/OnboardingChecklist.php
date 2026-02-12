<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onboarding Checklist Model
 * 
 * Represents a reusable onboarding checklist template that can be assigned to new employees.
 * Contains multiple tasks that guide the onboarding process.
 */
class OnboardingChecklist extends Model
{
    use HasFactory;

    protected $table = 'hr_onboarding_checklists';

    protected $fillable = [
        'shop_owner_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'checklist_id')->orderBy('order');
    }

    public function employeeOnboarding(): HasMany
    {
        return $this->hasMany(EmployeeOnboarding::class, 'checklist_id');
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Helper Methods
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function activate(): bool
    {
        $this->is_active = true;
        return $this->save();
    }

    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }

    public function getTotalTasks(): int
    {
        return $this->tasks()->count();
    }

    public function getMandatoryTasksCount(): int
    {
        return $this->tasks()->where('is_mandatory', true)->count();
    }

    public function getTasksByAssignee(): array
    {
        return $this->tasks()
            ->select('assigned_to', \DB::raw('COUNT(*) as count'))
            ->groupBy('assigned_to')
            ->get()
            ->mapWithKeys(fn($item) => [$item->assigned_to => $item->count])
            ->toArray();
    }

    /**
     * Duplicate checklist with all tasks
     */
    public function duplicate(string $newName): self
    {
        $newChecklist = self::create([
            'shop_owner_id' => $this->shop_owner_id,
            'name' => $newName,
            'description' => $this->description,
            'is_active' => false, // Start as inactive
        ]);

        foreach ($this->tasks as $task) {
            OnboardingTask::create([
                'shop_owner_id' => $task->shop_owner_id,
                'checklist_id' => $newChecklist->id,
                'task_name' => $task->task_name,
                'description' => $task->description,
                'assigned_to' => $task->assigned_to,
                'due_days' => $task->due_days,
                'is_mandatory' => $task->is_mandatory,
                'order' => $task->order,
            ]);
        }

        return $newChecklist;
    }

    /**
     * Get active checklists dropdown options
     */
    public static function getActiveOptions($shopOwnerId): array
    {
        return self::forShopOwner($shopOwnerId)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn($checklist) => [
                'value' => $checklist->id,
                'label' => $checklist->name,
                'task_count' => $checklist->getTotalTasks(),
            ])
            ->toArray();
    }

    /**
     * Create default onboarding checklist
     */
    public static function createDefault($shopOwnerId): self
    {
        $checklist = self::create([
            'shop_owner_id' => $shopOwnerId,
            'name' => 'Standard Employee Onboarding',
            'description' => 'Default onboarding checklist for new employees',
            'is_active' => true,
        ]);

        // Create default tasks
        $defaultTasks = [
            ['task_name' => 'Complete employment forms', 'assigned_to' => 'employee', 'due_days' => 1, 'order' => 1],
            ['task_name' => 'Review and sign employee handbook', 'assigned_to' => 'employee', 'due_days' => 3, 'order' => 2],
            ['task_name' => 'Set up workstation and equipment', 'assigned_to' => 'it', 'due_days' => 1, 'order' => 3],
            ['task_name' => 'Create email and system accounts', 'assigned_to' => 'it', 'due_days' => 1, 'order' => 4],
            ['task_name' => 'Schedule orientation meeting', 'assigned_to' => 'hr', 'due_days' => 2, 'order' => 5],
            ['task_name' => 'Introduction to team members', 'assigned_to' => 'manager', 'due_days' => 3, 'order' => 6],
            ['task_name' => 'Review job responsibilities', 'assigned_to' => 'manager', 'due_days' => 5, 'order' => 7],
            ['task_name' => 'Complete compliance training', 'assigned_to' => 'employee', 'due_days' => 7, 'order' => 8],
            ['task_name' => 'Set initial goals and objectives', 'assigned_to' => 'manager', 'due_days' => 14, 'order' => 9],
            ['task_name' => '30-day check-in meeting', 'assigned_to' => 'hr', 'due_days' => 30, 'order' => 10, 'is_mandatory' => false],
        ];

        foreach ($defaultTasks as $taskData) {
            OnboardingTask::create(array_merge([
                'shop_owner_id' => $shopOwnerId,
                'checklist_id' => $checklist->id,
                'is_mandatory' => true,
            ], $taskData));
        }

        return $checklist;
    }
}
