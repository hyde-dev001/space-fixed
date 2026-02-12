<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Department;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class DepartmentController extends Controller
{
    use LogsHRActivity;
    /**
     * Display a listing of departments.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-employees')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Department::forShopOwner($user->shop_owner_id)
            ->with(['employees:id,first_name,last_name,department,status']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('manager', 'like', "%{$search}%");
            });
        }

        $departments = $query->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json($departments);
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:departments,name,NULL,id,shop_owner_id,' . $user->shop_owner_id,
            'manager' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['shop_owner_id'] = $user->shop_owner_id;
        $data['employee_count'] = 0;

        $department = Department::create($data);

        return response()->json([
            'message' => 'Department created successfully',
            'department' => $department
        ], 201);
    }

    /**
     * Display the specified department.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $department = Department::forShopOwner($user->shop_owner_id)
            ->with(['employees' => function ($query) {
                $query->select('id', 'first_name', 'last_name', 'email', 'position', 'department', 'status', 'hire_date');
            }])
            ->findOrFail($id);

        return response()->json($department);
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $department = Department::forShopOwner($user->shop_owner_id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100|unique:departments,name,' . $department->id . ',id,shop_owner_id,' . $user->shop_owner_id,
            'manager' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $oldName = $department->name;
        $department->update($validator->validated());

        // If department name changed, update all employees in this department
        if (isset($validator->validated()['name']) && $oldName !== $validator->validated()['name']) {
            Employee::forShopOwner($user->shop_owner_id)
                ->where('department', $oldName)
                ->update(['department' => $validator->validated()['name']]);
        }

        // Update employee count
        $department->updateEmployeeCount();

        return response()->json([
            'message' => 'Department updated successfully',
            'department' => $department->load('employees')
        ]);
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $department = Department::forShopOwner($user->shop_owner_id)->findOrFail($id);

        // Check if department has employees
        $employeeCount = Employee::forShopOwner($user->shop_owner_id)
            ->where('department', $department->name)
            ->count();

        if ($employeeCount > 0) {
            return response()->json([
                'error' => "Cannot delete department with {$employeeCount} employees. Please move or remove employees first."
            ], 422);
        }

        $department->delete();

        return response()->json(['message' => 'Department deleted successfully']);
    }

    /**
     * Get department statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $departments = Department::forShopOwner($user->shop_owner_id)->get();

        $stats = $departments->map(function ($department) use ($user) {
            $employees = Employee::forShopOwner($user->shop_owner_id)
                ->where('department', $department->name)
                ->get();

            return [
                'id' => $department->id,
                'name' => $department->name,
                'manager' => $department->manager,
                'totalEmployees' => $employees->count(),
                'activeEmployees' => $employees->where('status', 'active')->count(),
                'onLeaveEmployees' => $employees->where('status', 'on-leave')->count(),
                'inactiveEmployees' => $employees->where('status', 'inactive')->count(),
                'suspendedEmployees' => $employees->where('status', 'suspended')->count(),
            ];
        });

        return response()->json($stats);
    }

    /**
     * Get all departments (simple list).
     */
    public function list(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $departments = Department::forShopOwner($user->shop_owner_id)
            ->select('id', 'name', 'manager', 'employee_count')
            ->orderBy('name')
            ->get();

        return response()->json($departments);
    }
}