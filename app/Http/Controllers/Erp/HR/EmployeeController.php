<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\HR\LeaveBalance;
use App\Models\HR\AuditLog;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    use LogsHRActivity;
    /**
     * Display a listing of employees.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-employees')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Employee::byShop($user->shop_owner_id)
            ->with(['attendanceRecords', 'leaveRequests', 'performanceReviews', 'user']);

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

        return response()->json($employees);
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:50',
            'lastName' => 'required|string|max:50',
            'email' => 'required|email|unique:employees,email|unique:users,email',
            'phone' => 'nullable|string|max:20',
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
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Generate temporary password
        $temporaryPassword = Str::random(10);

        // Map camelCase to snake_case for database
        $firstName = $request->firstName ?? $request->first_name ?? '';
        $lastName = $request->lastName ?? $request->last_name ?? '';
        $fullName = trim($firstName . ' ' . $lastName);
        
        // Create both Employee and User atomically
        [$employee, $newUser] = DB::transaction(function () use ($request, $user, $firstName, $lastName, $fullName, $temporaryPassword) {
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

            // Create User account for employee
            $newUser = User::create([
                'name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $request->email,
                'phone' => $request->phone ?? '',
                'address' => $request->location ?? $request->address ?? '',
                'shop_owner_id' => $user->shop_owner_id,
                'role' => $request->department, // Use department as role (Manager, Finance, HR, CRM, Staff)
                'position' => $request->position ?? null,
                'password' => Hash::make($temporaryPassword),
                'force_password_change' => true,
            ]);

            // Assign Spatie role based on department
            $roleMap = [
                'Manager' => 'Manager',
                'Finance' => 'Finance',
                'HR' => 'HR',
                'CRM' => 'CRM',
                'Staff' => 'Staff',
            ];
            
            $spatieRole = $roleMap[$request->department] ?? 'Staff';
            $newUser->assignRole($spatieRole);

            // Create initial leave balance
            LeaveBalance::createForNewEmployee(
                $employee->id,
                $user->shop_owner_id,
                date('Y')
            );

            return [$employee, $newUser];
        });

        // Audit log
        $this->auditCreated(
            AuditLog::MODULE_EMPLOYEE,
            $employee,
            "Employee created: {$employee->first_name} {$employee->last_name} ({$employee->position})",
            ['onboarding']
        );

        return response()->json([
            'message' => 'Employee created successfully',
            'employee' => $employee->load(['leaveBalances']),
            'user_id' => $newUser->id,
            'temporary_password' => $temporaryPassword,
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
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
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

        return response()->json($response);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'firstName' => 'sometimes|required|string|max:50',
            'lastName' => 'sometimes|required|string|max:50',
            'email' => 'sometimes|required|email|unique:employees,email,' . $employee->id,
            'phone' => 'sometimes|required|string|max:20',
            'position' => 'sometimes|required|string|max:100',
            'department' => 'sometimes|required|string|max:100',
            'hireDate' => 'sometimes|required|date',
            'salary' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:active,inactive,on-leave,suspended',
            'address' => 'sometimes|required|string',
            'city' => 'sometimes|required|string|max:100',
            'state' => 'sometimes|required|string|max:100',
            'zipCode' => 'sometimes|required|string|max:20',
            'emergencyContact' => 'sometimes|required|string|max:100',
            'emergencyPhone' => 'sometimes|required|string|max:20',
            'suspensionReason' => 'nullable|string',
            'profileImage' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
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
        if ($request->has('status')) $data['status'] = $request->status;
        if ($request->has('suspensionReason')) $data['suspension_reason'] = $request->suspensionReason;
        if ($request->has('location')) $data['address'] = $request->location;
        if ($request->has('address')) $data['address'] = $request->address;
        if ($request->has('city')) $data['city'] = $request->city;
        if ($request->has('state')) $data['state'] = $request->state;
        if ($request->has('zipCode')) $data['zip_code'] = $request->zipCode;
        if ($request->has('emergencyContact')) $data['emergency_contact'] = $request->emergencyContact;
        if ($request->has('emergencyPhone')) $data['emergency_phone'] = $request->emergencyPhone;

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

        // Audit log
        $this->auditUpdated(
            AuditLog::MODULE_EMPLOYEE,
            $employee,
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
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
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
     * Suspend an employee.
     */
    public function suspend(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::forShopOwner($user->shop_owner_id)->findOrFail($id);

        $employee->update([
            'status' => 'suspended',
            'suspensionReason' => $request->reason,
        ]);

        return response()->json([
            'message' => 'Employee suspended successfully',
            'employee' => $employee
        ]);
    }

    /**
     * Get employee statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $totalEmployees = Employee::forShopOwner($user->shop_owner_id)->count();
        $activeEmployees = Employee::forShopOwner($user->shop_owner_id)->active()->count();
        $onLeaveEmployees = Employee::forShopOwner($user->shop_owner_id)->withStatus('on-leave')->count();
        $suspendedEmployees = Employee::forShopOwner($user->shop_owner_id)->withStatus('suspended')->count();

        return response()->json([
            'totalEmployees' => $totalEmployees,
            'activeEmployees' => $activeEmployees,
            'onLeaveEmployees' => $onLeaveEmployees,
            'suspendedEmployees' => $suspendedEmployees,
            'inactiveEmployees' => $totalEmployees - $activeEmployees,
        ]);
    }
}