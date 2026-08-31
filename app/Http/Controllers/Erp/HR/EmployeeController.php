<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Enums\EmployeeStatus;
use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\SuspensionRequest;
use App\Models\User;
use App\Models\HR\LeaveBalance;
use App\Models\HR\AuditLog;
use App\Mail\EmployeeInvitation;
use App\Models\ShopOwner;
use App\Services\BusinessAccessControlService;
use App\Services\HR\EmployeeLinkedUserSynchronizer;
use App\Services\HR\EmployeeOwnerProjection;
use App\Services\HR\EmployeeOperationalPolicy;
use App\Services\Logistics\RiderProfileSyncService;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

class EmployeeController extends Controller
{
    use LogsHRActivity;

    public function __construct(
        private readonly EmployeeLinkedUserSynchronizer $linkedUserSynchronizer,
        private readonly EmployeeOwnerProjection $employeeOwnerProjection,
        private readonly EmployeeOperationalPolicy $employeePolicy,
    ) {
    }

    private function mapLegacyUserRole(string $normalizedRole): string
    {
        return match ($normalizedRole) {
            'CASHIER' => 'STAFF',
            'INVENTORY', 'INVENTORY_MANAGER' => 'INVENTORY',
            'PROCUREMENT', 'PROCUREMENT_MANAGER', 'LOGISTICS_DISPATCHER', 'LOGISTICS_RIDER' => 'STAFF',
            default => $normalizedRole,
        };
    }

