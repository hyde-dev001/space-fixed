<?php

namespace App\Http\Controllers\Erp\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\EmployeeOnboarding;
use App\Models\HR\OnboardingChecklist;
use App\Models\HR\OnboardingTask;
use App\Traits\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    use LogsHRActivity;

    /**
     * Get all onboarding checklists
     */
    public function getChecklists(Request $request)
    {
        try {
            // Ensure user is authenticated
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;
            
            $query = OnboardingChecklist::forShopOwner($shopOwnerId)
                ->with(['tasks' => function($q) {
                    $q->ordered();
                }]);

            // Filter by active status
            if ($request->has('active_only') && $request->active_only) {
                $query->active();
            }

            $checklists = $query->get()->map(function($checklist) {
                return [
                    'id' => $checklist->id,
                    'name' => $checklist->name,
                    'description' => $checklist->description,
                    'is_active' => $checklist->is_active,
                    'total_tasks' => $checklist->getTotalTasks(),
                    'mandatory_tasks' => $checklist->getMandatoryTasksCount(),
                    'tasks_by_assignee' => $checklist->getTasksByAssignee(),
                    'created_at' => $checklist->created_at->format('Y-m-d H:i:s'),
                    'tasks' => $checklist->tasks->map(fn($task) => [
                        'id' => $task->id,
                        'task_name' => $task->task_name,
                        'description' => $task->description,
                        'assigned_to' => $task->assigned_to,
                        'assignee_label' => $task->getAssigneeLabel(),
                        'due_days' => $task->due_days,
                        'is_mandatory' => $task->is_mandatory,
                        'order' => $task->order,
                    ]),
                ];
            });

            return response()->json([
                'success' => true,
                'checklists' => $checklists,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch checklists',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new onboarding checklist
     */
    public function createChecklist(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'tasks' => 'required|array|min:1',
                'tasks.*.task_name' => 'required|string|max:200',
                'tasks.*.description' => 'nullable|string',
                'tasks.*.assigned_to' => ['required', Rule::in(['employee', 'hr', 'manager', 'it'])],
                'tasks.*.due_days' => 'required|integer|min:0',
                'tasks.*.is_mandatory' => 'boolean',
            ]);

            DB::beginTransaction();

            $checklist = OnboardingChecklist::create([
                'shop_owner_id' => $shopOwnerId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Create tasks
            foreach ($validated['tasks'] as $index => $taskData) {
                OnboardingTask::create([
                    'shop_owner_id' => $shopOwnerId,
                    'checklist_id' => $checklist->id,
                    'task_name' => $taskData['task_name'],
                    'description' => $taskData['description'] ?? null,
                    'assigned_to' => $taskData['assigned_to'],
                    'due_days' => $taskData['due_days'],
                    'is_mandatory' => $taskData['is_mandatory'] ?? true,
                    'order' => $index + 1,
                ]);
            }

            DB::commit();

            $this->logActivity('onboarding_checklist_created', $checklist->id, [
                'name' => $checklist->name,
                'task_count' => count($validated['tasks']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Onboarding checklist created successfully',
                'checklist' => $checklist->load('tasks'),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a checklist
     */
    public function updateChecklist(Request $request, $id)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $checklist = OnboardingChecklist::forShopOwner($shopOwnerId)->findOrFail($id);

            $validated = $request->validate([
                'name' => 'string|max:100',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
            ]);

            $checklist->update($validated);

            $this->logActivity('onboarding_checklist_updated', $checklist->id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Checklist updated successfully',
                'checklist' => $checklist,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a checklist
     */
    public function deleteChecklist($id)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $checklist = OnboardingChecklist::forShopOwner($shopOwnerId)->findOrFail($id);

            // Check if checklist is assigned to any employees
            $inUse = EmployeeOnboarding::forChecklist($id)->exists();
            
            if ($inUse) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete checklist that is assigned to employees',
                ], 422);
            }

            $name = $checklist->name;
            $checklist->delete();

            $this->logActivity('onboarding_checklist_deleted', $id, ['name' => $name]);

            return response()->json([
                'success' => true,
                'message' => 'Checklist deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate a checklist
     */
    public function activateChecklist($id)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $checklist = OnboardingChecklist::forShopOwner($shopOwnerId)->findOrFail($id);
            $checklist->activate();

            $this->logActivity('onboarding_checklist_activated', $checklist->id);

            return response()->json([
                'success' => true,
                'message' => 'Checklist activated successfully',
                'checklist' => $checklist,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate a checklist
     */
    public function deactivateChecklist($id)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $checklist = OnboardingChecklist::forShopOwner($shopOwnerId)->findOrFail($id);
            $checklist->deactivate();

            $this->logActivity('onboarding_checklist_deactivated', $checklist->id);

            return response()->json([
                'success' => true,
                'message' => 'Checklist deactivated successfully',
                'checklist' => $checklist,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Duplicate a checklist
     */
    public function duplicateChecklist(Request $request, $id)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $checklist = OnboardingChecklist::forShopOwner($shopOwnerId)->findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:100',
            ]);

            $newChecklist = $checklist->duplicate($validated['name']);

            $this->logActivity('onboarding_checklist_duplicated', $newChecklist->id, [
                'original_id' => $checklist->id,
                'new_name' => $validated['name'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checklist duplicated successfully',
                'checklist' => $newChecklist->load('tasks'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create default checklist
     */
    public function createDefaultChecklist()
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $checklist = OnboardingChecklist::createDefault($shopOwnerId);

            $this->logActivity('onboarding_default_checklist_created', $checklist->id);

            return response()->json([
                'success' => true,
                'message' => 'Default checklist created successfully',
                'checklist' => $checklist->load('tasks'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create default checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign checklist to employee
     */
    public function assignChecklistToEmployee(Request $request, $employeeId)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $validated = $request->validate([
                'checklist_id' => 'required|exists:hr_onboarding_checklists,id',
                'hire_date' => 'required|date',
            ]);

            $employee = Employee::where('shop_owner_id', $shopOwnerId)->findOrFail($employeeId);
            $hireDate = new \DateTime($validated['hire_date']);

            // Check if already assigned
            $existing = EmployeeOnboarding::forEmployee($employeeId)
                ->forChecklist($validated['checklist_id'])
                ->exists();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checklist already assigned to this employee',
                ], 422);
            }

            $tasks = EmployeeOnboarding::assignChecklistToEmployee(
                $employeeId,
                $validated['checklist_id'],
                $shopOwnerId,
                $hireDate
            );

            $this->logActivity('onboarding_assigned_to_employee', $employeeId, [
                'checklist_id' => $validated['checklist_id'],
                'task_count' => count($tasks),
                'hire_date' => $validated['hire_date'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checklist assigned successfully',
                'tasks_created' => count($tasks),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign checklist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get employee onboarding progress
     */
    public function getEmployeeProgress($employeeId)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $employee = Employee::where('shop_owner_id', $shopOwnerId)->findOrFail($employeeId);

            $progress = EmployeeOnboarding::getEmployeeProgress($employeeId);
            $tasksByAssignee = EmployeeOnboarding::getTasksByAssignee($employeeId);
            
            $tasks = EmployeeOnboarding::forEmployee($employeeId)
                ->with(['task', 'completedBy'])
                ->get()
                ->map(fn($onboarding) => [
                    'id' => $onboarding->id,
                    'task_name' => $onboarding->task->task_name,
                    'assigned_to' => $onboarding->task->assigned_to,
                    'assignee_label' => $onboarding->task->getAssigneeLabel(),
                    'status' => $onboarding->status,
                    'due_date' => $onboarding->due_date?->format('Y-m-d'),
                    'completed_date' => $onboarding->completed_date?->format('Y-m-d'),
                    'is_overdue' => $onboarding->isOverdue(),
                    'days_until_due' => $onboarding->getDaysUntilDue(),
                    'days_overdue' => $onboarding->getDaysOverdue(),
                    'notes' => $onboarding->notes,
                    'completed_by' => $onboarding->completedBy?->name,
                ]);

            return response()->json([
                'success' => true,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                ],
                'progress' => $progress,
                'tasks_by_assignee' => $tasksByAssignee,
                'tasks' => $tasks,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch employee progress',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update task status
     */
    public function updateTaskStatus(Request $request, $taskId)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $validated = $request->validate([
                'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'skipped'])],
                'notes' => 'nullable|string',
            ]);

            $onboarding = EmployeeOnboarding::forShopOwner($shopOwnerId)->findOrFail($taskId);

            if ($validated['status'] === 'in_progress') {
                $onboarding->start();
            } elseif ($validated['status'] === 'completed') {
                $onboarding->complete(auth()->id(), $validated['notes'] ?? null);
            } elseif ($validated['status'] === 'skipped') {
                $onboarding->skip($validated['notes'] ?? 'No reason provided');
            } else {
                $onboarding->status = $validated['status'];
                $onboarding->save();
            }

            $this->logActivity('onboarding_task_status_updated', $taskId, [
                'status' => $validated['status'],
                'employee_id' => $onboarding->employee_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully',
                'task' => $onboarding,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete task
     */
    public function completeTask(Request $request, $taskId)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $validated = $request->validate([
                'notes' => 'nullable|string',
            ]);

            $onboarding = EmployeeOnboarding::forShopOwner($shopOwnerId)->findOrFail($taskId);
            $onboarding->complete(auth()->id(), $validated['notes'] ?? null);

            $this->logActivity('onboarding_task_completed', $taskId, [
                'employee_id' => $onboarding->employee_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task completed successfully',
                'task' => $onboarding,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Skip task
     */
    public function skipTask(Request $request, $taskId)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $validated = $request->validate([
                'reason' => 'required|string',
            ]);

            $onboarding = EmployeeOnboarding::forShopOwner($shopOwnerId)->findOrFail($taskId);
            $onboarding->skip($validated['reason']);

            $this->logActivity('onboarding_task_skipped', $taskId, [
                'employee_id' => $onboarding->employee_id,
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task skipped successfully',
                'task' => $onboarding,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to skip task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get overdue tasks
     */
    public function getOverdueTasks()
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $overdueTasks = EmployeeOnboarding::forShopOwner($shopOwnerId)
                ->overdue()
                ->with(['employee', 'task'])
                ->get()
                ->map(fn($onboarding) => [
                    'id' => $onboarding->id,
                    'employee' => $onboarding->employee->name,
                    'employee_id' => $onboarding->employee_id,
                    'task_name' => $onboarding->task->task_name,
                    'assigned_to' => $onboarding->task->assigned_to,
                    'assignee_label' => $onboarding->task->getAssigneeLabel(),
                    'due_date' => $onboarding->due_date->format('Y-m-d'),
                    'days_overdue' => $onboarding->getDaysOverdue(),
                    'status' => $onboarding->status,
                ]);

            return response()->json([
                'success' => true,
                'overdue_tasks' => $overdueTasks,
                'count' => $overdueTasks->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch overdue tasks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get upcoming tasks
     */
    public function getUpcomingTasks(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;
            $daysAhead = $request->get('days', 7);

            $upcomingTasks = EmployeeOnboarding::getUpcomingTasks($shopOwnerId, $daysAhead);

            return response()->json([
                'success' => true,
                'upcoming_tasks' => $upcomingTasks,
                'count' => count($upcomingTasks),
                'days_ahead' => $daysAhead,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch upcoming tasks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding statistics
     */
    public function getStatistics()
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = auth()->user()->shop_owner_id;

            $stats = EmployeeOnboarding::getShopStatistics($shopOwnerId);

            return response()->json([
                'success' => true,
                'statistics' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
