<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\PermissionAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\PositionTemplate;
use App\Models\PositionTemplatePermission;
use App\Services\BusinessAccessControlService;
use Carbon\Carbon;

class UserAccessControlController extends Controller
{
    /**
     * Business Access Control Service
     */
    protected BusinessAccessControlService $accessControl;

    public function __construct(BusinessAccessControlService $accessControl)
    {
        $this->accessControl = $accessControl;
    }

    /**
     * Display the user access control page.
     */
    public function index()
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        // If the request is unauthenticated for a shop owner, redirect to the shop-owner login
        if (!$shopOwner) {
            return redirect()->route('shop-owner.login.form');
        }

        // SECURITY: Individual accounts cannot access staff management
        if (!$this->accessControl->canManageStaff($shopOwner)) {
            return redirect()->route('shop-owner.dashboard')
                ->with('error', 'Staff management is only available for Company accounts. Upgrade your account to manage employees.')
                ->with('upgrade_prompt', true);
        }
        
        // Fetch employees for this shop owner with their user account role and permissions
        $employees = Employee::where('shop_owner_id', $shopOwner->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($employee) {
                $user = $employee->user;
                $roleName = $user?->getRoleNames()->first() ?? null;
                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'phone' => $employee->phone ?? $user?->phone ?? null,
                    'address' => $user?->address ?? null,
                    'role' => $roleName ?? $employee->department ?? 'Staff',
                    'status' => $employee->status,
                    'createdAt' => $employee->created_at,
                    'salary' => $employee->salary ?? 0,
                    'hire_date' => $employee->hire_date?->format('Y-m-d'),
                    'position_template_id' => $employee->position_template_id,
                    'department' => $employee->department,
                    // Include Spatie permissions
                    'userId' => $user?->id,
                    'roleName' => $roleName,
                    'permissions' => $user?->getAllPermissions()->pluck('name')->toArray() ?? [],
                    'rolePermissions' => $user?->getPermissionsViaRoles()->pluck('name')->toArray() ?? [],
                    'directPermissions' => $user?->getDirectPermissions()->pluck('name')->toArray() ?? [],
                    // Include role information
                    'primaryRole' => $roleName ?? $employee->department ?? 'Staff',
                    'additionalRoles' => $user?->additional_roles ?? [],
                ];
            });
        
        return Inertia::render('ShopOwner/TeamManagement/UserAccessControl', [
            'employees' => $employees,
        ]);
    }

    /**
     * Create a new employee for the authenticated shop owner
     */
    public function storeEmployee(Request $request)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if (!$shopOwner) {
                return back()->withErrors(['error' => 'Not authenticated as shop owner']);
            }

            // SECURITY: Check if shop owner can manage staff (company only)
            if (!$this->accessControl->canManageStaff($shopOwner)) {
                return back()->withErrors([
                    'error' => 'Staff management is only available for Company accounts. Individual accounts cannot create employees.'
                ])->with('upgrade_prompt', true);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:employees,email',
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:100',
                'position_template_id' => 'nullable|exists:position_templates,id',
                'department' => 'nullable|string|max:100',
                'branch' => 'nullable|string|max:100',
                'salary' => 'nullable|numeric|min:0',
                'hire_date' => 'nullable|date',
                'status' => 'nullable|in:active,inactive,on_leave',
                'role' => 'required|in:MANAGER,FINANCE,HR,CRM,STAFF,REPAIRER,Manager,Finance,Staff,Repairer',
            ], [
                'name.required' => 'Employee name is required',
                'email.required' => 'Email is required',
                'email.unique' => 'This email is already registered',
                'salary.numeric' => 'Salary must be a valid number',
                'role.in' => 'Role must be Manager, Finance, HR, CRM, Staff, or Repairer',
            ]);

            // Normalize role to uppercase to match database enum
            $validated['role'] = strtoupper($validated['role']);

            // SECURITY: Validate role creation based on business type
            $roleValidation = $this->accessControl->validateRoleCreation($validated['role'], $shopOwner);
            if (!$roleValidation['allowed']) {
                return back()->withErrors([
                    'role' => $roleValidation['reason']
                ])->withInput();
            }

            // Assign to shop owner's shop
            $validated['shop_owner_id'] = $shopOwner->id;
            
            // Set defaults for optional fields
            $validated['salary'] = $validated['salary'] ?? 0;
            $validated['phone'] = $validated['phone'] ?? '';
            // Position is now optional - can be set manually or left blank
            $validated['position'] = $validated['position'] ?? '';
            $validated['department'] = $validated['department'] ?? 'General';
            $validated['hire_date'] = $validated['hire_date'] ?? now()->toDateString();
            $validated['status'] = $validated['status'] ?? 'active';
            $validated['branch'] = $validated['branch'] ?? null;

            // Ensure email is free across employees and users before creating anything
            if (Employee::where('email', $validated['email'])->exists()) {
                return back()->withErrors([
                    'email' => 'This email is already registered as an employee'
                ]);
            }
            if (User::where('email', $validated['email'])->exists()) {
                return back()->withErrors([
                    'email' => 'User account already exists for this email'
                ]);
            }

            // Create both Employee and User atomically
            // Generate invitation token instead of temporary password
            $inviteToken = Str::random(64);
            $inviteExpiresAt = Carbon::now()->addDays(7);
            
            [$employee, $user] = DB::transaction(function () use ($validated, $shopOwner, $inviteToken, $inviteExpiresAt) {
                $employeeData = collect($validated)->only([
                    'shop_owner_id','name','email','phone','address','position','department','branch','salary','hire_date','status'
                ])->toArray();
                $employee = Employee::create($employeeData);

                // Split full name into first and last for the User model if possible
                $firstName = '';
                $lastName = '';
                if (!empty($validated['name'])) {
                    $parts = preg_split('/\s+/', trim($validated['name']));
                    $firstName = $parts[0] ?? '';
                    $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
                }

                $user = User::create([
                    'name' => $validated['name'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? '',
                    'address' => $validated['address'] ?? '',
                    'shop_owner_id' => $shopOwner->id,
                    'role' => $validated['role'], // Keep old role column for backward compatibility
                    'position' => $validated['position'] ?? null,
                    'password' => null, // No password until invitation is accepted
                    'invite_token' => $inviteToken,
                    'invite_expires_at' => $inviteExpiresAt,
                    'invited_at' => now(),
                    'invited_by' => $shopOwner->id,
                ]);

                // Assign Spatie role based on department
                $roleMap = [
                    'MANAGER' => 'Manager',
                    'FINANCE' => 'Finance',
                    'HR' => 'HR',
                    'CRM' => 'CRM',
                    'REPAIRER' => 'Repairer',
                    'STAFF' => 'Staff',
                ];
                
                $spatieRole = $roleMap[$validated['role']] ?? 'Staff';
                $user->assignRole($spatieRole);
                
                // Permission Audit Log - COMPLIANCE CRITICAL
                PermissionAuditLog::logRoleAssigned(
                    $user,
                    $spatieRole,
                    "New employee created with {$spatieRole} role",
                    $spatieRole === 'Manager' ? 'medium' : 'low'
                );
                
                // Apply position template permissions if provided (for Staff or department roles)
                if ($validated['role'] !== 'Manager' && !empty($validated['position_template_id'])) {
                    $template = PositionTemplate::with('permissions')->find($validated['position_template_id']);
                    if ($template) {
                        // Use the relationship collection, not the attribute accessor
                        $permissionNames = $template->permissions()->pluck('permission_name')->toArray();
                        if (!empty($permissionNames)) {
                            $user->givePermissionTo($permissionNames);
                            
                            // Permission Audit Log - Position template application
                            PermissionAuditLog::logPositionAssigned(
                                $user,
                                $template->name,
                                $template->id,
                                $permissionNames,
                                "Position template '{$template->name}' applied to new employee"
                            );
                        }
                        
                        // Set position name from template if not provided
                        if (empty($validated['position'])) {
                            $user->position = $template->name;
                            $employee->position = $template->name;
                            $user->save();
                            $employee->save();
                        }
                        
                        // Increment usage count
                        $template->increment('usage_count');
                    }
                }

                return [$employee, $user];
            });

            // Audit log (optional)
            try {
                AuditLog::create([
                    'shop_owner_id' => $shopOwner->id,
                    'actor_user_id' => $shopOwner->id,
                    'action' => 'employee_created',
                    'target_type' => 'employee',
                    'target_id' => $employee->id,
                    'metadata' => [
                        'assigned_role' => $validated['role'], // Old role column
                        'spatie_role' => $user->getRoleNames()->first() ?? null, // New Spatie role
                        'position' => $user->position ?? null,
                        'employee_email' => $validated['email'],
                        'branch' => $validated['branch'] ?? null,
                    ],
                ]);
            } catch (\Exception $e) {
                // Audit log is optional - don't fail if it errors
            }

            // Generate invitation URL
            $inviteUrl = url("/invite/{$inviteToken}");

            // Return back with success data - Inertia will automatically reload with fresh props
            // Use redirect()->back() to ensure flash data is properly set in session
            // Add timestamp to ensure uniqueness and trigger useEffect on each creation
            return redirect()->back()->with([
                'success' => true,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                ],
                'user_id' => $user->id,
                'invite_url' => $inviteUrl,
                'invite_expires_at' => $inviteExpiresAt->toISOString(),
                'work_email' => $employee->email,
                'email_sent' => false, // Manual sharing workflow
                'timestamp' => now()->timestamp, // Unique identifier for each creation
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => 'Error creating employee: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get all available permissions grouped by module
     * Phase 6: Permission Management
     */
    public function getAvailablePermissions()
    {
        // Support both shop owner and user (manager/HR) authentication
        $shopOwner = Auth::guard('shop_owner')->user();
        $user = Auth::guard('user')->user();
        
        if (!$shopOwner && !$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        // Shop owners can always access
        // Users (managers/HR) can access this feature - no specific permission required
        // This allows managers to view and manage employee permissions

        // Get all permissions for 'user' guard
        $allPermissions = Permission::where('guard_name', 'user')
            ->orderBy('name')
            ->get()
            ->pluck('name')
            ->toArray();

        // Group permissions by module - SIMPLIFIED PAGE-BASED STRUCTURE
        $grouped = [
            'finance' => [],
            'hr' => [],
            'crm' => [],
            'manager' => [],
            'repairer' => [],
            'staff' => [],
            'common' => [],
        ];

        foreach ($allPermissions as $permission) {
            // Finance Module: access-finance-* permissions
            if (str_starts_with($permission, 'access-finance-') ||
                str_contains($permission, 'payslip-approval') ||
                str_contains($permission, 'refund-approval') ||
                str_contains($permission, 'repair-price-approval') ||
                str_contains($permission, 'shoe-price-approval') ||
                str_contains($permission, 'approval-workflow')) {
                $grouped['finance'][] = $permission;
            } 
            // HR Module: access-hr-* permissions
            elseif (str_starts_with($permission, 'access-hr-') ||
                    str_contains($permission, 'employee-directory') ||
                    str_contains($permission, 'attendance-records') ||
                    str_contains($permission, 'leave-approval') ||
                    str_contains($permission, 'overtime-approval') ||
                    str_contains($permission, 'payslip-generation') ||
                    str_contains($permission, 'view-payslip')) {
                $grouped['hr'][] = $permission;
            } 
            // CRM Module: access-crm-* permissions
            elseif (str_starts_with($permission, 'access-crm-') ||
                    str_contains($permission, 'customer-support') ||
                    str_contains($permission, 'customer-reviews')) {
                $grouped['crm'][] = $permission;
            } 
            // Manager Module: access-manager-* permissions
            elseif (str_starts_with($permission, 'access-manager-') ||
                    str_contains($permission, 'audit-logs') ||
                    str_contains($permission, 'manager-reports') ||
                    str_contains($permission, 'inventory-overview') ||
                    str_contains($permission, 'product-upload-manager') ||
                    str_contains($permission, 'repair-reject-review') ||
                    str_contains($permission, 'suspend-account')) {
                $grouped['manager'][] = $permission;
            }
            // Repairer Module: access-repairer-* permissions
            elseif (str_starts_with($permission, 'access-repairer-') ||
                    str_contains($permission, 'repair-job-orders') ||
                    str_contains($permission, 'pricing-services') ||
                    str_contains($permission, 'repairer-support') ||
                    str_contains($permission, 'repair-stocks') ||
                    str_contains($permission, 'upload-service')) {
                $grouped['repairer'][] = $permission;
            }
            // Staff Module: access-staff-* permissions
            elseif (str_starts_with($permission, 'access-staff-') ||
                    str_contains($permission, 'staff-job-orders') ||
                    str_contains($permission, 'product-management') ||
                    str_contains($permission, 'product-upload-staff') ||
                    str_contains($permission, 'shoe-pricing') ||
                    str_contains($permission, 'staff-time-in') ||
                    str_contains($permission, 'staff-leave') ||
                    str_contains($permission, 'color-variant-manager') ||
                    str_contains($permission, 'staff-customers')) {
                $grouped['staff'][] = $permission;
            }
            // Inventory Module: access-inventory-* permissions
            elseif (str_starts_with($permission, 'access-inventory-') ||
                    str_contains($permission, 'product-inventory') ||
                    str_contains($permission, 'stock-movement') ||
                    str_contains($permission, 'suppliers-management') ||
                    str_contains($permission, 'upload-inventory')) {
                $grouped['staff'][] = $permission; // Group with staff for now
            }
            // Common/Global permissions
            elseif (str_contains($permission, 'global-search') ||
                    str_contains($permission, 'notification-center') ||
                    str_contains($permission, 'access-profile')) {
                $grouped['common'][] = $permission;
            }
        }

        // Get all roles with their permissions
        $roles = Role::where('guard_name', 'user')
            ->with('permissions')
            ->get()
            ->map(function ($role) {
                return [
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->toArray(),
                ];
            });

        return response()->json([
            'all' => $allPermissions,
            'grouped' => $grouped,
            'roles' => $roles,
        ]);
    }

    /**
     * Get employee's permissions
     * Phase 6: Permission Management
     */
    public function getEmployeePermissions($userId)
    {
        // Support both shop owner and user (manager/HR) authentication
        $shopOwner = Auth::guard('shop_owner')->user();
        $authenticatedUser = Auth::guard('user')->user();
        
        if (!$shopOwner && !$authenticatedUser) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        // If user (manager/HR), verify they have permission to view employees
        // Shop owners can always view - no specific permission required

        // Get shop_owner_id from either shop owner or authenticated user
        $shopOwnerId = $shopOwner ? $shopOwner->id : $authenticatedUser->shop_owner_id;

        $employee = User::where('id', $userId)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        return response()->json([
            'userId' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'roleName' => $employee->getRoleNames()->first() ?? null,
            'allPermissions' => $employee->getAllPermissions()->pluck('name')->toArray(),
            'rolePermissions' => $employee->getPermissionsViaRoles()->pluck('name')->toArray(),
            'directPermissions' => $employee->getDirectPermissions()->pluck('name')->toArray(),
        ]);
    }

    /**
     * Update employee's direct permissions (add/remove individual permissions)
     * Phase 6: Permission Management
     */
    public function updateEmployeePermissions(Request $request, $userId)
    {
        try {
            // Support both shop owner and user (manager/HR) authentication
            $shopOwner = Auth::guard('shop_owner')->user();
            $authenticatedUser = Auth::guard('user')->user();
            
            if (!$shopOwner && !$authenticatedUser) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // If user (manager/HR), they can manage permissions
            // Shop owners can always manage - no specific permission required

            // Get shop_owner_id from either shop owner or authenticated user
            $shopOwnerId = $shopOwner ? $shopOwner->id : $authenticatedUser->shop_owner_id;

            $user = User::where('id', $userId)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $validated = $request->validate([
                'action' => 'required|in:give,revoke',
                'permission' => 'required|string|exists:permissions,name',
            ]);

            // SECURITY: Prevent managers from being assigned finance permissions
            // Only shop owners can access finance - as per tech lead requirements
            $financePermissions = [
                'view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses', 'approve-expenses',
                'view-invoices', 'create-invoices', 'edit-invoices', 'delete-invoices', 'send-invoices',
                'approve-payroll', 'process-payroll', // Managers can view payroll but not approve/process
                'view-finance-reports', 'export-finance-reports', 'view-finance-audit-logs',
                'manage-cost-centers', 'view-revenue-accounts', 'reconcile-accounts',
                'approve-repair-pricing', 'approve-shoe-pricing', 'manage-service-pricing', 'edit-pricing',
            ];
            
            if ($validated['action'] === 'give' && $user->hasRole('Manager') && in_array($validated['permission'], $financePermissions)) {
                return response()->json([
                    'error' => 'Finance permissions cannot be assigned to managers. Only shop owners can access finance.',
                    'forbidden_permission' => $validated['permission']
                ], 403);
            }

            if ($validated['action'] === 'give') {
                $user->givePermissionTo($validated['permission']);
                $message = "Permission '{$validated['permission']}' granted to {$user->name}";
                
                // Permission Audit Log - COMPLIANCE CRITICAL
                PermissionAuditLog::logPermissionGranted(
                    $user,
                    $validated['permission'],
                    $request->input('reason'),
                    'medium'
                );
            } else {
                $user->revokePermissionTo($validated['permission']);
                $message = "Permission '{$validated['permission']}' revoked from {$user->name}";
                
                // Permission Audit Log - COMPLIANCE CRITICAL
                PermissionAuditLog::logPermissionRevoked(
                    $user,
                    $validated['permission'],
                    $request->input('reason'),
                    'high'
                );
            }

            // Audit log (legacy system)
            try {
                // Get actor ID (shop owner or authenticated user)
                $actorId = $shopOwner ? $shopOwner->id : $authenticatedUser->id;
                
                AuditLog::create([
                    'shop_owner_id' => $shopOwnerId,
                    'actor_user_id' => $actorId,
                    'action' => $validated['action'] === 'give' ? 'permission_granted' : 'permission_revoked',
                    'target_type' => 'user',
                    'target_id' => $user->id,
                    'metadata' => [
                        'permission' => $validated['permission'],
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                    ],
                ]);
            } catch (\Exception $e) {
                // Audit log is optional
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'allPermissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                'directPermissions' => $user->getDirectPermissions()->pluck('name')->toArray(),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update permissions: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Sync employee's direct permissions (replace all direct permissions)
     * Phase 6: Permission Management
     */
    public function syncEmployeePermissions(Request $request, $userId)
    {
        try {
            // Support both shop owner and user (manager/HR) authentication
            $shopOwner = Auth::guard('shop_owner')->user();
            $authenticatedUser = Auth::guard('user')->user();
            
            if (!$shopOwner && !$authenticatedUser) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // Shop owners can always manage permissions
            // Users (managers/HR) can also manage permissions - no specific permission required
            // This allows managers and HR staff to configure employee access

            // Get shop_owner_id from either shop owner or authenticated user
            $shopOwnerId = $shopOwner ? $shopOwner->id : $authenticatedUser->shop_owner_id;

            $user = User::where('id', $userId)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'string|exists:permissions,name',
            ]);

            // SECURITY: Prevent managers from being assigned finance permissions
            // Only shop owners can access finance - as per tech lead requirements
            $financePermissions = [
                'view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses', 'approve-expenses',
                'view-invoices', 'create-invoices', 'edit-invoices', 'delete-invoices', 'send-invoices',
                'approve-payroll', 'process-payroll', // Managers can view payroll but not approve/process
                'view-finance-reports', 'export-finance-reports', 'view-finance-audit-logs',
                'manage-cost-centers', 'view-revenue-accounts', 'reconcile-accounts',
                'approve-repair-pricing', 'approve-shoe-pricing', 'manage-service-pricing', 'edit-pricing',
            ];
            
            if ($user->hasRole('Manager')) {
                $requestedFinancePerms = array_intersect($validated['permissions'], $financePermissions);
                if (!empty($requestedFinancePerms)) {
                    return response()->json([
                        'error' => 'Finance permissions cannot be assigned to managers. Only shop owners can access finance.',
                        'forbidden_permissions' => array_values($requestedFinancePerms)
                    ], 403);
                }
            }

            // Get current direct permissions (before sync)
            $oldDirectPermissions = $user->getDirectPermissions()->pluck('name')->toArray();

            // Get role permissions to preserve them
            $rolePermissions = $user->getPermissionsViaRoles()->pluck('name')->toArray();
            
            // Disable automatic logging to use custom log
            activity()->disableLogging();
            
            // Sync permissions with the correct 'user' guard
            // First clear all direct permissions
            $user->permissions()->detach();
            
            // Then give the new permissions explicitly with 'user' guard
            foreach ($validated['permissions'] as $permissionName) {
                $permission = Permission::where('name', $permissionName)
                    ->where('guard_name', 'user')
                    ->first();
                if ($permission) {
                    $user->givePermissionTo($permission);
                }
            }
            
            // Clear Spatie permission cache for this user
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            
            // Refresh the user model to get updated permissions
            $user->refresh();
            $user->load('roles', 'permissions');
            
            // Re-enable automatic logging
            activity()->enableLogging();
            
            // Get new direct permissions (after sync)
            $newDirectPermissions = $user->getDirectPermissions()->pluck('name')->toArray();
            
            // Calculate added and removed permissions
            $addedPermissions = array_diff($newDirectPermissions, $oldDirectPermissions);
            $removedPermissions = array_diff($oldDirectPermissions, $newDirectPermissions);
            
            // Spatie Activity Log
            $actorName = $shopOwner ? $shopOwner->name : $authenticatedUser->name;
            activity()
                ->causedBy($shopOwner ?? $authenticatedUser)
                ->performedOn($user)
                ->event('updated')
                ->withProperties([
                    'old' => $oldDirectPermissions,
                    'attributes' => $newDirectPermissions,
                    'added' => array_values($addedPermissions),
                    'removed' => array_values($removedPermissions),
                    'actor_name' => $actorName,
                    'actor_type' => $shopOwner ? 'Shop Owner' : 'User',
                    'target_name' => $user->name,
                    'target_email' => $user->email,
                ])
                ->log("Permissions updated for {$user->name} by {$actorName}");
            
            // Permission Audit Log - COMPLIANCE CRITICAL (High severity for bulk changes)
            PermissionAuditLog::logPermissionsSynced(
                $user,
                $oldDirectPermissions,
                $newDirectPermissions,
                $request->input('reason', 'Bulk permission update')
            );

            // Audit log (legacy system)
            try {
                // Get actor ID (shop owner or authenticated user)
                $actorId = $shopOwner ? $shopOwner->id : $authenticatedUser->id;
                
                AuditLog::create([
                    'shop_owner_id' => $shopOwnerId,
                    'actor_user_id' => $actorId,
                    'action' => 'permissions_synced',
                    'target_type' => 'user',
                    'target_id' => $user->id,
                    'metadata' => [
                        'permissions_count' => count($validated['permissions']),
                        'permissions' => $validated['permissions'],
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'actor_type' => $shopOwner ? 'shop_owner' : 'user',
                    ],
                ]);
            } catch (\Exception $e) {
                // Audit log is optional
            }

            return response()->json([
                'success' => true,
                'message' => "Permissions updated for {$user->name}",
                'allPermissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                'rolePermissions' => $user->getPermissionsViaRoles()->pluck('name')->toArray(),
                'directPermissions' => $user->getDirectPermissions()->pluck('name')->toArray(),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to sync permissions: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get available roles for assignment (all roles except primary)
     * Phase 7: Additional Roles
     */
    public function getAvailableRoles(Request $request)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            $authenticatedUser = Auth::guard('user')->user();
            
            if (!$shopOwner && !$authenticatedUser) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // Get all available roles
            $allRoles = Role::where('guard_name', 'user')
                ->get()
                ->map(fn($role) => [
                    'name' => $role->name,
                    'permissionCount' => $role->permissions()->count(),
                ])
                ->toArray();

            return response()->json([
                'success' => true,
                'roles' => $allRoles,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch roles: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update employee's additional roles
     * Phase 7: Additional Roles
     */
    public function syncAdditionalRoles(Request $request, $userId)
    {
        try {
            // Support both shop owner and user (manager/HR) authentication
            $shopOwner = Auth::guard('shop_owner')->user();
            $authenticatedUser = Auth::guard('user')->user();
            
            if (!$shopOwner && !$authenticatedUser) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // Get shop_owner_id from either shop owner or authenticated user
            $shopOwnerId = $shopOwner ? $shopOwner->id : $authenticatedUser->shop_owner_id;

            $user = User::where('id', $userId)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $validated = $request->validate([
                'additional_roles' => 'required|array',
                'additional_roles.*' => 'string|exists:roles,name,guard_name,user',
            ]);

            // Get old roles for audit
            $oldRoles = $user->additional_roles ?? [];
            $newRoles = $validated['additional_roles'];

            // Disable automatic logging to use custom log
            activity()->disableLogging();
            
            // Set the new additional roles
            $user->setAdditionalRoles($newRoles);
            
            // Re-enable automatic logging
            activity()->enableLogging();

            // Calculate added and removed roles
            $addedRoles = array_diff($newRoles, $oldRoles);
            $removedRoles = array_diff($oldRoles, $newRoles);
            
            // Spatie Activity Log
            $actorName = $shopOwner ? $shopOwner->name : $authenticatedUser->name;
            activity()
                ->causedBy($shopOwner ?? $authenticatedUser)
                ->performedOn($user)
                ->event('updated')
                ->withProperties([
                    'old' => $oldRoles,
                    'attributes' => $newRoles,
                    'added' => array_values($addedRoles),
                    'removed' => array_values($removedRoles),
                    'primary_role' => $user->role,
                    'actor_name' => $actorName,
                    'actor_type' => $shopOwner ? 'Shop Owner' : 'User',
                    'target_name' => $user->name,
                    'target_email' => $user->email,
                ])
                ->log("Additional roles updated for {$user->name} by {$actorName}");

            // Permission Audit Log
            PermissionAuditLog::logRolesSynced(
                $user,
                $oldRoles,
                $newRoles,
                "Additional roles updated: " . implode(', ', $newRoles ?: ['none'])
            );

            return response()->json([
                'success' => true,
                'message' => "Roles updated for {$user->name}",
                'primaryRole' => $user->role,
                'additionalRoles' => $user->additional_roles ?? [],
                'allRoles' => $user->getAllRoles(),
                'allPermissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                'rolePermissions' => $user->getPermissionsViaRoles()->pluck('name')->toArray(),
                'directPermissions' => $user->getDirectPermissions()->pluck('name')->toArray(),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to sync roles: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get all available position templates
     * Phase 6+: Position Templates
     */
    public function getPositionTemplates()
    {
        // Support both shop owner and user (manager/HR) authentication
        $shopOwner = Auth::guard('shop_owner')->user();
        $user = Auth::guard('user')->user();
        
        if (!$shopOwner && !$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        // If user (manager/HR), verify they have permission to view employees
        // Shop owners can always view - no specific permission required

        try {
            $templates = PositionTemplate::where('is_active', true)
                ->with('templatePermissions')
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->map(function ($template) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'slug' => $template->slug,
                        'description' => $template->description,
                        'category' => $template->category,
                        'recommended_role' => $template->recommended_role,
                        'permissions' => $template->templatePermissions->pluck('permission_name')->toArray(),
                        'permission_count' => $template->templatePermissions->count(),
                        'usage_count' => $template->usage_count,
                    ];
                });

            // Group by category
            $grouped = $templates->groupBy('category')->map(function ($group) {
                return $group->values();
            });

            return response()->json([
                'templates' => $templates,
                'grouped' => $grouped,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch templates: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Apply a position template to a user
     * Phase 6+: Position Templates
     */
    public function applyPositionTemplate(Request $request, $userId)
    {
        try {
            // Support both shop owner and user (manager/HR) authentication
            $shopOwner = Auth::guard('shop_owner')->user();
            $authenticatedUser = Auth::guard('user')->user();
            
            if (!$shopOwner && !$authenticatedUser) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // If user (manager/HR), they can apply templates
            // Shop owners can always apply - no specific permission required

            // Get shop_owner_id from either shop owner or authenticated user
            $shopOwnerId = $shopOwner ? $shopOwner->id : $authenticatedUser->shop_owner_id;

            $user = User::where('id', $userId)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $validated = $request->validate([
                'template_id' => 'required|integer|exists:position_templates,id',
                'preserve_existing' => 'boolean',
            ]);

            $template = PositionTemplate::findOrFail($validated['template_id']);
            $preserveExisting = $validated['preserve_existing'] ?? true;

            // Apply template
            $appliedPermissions = $template->applyToUser($user, $preserveExisting);

            // Audit log
            try {
                // Get actor ID (shop owner or authenticated user)
                $actorId = $shopOwner ? $shopOwner->id : $authenticatedUser->id;
                
                AuditLog::create([
                    'shop_owner_id' => $shopOwnerId,
                    'actor_user_id' => $actorId,
                    'action' => 'position_template_applied',
                    'target_type' => 'user',
                    'target_id' => $user->id,
                    'metadata' => [
                        'template_name' => $template->name,
                        'template_id' => $template->id,
                        'permissions_count' => count($appliedPermissions),
                        'preserve_existing' => $preserveExisting,
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'actor_type' => $shopOwner ? 'shop_owner' : 'user',
                    ],
                ]);
            } catch (\Exception $e) {
                // Audit log is optional
            }

            return response()->json([
                'success' => true,
                'message' => "Applied '{$template->name}' template to {$user->name}",
                'template' => [
                    'name' => $template->name,
                    'permissions_count' => count($appliedPermissions),
                ],
                'allPermissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                'directPermissions' => $user->getDirectPermissions()->pluck('name')->toArray(),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to apply template: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Regenerate invitation link for an employee
     */
    public function regenerateInvite($userId)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if (!$shopOwner) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // Find the user and verify they belong to this shop owner
            $user = User::where('id', $userId)
                ->where('shop_owner_id', $shopOwner->id)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            // Generate new invitation token
            $inviteToken = Str::random(64);
            $inviteExpiresAt = Carbon::now()->addDays(7);

            // Update user with new invitation token
            $user->update([
                'invite_token' => $inviteToken,
                'invite_expires_at' => $inviteExpiresAt,
                'invited_at' => now(),
                'invited_by' => $shopOwner->id,
            ]);

            // Generate invitation URL
            $inviteUrl = url("/accept-invitation/{$inviteToken}");

            // Log the regeneration
            AuditLog::create([
                'shop_owner_id' => $shopOwner->id,
                'user_id' => $shopOwner->id,
                'action' => 'regenerate_invitation',
                'description' => "Regenerated invitation for employee: {$user->name} ({$user->email})",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'invite_url' => $inviteUrl,
                'invite_expires_at' => $inviteExpiresAt->toIso8601String(),
                'work_email' => $user->email,
                'employee_name' => $user->name,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to regenerate invitation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send invitation email to personal address
     */
    public function sendInvitationEmail(Request $request, $userId)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if (!$shopOwner) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            $validated = $request->validate([
                'personal_email' => 'required|email',
            ]);

            // Find the user and verify they belong to this shop owner
            $user = User::where('id', $userId)
                ->where('shop_owner_id', $shopOwner->id)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            // Check if user has valid invitation token
            if (!$user->invite_token || !$user->invite_expires_at) {
                return response()->json(['error' => 'No active invitation found. Please regenerate the invitation first.'], 400);
            }

            // Check if invitation expired
            if (Carbon::now()->greaterThan($user->invite_expires_at)) {
                return response()->json(['error' => 'Invitation has expired. Please regenerate a new invitation.'], 400);
            }

            $inviteUrl = url("/accept-invitation/{$user->invite_token}");
            $shopName = $shopOwner->business_name ?? 'SoleSpace';
            $expiresAt = $user->invite_expires_at->format('M d, Y h:i A');
            $personalEmail = $validated['personal_email'];

            // Send email using Laravel Mail
            \Mail::send([], [], function ($message) use ($user, $inviteUrl, $shopName, $expiresAt, $personalEmail) {
                $message->to($personalEmail)
                    ->subject("Your {$shopName} Account Invitation")
                    ->html("
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                <h2 style='color: #4F46E5;'>Welcome to {$shopName}!</h2>
                                
                                <p>Hi <strong>{$user->name}</strong>,</p>
                                
                                <p>You've been invited to join our team at <strong>{$shopName}</strong>!</p>
                                
                                <div style='background: #F3F4F6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                                    <p style='margin: 0 0 10px 0;'><strong>Your work email:</strong> {$user->email}</p>
                                    <p style='margin: 0;'><strong>Invitation expires:</strong> {$expiresAt}</p>
                                </div>
                                
                                <p>Click the button below to set up your account and create your password:</p>
                                
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$inviteUrl}' 
                                       style='display: inline-block; background: #4F46E5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                                        Set Up My Account
                                    </a>
                                </div>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    Or copy and paste this link into your browser:<br>
                                    <a href='{$inviteUrl}' style='color: #4F46E5; word-break: break-all;'>{$inviteUrl}</a>
                                </p>
                                
                                <hr style='border: none; border-top: 1px solid #E5E7EB; margin: 20px 0;'>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    <strong>Important:</strong> This invitation link will expire on {$expiresAt}. 
                                    If you need a new invitation, please contact your administrator.
                                </p>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    If you didn't expect this invitation, please ignore this email or contact us.
                                </p>
                            </div>
                        </body>
                        </html>
                    ");
            });

            // Log the email send
            AuditLog::create([
                'shop_owner_id' => $shopOwner->id,
                'user_id' => $shopOwner->id,
                'action' => 'send_invitation_email',
                'description' => "Sent invitation email to {$personalEmail} for employee: {$user->name} ({$user->email})",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Invitation email sent successfully to {$personalEmail}",
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Invalid email address',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send invitation email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get allowed roles for the authenticated shop owner
     * Based on business type and registration type
     */
    public function getAllowedRoles()
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if (!$shopOwner) {
                return response()->json(['error' => 'Not authenticated'], 401);
            }

            // Check if shop owner can manage staff
            if (!$this->accessControl->canManageStaff($shopOwner)) {
                return response()->json([
                    'allowed_roles' => [],
                    'can_manage_staff' => false,
                    'message' => 'Staff management is only available for Company accounts.',
                    'upgrade_required' => true,
                ]);
            }

            $allowedRoles = $this->accessControl->getAllowedRoles($shopOwner);
            $restrictedFeatures = $this->accessControl->getRestrictedFeatures($shopOwner);

            return response()->json([
                'allowed_roles' => $allowedRoles,
                'can_manage_staff' => true,
                'business_type' => $shopOwner->business_type,
                'registration_type' => $shopOwner->registration_type,
                'restricted_features' => $restrictedFeatures,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch allowed roles: ' . $e->getMessage()], 500);
        }
    }
}