    private function resolveAssignableUserRole(string $preferredRoleName): ?string
    {
        $roleAliases = [
            'Inventory Manager' => ['Inventory Manager', 'Inventory'],
            'Procurement Manager' => ['Procurement Manager', 'Procurement'],
        ];

        $candidates = $roleAliases[$preferredRoleName] ?? [$preferredRoleName];

        foreach ($candidates as $candidate) {
            $exists = Role::query()
                ->where('guard_name', 'user')
                ->where('name', $candidate)
                ->exists();

            if ($exists) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Display a listing of employees.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('access-employee-directory')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Employee::byShop($user->shop_owner_id)
            ->with([
                'attendanceRecords',
                'leaveRequests',
                'performanceReviews',
                'employmentPeriods' => fn ($query) => $query->orderByDesc('start_date'),
                'user',
            ]);

        // Apply filters
        if ($request->filled('department')) {
            $query->where('department', $request->department);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $employees = $query->orderBy('name')
            ->paginate($request->get('per_page', 15));

        $employees->setCollection($employees->getCollection()->map(function (Employee $employee): array {
            return $employee->toArray() + [
                'owner_projection' => $this->employeeOwnerProjection->project($employee),
            ];
        }));

        return response()->json($employees);
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:50',
            'lastName' => 'required|string|max:50',
            'email' => 'required|email|unique:employees,email|unique:users,email',
            'phone' => ['nullable', 'regex:/^\d{11}$/', 'unique:employees,phone', 'unique:users,phone'],
            'position' => 'required|string|max:100',
            'department' => 'required|string|max:100',
            'hireDate' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zipCode' => 'nullable|string|max:20',
            'emergencyContact' => 'nullable|string|max:100',
            'emergencyPhone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:100',
            'profileImage' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ], [
            'phone.regex' => 'Phone number must be exactly 11 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rawRole = trim((string) ($request->input('role') ?? $request->input('department') ?? 'Staff'));
        $normalizedRole = strtoupper(str_replace(' ', '_', $rawRole));

        $canonicalRoleMap = [
            'MANAGER' => 'Manager',
            'FINANCE' => 'Finance',
            'HR' => 'HR',
            'CRM' => 'CRM',
            'CASHIER' => 'Cashier',
            'REPAIRER' => 'Repairer',
            'INVENTORY' => 'Inventory Manager',
            'INVENTORY_MANAGER' => 'Inventory Manager',
            'PROCUREMENT' => 'Procurement Manager',
            'PROCUREMENT_MANAGER' => 'Procurement Manager',
            'LOGISTICS_DISPATCHER' => 'Logistics Dispatcher',
            'LOGISTICS_RIDER' => 'Logistics Rider',
            'STAFF' => 'Staff',
        ];

        if (!isset($canonicalRoleMap[$normalizedRole])) {
            return response()->json([
                'errors' => [
                    'role' => ['Invalid role selected.'],
                ],
            ], 422);
        }

        $shopOwner = $user->shopOwner;
        if (!$shopOwner && $user->shop_owner_id) {
            $shopOwner = ShopOwner::find($user->shop_owner_id);
        }

        /** @var BusinessAccessControlService $accessControl */
        $accessControl = app(BusinessAccessControlService::class);

        $roleToValidate = match ($normalizedRole) {
            'REPAIRER' => 'REPAIRER',
            'CASHIER' => 'CASHIER',
            'STAFF' => 'STAFF',
            default => 'MANAGER',
        };

        $roleValidation = $accessControl->validateRoleCreation($roleToValidate, $shopOwner);
        if (!$roleValidation['allowed']) {
            return response()->json([
                'errors' => [
                    'role' => [$roleValidation['reason']],
                ],
            ], 422);
        }

        $spatieRole = $canonicalRoleMap[$normalizedRole];
        $resolvedSpatieRole = $this->resolveAssignableUserRole($spatieRole);
        if (!$resolvedSpatieRole) {
            return response()->json([
                'errors' => [
                    'role' => ["Role configuration for '{$spatieRole}' is missing. Please run role seeding for ERP roles."],
                ],
            ], 422);
        }
        $legacyUserRole = $this->mapLegacyUserRole($normalizedRole);

        // Generate invitation token instead of temporary password
        $inviteToken = Str::random(64);
        $inviteExpiresAt = Carbon::now()->addDays(7);

        // Map camelCase to snake_case for database
        $firstName = $request->firstName ?? $request->first_name ?? '';
        $lastName = $request->lastName ?? $request->last_name ?? '';
        $fullName = trim($firstName . ' ' . $lastName);
        
        // Create both Employee and User atomically
        [$employee, $newUser, $inviteUrl] = DB::transaction(function () use ($request, $user, $firstName, $lastName, $fullName, $inviteToken, $inviteExpiresAt, $resolvedSpatieRole, $legacyUserRole) {
            $data = [
                'shop_owner_id' => $user->shop_owner_id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $fullName,
                'email' => $request->email,
                'phone' => $request->phone ?? null,
                'position' => $request->position,
                'department' => $request->department,
                'hire_date' => $request->hireDate ?? $request->hire_date ?? now(),
                'salary' => $request->salary ?? 0,
                'address' => $request->location ?? $request->address ?? null,
                'city' => $request->city ?? null,
                'state' => $request->state ?? null,
                'zip_code' => $request->zipCode ?? $request->zip_code ?? null,
                'emergency_contact' => $request->emergencyContact ?? $request->emergency_contact ?? null,
                'emergency_phone' => $request->emergencyPhone ?? $request->emergency_phone ?? null,
                'status' => 'active',
            ];

            // Handle profile image upload
            if ($request->hasFile('profileImage')) {
                $path = $request->file('profileImage')->store('employees/profiles', 'public');
                $data['profile_photo'] = $path;
            }

            $employee = Employee::create($data);

            // Create User account for employee with invitation token (no password yet)
            $newUser = User::create([
                'name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $request->email,
                'phone' => $request->phone ?? '',
                'address' => $request->location ?? $request->address ?? '',
                'shop_owner_id' => $user->shop_owner_id,
                // Keep legacy users.role enum-compatible; source of truth remains Spatie roles.
                'role' => $legacyUserRole,
                'position' => $request->position ?? null,
                'password' => null, // No password until invitation is accepted
                'invite_token' => $inviteToken,
                'invite_expires_at' => $inviteExpiresAt,
                'invited_at' => now(),
                'invited_by' => $user->id,
            ]);

            $newUser->assignRole($resolvedSpatieRole);
            app(RiderProfileSyncService::class)->syncUser($newUser);

            // Generate invitation URL
            $inviteUrl = url("/invite/{$inviteToken}");

            // Create initial leave balance
            LeaveBalance::createForNewEmployee(
                $employee->id,
                $user->shop_owner_id,
                date('Y')
            );

            return [$employee, $newUser, $inviteUrl];
        });

        $this->linkedUserSynchronizer->sync($employee);

        // DON'T auto-send email - work email likely doesn't exist yet
        // HR will manually share the link via personal email/WhatsApp/SMS
        $emailSent = false;

        // Audit log
        $this->auditCreated(
            AuditLog::MODULE_EMPLOYEE,
            $employee,
            "Employee created: {$employee->first_name} {$employee->last_name} ({$employee->position})",
            ['onboarding', 'invitation_sent' => $emailSent]
        );

        return response()->json([
            'message' => 'Employee created successfully. Share the invitation link with the employee.',
            'employee' => $employee->load(['leaveBalances']),
            'user_id' => $newUser->id,
            'invite_url' => $inviteUrl,
            'invite_expires_at' => $inviteExpiresAt->toDateTimeString(),
            'email_sent' => $emailSent,
            'work_email' => $newUser->email, // Work email (for reference only)
            'csrf_token' => csrf_token(),
        ], 201);
    }

    /**
     * Display the specified employee.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->with([
                'attendanceRecords' => function ($query) {
                    $query->latest()->take(10);
                },
                'leaveRequests' => function ($query) {
                    $query->latest()->take(10);
                },
                'payrolls' => function ($query) {
                    $query->latest()->take(5);
                },
                'performanceReviews' => function ($query) {
                    $query->latest()->take(5);
                },
                'employmentPeriods' => function ($query) {
                    $query->orderByDesc('start_date');
                },
                'leaveBalances' => function ($query) {
                    $query->where('year', date('Y'));
                },
                'user.roles',
                'user.permissions'
            ])
            ->findOrFail($id);

        // Add user_id and permissions to response
        $response = $employee->toArray();
        $response['user_id'] = $employee->user?->id;
        $response['permissions'] = $employee->user?->getAllPermissions()->pluck('name')->toArray() ?? [];
        $response['direct_permissions'] = $employee->user?->permissions->pluck('name')->toArray() ?? [];
        $response['role_permissions'] = $employee->user?->getPermissionsViaRoles()->pluck('name')->toArray() ?? [];
        $response['owner_projection'] = $this->employeeOwnerProjection->project($employee);

        return response()->json($response);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($id);

        if ($request->has('status')
            && EmployeeStatus::tryFrom(strtolower(trim((string) $request->input('status')))) === EmployeeStatus::TERMINATED) {
            return $this->terminationWorkflowRequiredResponse();
        }

        if ($request->has('status') && $this->isDirectSuspensionStateMutation($employee, (string) $request->input('status'))) {
            return $this->suspensionWorkflowRequiredResponse();
        }

        $validator = Validator::make($request->all(), [
            'firstName' => 'sometimes|required|string|max:50',
            'lastName' => 'sometimes|required|string|max:50',
            'email' => 'sometimes|required|email|unique:employees,email,' . $employee->id,
            'phone' => 'sometimes|required|regex:/^\d{11}$/',
            'position' => 'sometimes|required|string|max:100',
            'department' => 'sometimes|required|string|max:100',
            'hireDate' => 'sometimes|required|date',
            'status' => ['sometimes', 'required', Rule::enum(EmployeeStatus::class)],
            'address' => 'sometimes|required|string',
            'city' => 'sometimes|required|string|max:100',
            'state' => 'sometimes|required|string|max:100',
            'zipCode' => 'sometimes|required|string|max:20',
            'emergencyContact' => 'sometimes|required|string|max:100',
            'emergencyPhone' => 'sometimes|required|string|max:20',
            'suspensionReason' => 'nullable|string',
            'profileImage' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ], [
            'phone.regex' => 'Phone number must be exactly 11 digits.',
        ]);

        // Salary changes must go through the dedicated workflow (Phase 7).
        if ($request->has('salary')) {
            return response()->json([
                'error' => 'Direct salary edits are disabled. Use POST /api/hr/salary-changes to submit a salary change request.',
                'code'  => 'USE_SALARY_CHANGE_WORKFLOW',
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('status') && ! $this->employeePolicy->canChangeAccountState($employee, (string) $request->status)) {
            return response()->json([
                'error' => 'Terminated employees must use the Rehire / Reinstate Employee workflow.',
                'code' => 'EMPLOYEE_REHIRE_REQUIRED',
            ], 422);
        }

        // Map camelCase to snake_case for database
        $data = [];
        if ($request->has('firstName')) $data['first_name'] = $request->firstName ?? '';
        if ($request->has('lastName')) $data['last_name'] = $request->lastName ?? '';
        if ($request->has('firstName') || $request->has('lastName')) {
            $firstName = $request->firstName ?? $employee->first_name ?? '';
            $lastName = $request->lastName ?? $employee->last_name ?? '';
            $data['name'] = trim($firstName . ' ' . $lastName);
        }
        if ($request->has('email')) $data['email'] = $request->email;
        if ($request->has('phone')) $data['phone'] = $request->phone;
        if ($request->has('position')) $data['position'] = $request->position;
        if ($request->has('department')) $data['department'] = $request->department;
        if ($request->has('hireDate')) $data['hire_date'] = $request->hireDate;
        if ($request->has('salary')) $data['salary'] = $request->salary;
        if ($request->has('status')) {
            $data['status'] = $request->status;
            $data['privileged_suspension_id'] = null;
        }
        if ($request->has('suspensionReason')) $data['suspension_reason'] = $request->suspensionReason;
        if ($request->has('location')) $data['address'] = $request->location;
        if ($request->has('address')) $data['address'] = $request->address;
        if ($request->has('city')) $data['city'] = $request->city;
        if ($request->has('state')) $data['state'] = $request->state;
        if ($request->has('zipCode')) $data['zip_code'] = $request->zipCode;
        if ($request->has('emergencyContact')) $data['emergency_contact'] = $request->emergencyContact;
        if ($request->has('emergencyPhone')) $data['emergency_phone'] = $request->emergencyPhone;

        $oldValues = $employee->only(array_keys($data));

        // Handle profile image upload
        if ($request->hasFile('profileImage')) {
            // Delete old image
            if ($employee->profile_photo) {
                Storage::disk('public')->delete($employee->profile_photo);
            }
            
            $path = $request->file('profileImage')->store('employees/profiles', 'public');
            $data['profile_photo'] = $path;
        }

        $employee->update($data);

        if (isset($data['status'])) {
            $this->linkedUserSynchronizer->sync($employee);
        }

        // Audit log
        $this->auditUpdated(
            AuditLog::MODULE_EMPLOYEE,
            $employee,
            $oldValues,
            "Employee updated: {$employee->first_name} {$employee->last_name}",
            ['employee_management']
        );

        return response()->json([
            'message' => 'Employee updated successfully',
            'employee' => $employee
        ]);
    }

    /**
     * Remove the specified employee.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($id);

        // Delete profile image
        if ($employee->profileImage) {
            Storage::disk('public')->delete($employee->profileImage);
        }

        $employee->delete();

        return response()->json(['message' => 'Employee deleted successfully']);
    }

    /**
     * Keep the legacy direct suspension endpoint from bypassing the approval workflow.
     */
    public function suspend(Request $request, $id): JsonResponse
    {
        return $this->suspensionWorkflowRequiredResponse();
    }

    /**
     * Reactivate a suspended employee and linked user login account.
     */
    public function activate(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user || (! $user->hasRole('Manager') && ! $user->can('access-employee-directory') && ! $user->can('access-attendance-records') && ! $user->can('access-payslip-generation'))) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($id);

        if (! $this->employeePolicy->canChangeAccountState($employee, EmployeeStatus::ACTIVE)) {
            return response()->json([
                'error' => 'Terminated employees must use the Rehire / Reinstate Employee workflow.',
                'code' => 'EMPLOYEE_REHIRE_REQUIRED',
            ], 422);
        }

        $oldValues = [
            'status' => $employee->getRawOriginal('status'),
            'suspension_reason' => $employee->getRawOriginal('suspension_reason'),
        ];

        $employee = DB::transaction(function () use ($employee): Employee {
            $lockedEmployee = Employee::query()
                ->whereKey($employee->getKey())
                ->where('shop_owner_id', $employee->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedEmployee->forceFill([
                'status' => EmployeeStatus::ACTIVE,
                'suspension_reason' => null,
                'privileged_suspension_id' => null,
            ])->save();

            $this->linkedUserSynchronizer->sync($lockedEmployee);

            return $lockedEmployee->fresh();
        });

        $this->auditUpdated(
            AuditLog::MODULE_EMPLOYEE,
            $employee,
            $oldValues,
            "Employee account activated: {$employee->first_name} {$employee->last_name}",
            ['employee_management', 'account_state']
        );

        return response()->json([
            'message' => 'Employee account reactivated successfully',
            'employee' => $employee,
        ]);
    }

    private function isDirectSuspensionStateMutation(Employee $employee, string $targetStatus): bool
    {
        $target = EmployeeStatus::tryFrom(strtolower(trim($targetStatus)));
        $current = $employee->status instanceof EmployeeStatus ? $employee->status : null;

        if ($target === EmployeeStatus::SUSPENDED || $current === EmployeeStatus::SUSPENDED) {
            return true;
        }

        return SuspensionRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [SuspensionStatus::PENDING_MANAGER, SuspensionStatus::PENDING_OWNER])
            ->exists();
    }

    private function suspensionWorkflowRequiredResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Employee suspension changes must go through the HR → Manager → Shop Owner workflow.',
            'code' => 'SUSPENSION_WORKFLOW_REQUIRED',
        ], 403);
    }

    private function terminationWorkflowRequiredResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Employment termination must go through the HR → Manager → Shop Owner workflow.',
            'code' => 'TERMINATION_WORKFLOW_REQUIRED',
        ], 403);
    }

    /**
     * Get employee statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('access-employee-directory') && !$user->can('access-attendance-records') && !$user->can('access-payslip-generation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employees = Employee::forShopOwner($user->shop_owner_id)
            ->with('leaveRequests')
            ->get();
        $projections = $employees->map(
            fn (Employee $employee): array => $this->employeeOwnerProjection->project($employee),
        );
        $totalEmployees = $projections->count();
        $activeEmployees = $projections->where('account_state', EmployeeStatus::ACTIVE->value)->count();
        $onLeaveEmployees = $projections->where('on_leave', true)->count();
        $probationEmployees = $projections->where('probation', true)->count();
        $suspendedEmployees = $projections->where('account_state', EmployeeStatus::SUSPENDED->value)->count();

        return response()->json([
            'totalEmployees' => $totalEmployees,
            'activeEmployees' => $activeEmployees,
            'onLeaveEmployees' => $onLeaveEmployees,
            'probationEmployees' => $probationEmployees,
            'suspendedEmployees' => $suspendedEmployees,
            'inactiveEmployees' => $totalEmployees - $activeEmployees,
        ]);
    }
}
