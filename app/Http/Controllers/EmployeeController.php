<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

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
                'status' => 'required|in:active,inactive,on_leave',
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

            // Auto-generate temporary password for the employee
            $temporaryPassword = Str::random(10);

            // Create Employee record with temporary password
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
            $employeeData['password'] = $temporaryPassword;
            $employee = Employee::create($employeeData);

            // Ensure no existing user account with same email
            if (User::where('email', $validated['email'])->exists()) {
                return response()->json([
                    'message' => 'User account already exists for this email',
                    'error' => 'USER_EXISTS',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Create user account with same temporary password
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'shop_owner_id' => $request->user_shop_id,
                'role' => $validated['role'],
                'password' => Hash::make($temporaryPassword),
                'force_password_change' => true,
            ]);

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
                ],
            ]);

            return response()->json([
                'message' => 'Employee and account created successfully',
                'data' => [
                    'employee' => $employee,
                    'user_id' => $user->id,
                    'temporary_password' => $temporaryPassword,
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
                'status' => 'sometimes|in:active,inactive,on_leave',
            ]);

            $employee->update($validated);

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
     * Suspend an employee (temporary) - sets status to 'inactive' and records reason in AuditLog
     *
     * @param Request $request
     * @param Employee $employee
     * @return \Illuminate\Http\JsonResponse
     */
    public function suspend(Request $request, Employee $employee)
    {
        try {
            if ($employee->shop_owner_id !== $request->user_shop_id) {
                return response()->json([
                    'message' => 'Employee not found',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'suspension_reason' => 'nullable|string|max:500',
            ]);

            $employee->update(['status' => 'inactive']);

            // Audit log the suspension
            try {
                AuditLog::create([
                    'shop_owner_id' => $request->user_shop_id,
                    'actor_user_id' => $request->user()->id ?? null,
                    'action' => 'employee_suspended',
                    'target_type' => 'employee',
                    'target_id' => $employee->id,
                    'metadata' => [
                        'reason' => $validated['suspension_reason'] ?? null,
                    ],
                ]);
            } catch (\Exception $e) {
                // non-fatal
            }

            // Also sync the User record (if any) so Super Admin sees suspension in user management
            try {
                $user = User::where('email', $employee->email)->first();
                if ($user) {
                    $user->update(['status' => 'suspended']);

                    // Audit log for user suspension caused by shop owner
                    try {
                        AuditLog::create([
                            'shop_owner_id' => $request->user_shop_id,
                            'actor_user_id' => $request->user()->id ?? null,
                            'action' => 'user_suspended_by_shop_owner',
                            'target_type' => 'user',
                            'target_id' => $user->id,
                            'metadata' => [
                                'email' => $user->email,
                                'employee_id' => $employee->id,
                                'reason' => $validated['suspension_reason'] ?? null,
                            ],
                        ]);
                    } catch (\Exception $e) {
                        // non-fatal
                    }
                }
            } catch (\Exception $e) {
                // non-fatal
            }

            // If this was an AJAX/JSON request, return JSON; otherwise redirect back so Inertia receives a proper response
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Employee suspended successfully',
                    'data' => $employee,
                ], Response::HTTP_OK);
            }

            return redirect()->back()->with('success', 'Employee suspended successfully');
        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Error suspending employee',
                    'error' => $e->getMessage(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return redirect()->back()->withErrors(['error' => 'Error suspending employee']);
        }
    }

    /**
     * Activate an employee - sets status back to 'active'
     *
     * @param Request $request
     * @param Employee $employee
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate(Request $request, Employee $employee)
    {
        try {
            if ($employee->shop_owner_id !== $request->user_shop_id) {
                return response()->json([
                    'message' => 'Employee not found',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            $employee->update(['status' => 'active']);

            try {
                AuditLog::create([
                    'shop_owner_id' => $request->user_shop_id,
                    'actor_user_id' => $request->user()->id ?? null,
                    'action' => 'employee_activated',
                    'target_type' => 'employee',
                    'target_id' => $employee->id,
                    'metadata' => [],
                ]);
            } catch (\Exception $e) {
                // non-fatal
            }

            // Also sync the User record (if any) so Super Admin sees activation in user management
            try {
                $user = User::where('email', $employee->email)->first();
                if ($user) {
                    $user->update(['status' => 'active']);

                    try {
                        AuditLog::create([
                            'shop_owner_id' => $request->user_shop_id,
                            'actor_user_id' => $request->user()->id ?? null,
                            'action' => 'user_activated_by_shop_owner',
                            'target_type' => 'user',
                            'target_id' => $user->id,
                            'metadata' => [
                                'email' => $user->email,
                                'employee_id' => $employee->id,
                            ],
                        ]);
                    } catch (\Exception $e) {
                        // non-fatal
                    }
                }
            } catch (\Exception $e) {
                // non-fatal
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Employee activated successfully',
                    'data' => $employee,
                ], Response::HTTP_OK);
            }

            return redirect()->back()->with('success', 'Employee activated successfully');
        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Error activating employee',
                    'error' => $e->getMessage(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return redirect()->back()->withErrors(['error' => 'Error activating employee']);
        }
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

            $stats = [
                'total_employees' => Employee::where('shop_owner_id', $shopId)->count(),
                'active_employees' => Employee::where('shop_owner_id', $shopId)
                    ->where('status', 'active')->count(),
                'inactive_employees' => Employee::where('shop_owner_id', $shopId)
                    ->where('status', 'inactive')->count(),
                'on_leave' => Employee::where('shop_owner_id', $shopId)
                    ->where('status', 'on_leave')->count(),
                'total_payroll' => Employee::where('shop_owner_id', $shopId)
                    ->sum('salary'),
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
