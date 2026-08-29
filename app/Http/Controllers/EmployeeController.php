<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\AuditLog;
use App\Mail\EmployeeInvitation;
use App\Services\HR\EmployeeLinkedUserSynchronizer;
use App\Services\HR\EmployeeOwnerProjection;
use App\Services\HR\EmployeeOperationalPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

/**
 * EmployeeController
 * 
 * Handles employee management within the HR module.
 * All operations are automatically scoped to the authenticated user's shop.
 * 
 * Requires: HR role + ShopIsolationMiddleware
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeLinkedUserSynchronizer $linkedUserSynchronizer,
        private readonly EmployeeOwnerProjection $employeeOwnerProjection,
        private readonly EmployeeOperationalPolicy $employeePolicy,
    )
    {
    }

    /**
     * Get all employees for the authenticated user's shop
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            // user_shop_id is injected by ShopIsolationMiddleware
            $shopId = $request->user_shop_id;

            // Apply optional filters
            $query = Employee::where('shop_owner_id', $shopId);

            // Filter by department
            if ($request->has('department')) {
                $query->where('department', $request->department);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Pagination
            $perPage = $request->input('per_page', 15);
            $employees = $query->paginate($perPage);

            return response()->json([
                'message' => 'Employees retrieved successfully',
                'data' => $employees,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving employees',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new employee in the user's shop
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $rules = [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:employees,email',
                'phone' => 'required|string|max:20',
                'position' => $request->role === 'Manager' ? 'nullable|string|max:100' : 'required|string|max:100',
                'department' => 'required|string|max:100',
                'branch' => 'nullable|string|max:100',
                'functional_role' => 'nullable|string|in:HR Handler,Finance Handler,Inventory Handler,Attendance Manager,Performance Reviewer',
                'salary' => 'required|numeric|min:0',
                'hire_date' => 'required|date',
                'status' => ['required', Rule::enum(EmployeeStatus::class)],
                'role' => 'required|in:HR,FINANCE_STAFF,FINANCE_MANAGER,MANAGER,STAFF,CRM,SCM,MRP',
            ];

            $validated = $request->validate($rules, [
                'name.required' => 'Employee name is required',
                'email.required' => 'Email is required',
                'email.unique' => 'This email is already registered',
                'salary.numeric' => 'Salary must be a valid number',
            ]);

            // Automatically assign to user's shop
            $validated['shop_owner_id'] = $request->user_shop_id;

            // Generate invitation token instead of temporary password
            $inviteToken = Str::random(64);
            $inviteExpiresAt = Carbon::now()->addDays(7);

            // Create Employee record
            $employeeData = collect($validated)->only([
                'shop_owner_id',
                'name',
                'email',
                'phone',
                'position',
                'department',
                'branch',
                'functional_role',
                'salary',
                'hire_date',
                'status'
            ])->toArray();
            $employee = Employee::create($employeeData);

            // Ensure no existing user account with same email
            if (User::where('email', $validated['email'])->exists()) {
                return response()->json([
                    'message' => 'User account already exists for this email',
                    'error' => 'USER_EXISTS',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Create user account with invitation token (no password yet)
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'shop_owner_id' => $request->user_shop_id,
                'role' => $validated['role'],
                'password' => null, // No password until invitation is accepted
                'invite_token' => $inviteToken,
                'invite_expires_at' => $inviteExpiresAt,
                'invited_at' => now(),
                'invited_by' => $request->user()->id,
            ]);
            $this->linkedUserSynchronizer->sync($employee);

            // Generate invitation URL
            $inviteUrl = url("/invite/{$inviteToken}");

            // DON'T auto-send email - work email likely doesn't exist yet
            // HR will manually share the link via personal email/WhatsApp/SMS
            $emailSent = false;

            // Audit log
            AuditLog::create([
                'shop_owner_id' => $request->user_shop_id,
                'actor_user_id' => $request->user()->id,
                'action' => 'employee_created',
                'target_type' => 'employee',
                'target_id' => $employee->id,
                'metadata' => [
                    'assigned_role' => $validated['role'],
                    'employee_email' => $validated['email'],
                    'functional_role' => $validated['functional_role'] ?? null,
                    'branch' => $validated['branch'] ?? null,
                    'invitation_sent' => $emailSent,
                ],
            ]);

            return response()->json([
                'message' => 'Employee created successfully. Share the invitation link with the employee.',
                'data' => [
                    'employee' => $employee,
                    'user_id' => $user->id,
                    'invite_url' => $inviteUrl,
                    'invite_expires_at' => $inviteExpiresAt->toDateTimeString(),
                    'email_sent' => $emailSent,
                    'work_email' => $user->email, // Work email (for reference only)
                ],
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating employee',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific employee
     * 
     * @param Request $request
     * @param Employee $employee
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, Employee $employee)
    {
        try {
            // Verify employee belongs to user's shop
            if ($employee->shop_owner_id !== $request->user_shop_id) {
                return response()->json([
                    'message' => 'Employee not found',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Employee retrieved successfully',
                'data' => $employee,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving employee',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an employee
     * 
     * @param Request $request
     * @param Employee $employee
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Employee $employee)
    {
        try {
            // Verify employee belongs to user's shop
            if ($employee->shop_owner_id !== $request->user_shop_id) {
                return response()->json([
                    'message' => 'Employee not found',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:20',
                'position' => 'sometimes|string|max:100',
                'department' => 'sometimes|string|max:100',
                'salary' => 'sometimes|numeric|min:0',
                'salary_effective_date' => 'required_with:salary|date',
                'salary_change_reason' => 'required_with:salary|string|max:500',
                'salary_approved_by' => 'nullable|integer|exists:users,id',
                'status' => ['sometimes', Rule::enum(EmployeeStatus::class)],
            ]);

            $salaryAuditData = null;

            if (array_key_exists('salary', $validated)) {
                $approverId = (int) ($validated['salary_approved_by'] ?? ($request->user()->id ?? 0));

                if ($approverId <= 0) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'errors' => [
                            'salary_approved_by' => ['Salary approver is required.'],
                        ],
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $approver = User::where('shop_owner_id', $request->user_shop_id)->find($approverId);

                if (!$approver) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'errors' => [
                            'salary_approved_by' => ['Salary approver must belong to the same shop.'],
                        ],
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $previousSalary = (float) ($employee->salary ?? 0);
                $newSalary = (float) $validated['salary'];
                $changePercent = $previousSalary > 0
                    ? round((abs($newSalary - $previousSalary) / $previousSalary) * 100, 2)
                    : 0.0;

                $salaryAuditData = [
                    'previous_salary' => $previousSalary,
                    'new_salary' => $newSalary,
                    'effective_date' => $validated['salary_effective_date'],
                    'reason' => $validated['salary_change_reason'],
                    'approved_by' => $approver->id,
                    'approved_by_name' => $approver->name,
                    'change_percent' => $changePercent,
                    'minor_threshold_percent' => (float) config('payroll_governance.salary_change.minor_threshold_percent', 5),
                ];
            }

            $employeeUpdateData = $validated;
            unset(
                $employeeUpdateData['salary_effective_date'],
                $employeeUpdateData['salary_change_reason'],
                $employeeUpdateData['salary_approved_by']
            );

            if (array_key_exists('status', $employeeUpdateData)) {
                if (! $this->employeePolicy->canChangeAccountState($employee, $employeeUpdateData['status'])) {
                    return response()->json([
                        'message' => 'Terminated employees cannot be reactivated.',
                        'error' => 'EMPLOYEE_TERMINATED',
                        'code' => 'EMPLOYEE_TERMINATED',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $employeeUpdateData['privileged_suspension_id'] = null;
            }

            $employee->update($employeeUpdateData);

            if (array_key_exists('status', $employeeUpdateData)) {
                $this->linkedUserSynchronizer->sync($employee);
            }

            if ($salaryAuditData) {
                AuditLog::create([
                    'shop_owner_id' => $request->user_shop_id,
                    'actor_user_id' => $request->user()->id ?? null,
                    'action' => 'employee_salary_updated',
                    'target_type' => 'employee',
                    'target_id' => $employee->id,
                    'metadata' => [
                        'previous_salary' => $salaryAuditData['previous_salary'],
                        'new_salary' => $salaryAuditData['new_salary'],
                        'salary_effective_date' => $salaryAuditData['effective_date'],
                        'salary_change_reason' => $salaryAuditData['reason'],
                        'salary_approved_by' => $salaryAuditData['approved_by'],
                        'salary_approved_by_name' => $salaryAuditData['approved_by_name'],
                        'salary_change_percent' => $salaryAuditData['change_percent'],
                    ],
                ]);
            }

            return response()->json([
                'message' => 'Employee updated successfully',
                'data' => $employee,
            ], Response::HTTP_OK);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating employee',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete an employee
     * 
     * @param Request $request
     * @param Employee $employee
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, Employee $employee)
    {
        try {
            // Verify employee belongs to user's shop
            if ($employee->shop_owner_id !== $request->user_shop_id) {
                return response()->json([
                    'message' => 'Employee not found',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            $employee->delete();

            // Redirect back so Inertia gets a full response and the frontend can show SweetAlert
            return redirect()->back()->with('success', 'Employee deleted successfully');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting employee',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Keep the legacy direct suspension endpoint from bypassing the approval workflow.
     */
    public function suspend(Request $request, Employee $employee)
    {
        return $this->suspensionWorkflowRequiredResponse($request);
    }

    /**
     * Keep the legacy direct activation endpoint from bypassing the approval workflow.
     */
    public function activate(Request $request, Employee $employee)
    {
        return $this->suspensionWorkflowRequiredResponse($request);
    }

    private function suspensionWorkflowRequiredResponse(Request $request)
    {
        $message = 'Employee suspension changes must go through the HR → Manager → Shop Owner workflow.';

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'error' => 'SUSPENSION_WORKFLOW_REQUIRED',
                'code' => 'SUSPENSION_WORKFLOW_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->back()->withErrors(['error' => $message]);
    }

    /**
     * Get employee statistics for the shop
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics(Request $request)
    {
        try {
            $shopId = $request->user_shop_id;

            $employees = Employee::query()
                ->where('shop_owner_id', $shopId)
                ->with('leaveRequests')
                ->get();
            $projections = $employees->map(
                fn (Employee $employee): array => $this->employeeOwnerProjection->project($employee),
            );

            $stats = [
                'total_employees' => $projections->count(),
                'active_employees' => $projections->where('account_state', EmployeeStatus::ACTIVE->value)->count(),
                'inactive_employees' => $projections->whereIn('account_state', [
                    EmployeeStatus::INACTIVE->value,
                    EmployeeStatus::TERMINATED->value,
                ])->count(),
                'on_leave' => $projections->where('on_leave', true)->count(),
                'total_payroll' => $employees->sum('salary'),
            ];

            return response()->json([
                'message' => 'Employee statistics retrieved successfully',
                'data' => $stats,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving statistics',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
