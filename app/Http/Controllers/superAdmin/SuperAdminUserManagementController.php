<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Carbon;

class SuperAdminUserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->input('q');
        $role = $request->input('role');
        $status = $request->input('status');
        $department = $request->input('department');

        $query = User::with(['shopOwner', 'employee'])
            ->orderBy('created_at', 'desc');

        // Search by name/email/phone
        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        if ($role) {
            $query->where('role', $role);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($department) {
            $query->whereHas('employee', function ($e) use ($department) {
                $e->where('department', 'like', "%{$department}%");
            });
        }

        $users = $query->paginate(15)->withQueryString()->through(function ($user) {
                // Map role codes to human friendly labels when appropriate
                $roleLabel = $user->role ?: null;
                switch (strtoupper($roleLabel ?? '')) {
                    case 'HR':
                        $roleLabel = 'HR';
                        break;
                    case 'FINANCE_STAFF':
                        $roleLabel = 'Finance Staff';
                        break;
                    case 'FINANCE_MANAGER':
                        $roleLabel = 'Finance Manager';
                        break;
                    case 'CRM':
                        $roleLabel = 'CRM';
                        break;
                    case 'MANAGER':
                        $roleLabel = 'Manager';
                        break;
                    case 'STAFF':
                        $roleLabel = 'Staff';
                        break;
                    default:
                        // Keep null so frontend can fall back to "Customer"
                        $roleLabel = $roleLabel ?: null;
                }

                // Determine who created the account (shop owner vs direct registration)
                $createdBy = 'Direct Registration';
                if ($user->shop_owner_id && $user->shopOwner) {
                    $shop = $user->shopOwner;
                    $createdBy = $shop->business_name ?? ($shop->first_name . ' ' . $shop->last_name) ?? 'Shop Owner';
                }

                $employee = $user->employee; // eager-loaded

                return [
                    'id' => $user->id,
                    'firstName' => $user->first_name,
                    'lastName' => $user->last_name,
                    'name' => $user->name ?? ($user->first_name . ' ' . $user->last_name),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'age' => $user->age,
                    'address' => $user->address,
                    'status' => $user->status,
                    'validIdPath' => $user->valid_id_path,
                    'role' => $roleLabel,
                    'createdBy' => $createdBy,
                    // Include employee-specific HR fields when available
                    'employee' => $employee ? [
                            'id' => $employee->id,
                            'name' => $employee->name ?? null,
                            'phone' => $employee->phone ?? null,
                            'position' => $employee->position,
                            'department' => $employee->department,
                            'branch' => $employee->branch,
                            'functionalRole' => $employee->functional_role,
                            'salary' => $employee->salary,
                            'hireDate' => $employee->hire_date ? Carbon::parse($employee->hire_date)->format('Y-m-d') : null,
                            'status' => $employee->status,
                        ] : null,
                    'createdAt' => $user->created_at ? Carbon::parse($user->created_at)->format('Y-m-d H:i:s') : null,
                    'lastLogin' => $user->last_login_at ? Carbon::parse($user->last_login_at)->format('Y-m-d H:i:s') : null,
                ];
            });

        // Return paginated payload for Inertia (items already transformed via through())
        return Inertia::render('superAdmin/SuperAdminUserManagement', [
            'users' => $users,
        ]);
    }
}
