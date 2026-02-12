<?php

namespace App\Models\HR;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Employee Onboarding Model
 * 
 * Tracks the completion status of onboarding tasks for individual employees.
 * Links employees to checklist tasks and monitors progress.
 */
class EmployeeOnboarding extends Model
{
    use HasFactory;

    protected $table = 'hr_employee_onboarding';

    protected $fillable = [
        'shop_owner_id',
        'employee_id',
        'checklist_id',
        'task_id',
        'status',
        'due_date',
        'completed_date',
        'notes',
        'completed_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_date' => 'date',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_SKIPPED = 'skipped';

    /**
     * Relationships
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(OnboardingChecklist::class, 'checklist_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OnboardingTask::class, 'task_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner(Builder $query, $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForEmployee(Builder $query, $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForChecklist(Builder $query, $checklistId): Builder
    {
        return $query->where('checklist_id', $checklistId);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
                    ->where('due_date', '<', now())
                    ->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS]);
    }

    /**
     * Helper Methods
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isOverdue(): bool
    {
        if (!$this->due_date || $this->isCompleted()) {
            return false;
        }
        
        return now()->isAfter($this->due_date);
    }

    public function start(): bool
    {
        if ($this->isPending()) {
            $this->status = self::STATUS_IN_PROGRESS;
            return $this->save();
        }
        return false;
    }

    public function complete($userId = null, string $notes = null): bool
    {
        if (!$this->isCompleted()) {
            $this->status = self::STATUS_COMPLETED;
            $this->completed_date = now();
            $this->completed_by = $userId;
            if ($notes !== null) {
                $this->notes = $notes;
            }
            return $this->save();
        }
        return false;
    }

    public function skip(string $reason): bool
    {
        if (!$this->isCompleted()) {
            $this->status = self::STATUS_SKIPPED;
            $this->notes = $reason;
            return $this->save();
        }
        return false;
    }

    public function getDaysUntilDue(): ?int
    {
        if (!$this->due_date || $this->isCompleted()) {
            return null;
        }

        return now()->diffInDays($this->due_date, false);
    }

    public function getDaysOverdue(): ?int
    {
        if (!$this->isOverdue()) {
            return null;
        }

        return $this->due_date->diffInDays(now());
    }

    /**
     * Assign checklist to employee
     */
    public static function assignChecklistToEmployee($employeeId, $checklistId, $shopOwnerId, \DateTime $hireDate): array
    {
        $checklist = OnboardingChecklist::findOrFail($checklistId);
        $tasks = $checklist->tasks()->ordered()->get();
        
        $created = [];
        foreach ($tasks as $task) {
            $dueDate = $task->calculateDueDate($hireDate);
            
            $created[] = self::create([
                'shop_owner_id' => $shopOwnerId,
                'employee_id' => $employeeId,
                'checklist_id' => $checklistId,
                'task_id' => $task->id,
                'status' => self::STATUS_PENDING,
                'due_date' => $dueDate,
            ]);
        }
        
        return $created;
    }

    /**
     * Get onboarding progress for employee
     */
    public static function getEmployeeProgress($employeeId, $checklistId = null): array
    {
        $query = self::forEmployee($employeeId);
        
        if ($checklistId) {
            $query->forChecklist($checklistId);
        }
        
        $tasks = $query->with('task')->get();
        
        $total = $tasks->count();
        $completed = $tasks->where('status', self::STATUS_COMPLETED)->count();
        $inProgress = $tasks->where('status', self::STATUS_IN_PROGRESS)->count();
        $pending = $tasks->where('status', self::STATUS_PENDING)->count();
        $overdue = $tasks->filter(fn($t) => $t->isOverdue())->count();
        
        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'pending' => $pending,
            'overdue' => $overdue,
            'completion_percentage' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'is_complete' => $total > 0 && $completed === $total,
        ];
    }

    /**
     * Get tasks by assignee for employee
     */
    public static function getTasksByAssignee($employeeId, $checklistId = null): array
    {
        $query = self::forEmployee($employeeId)->with('task');
        
        if ($checklistId) {
            $query->forChecklist($checklistId);
        }
        
        $tasks = $query->get();
        
        return [
            'employee' => $tasks->filter(fn($t) => $t->task->assigned_to === OnboardingTask::ASSIGNED_TO_EMPLOYEE)->values(),
            'hr' => $tasks->filter(fn($t) => $t->task->assigned_to === OnboardingTask::ASSIGNED_TO_HR)->values(),
            'manager' => $tasks->filter(fn($t) => $t->task->assigned_to === OnboardingTask::ASSIGNED_TO_MANAGER)->values(),
            'it' => $tasks->filter(fn($t) => $t->task->assigned_to === OnboardingTask::ASSIGNED_TO_IT)->values(),
        ];
    }

    /**
     * Get upcoming tasks (due within X days)
     */
    public static function getUpcomingTasks($shopOwnerId, int $daysAhead = 7): array
    {
        $endDate = now()->addDays($daysAhead);
        
        return self::forShopOwner($shopOwnerId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])
            ->whereBetween('due_date', [now(), $endDate])
            ->with(['employee', 'task'])
            ->orderBy('due_date')
            ->get()
            ->map(fn($onboarding) => [
                'employee' => $onboarding->employee->name ?? 'Unknown',
                'task' => $onboarding->task->task_name,
                'assigned_to' => $onboarding->task->assigned_to,
                'due_date' => $onboarding->due_date->format('Y-m-d'),
                'days_until_due' => $onboarding->getDaysUntilDue(),
                'status' => $onboarding->status,
            ])
            ->toArray();
    }

    /**
     * Get statistics for shop
     */
    public static function getShopStatistics($shopOwnerId): array
    {
        $all = self::forShopOwner($shopOwnerId)->with('task')->get();
        
        $activeEmployees = $all->unique('employee_id')->count();
        $totalTasks = $all->count();
        $completedTasks = $all->where('status', self::STATUS_COMPLETED)->count();
        $overdueTasks = $all->filter(fn($t) => $t->isOverdue())->count();
        
        return [
            'active_onboarding' => $activeEmployees,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'overdue_tasks' => $overdueTasks,
            'completion_rate' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0,
        ];
    }
}
