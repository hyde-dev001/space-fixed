<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\SuperAdmin;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SuperAdminController extends Controller
{
    /**
     * Show create admin form
     */
    public function showCreateAdmin(): Response
    {
        return Inertia::render('superAdmin/CreateAdmin');
    }
    /**
     * Show admin management page
     */
    public function showAdminManagement(): Response
    {
        // Get current authenticated admin ID
        $currentAdminId = auth('super_admin')->id();

        // Fetch all super admins except the currently logged in one
        $admins = SuperAdmin::where('id', '!=', $currentAdminId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($admin) {
                return [
                    'id' => $admin->id,
                    'firstName' => $admin->first_name,
                    'lastName' => $admin->last_name,
                    'email' => $admin->email,
                    'status' => $admin->status,
                    'createdAt' => $admin->created_at->format('Y-m-d H:i:s'),
                    'lastLogin' => $admin->last_login ? $admin->last_login->format('Y-m-d H:i:s') : null,
                ];
            });

        // Calculate statistics
        $stats = [
            'total' => $admins->count(),
            'active' => $admins->where('status', 'active')->count(),
            'suspended' => $admins->where('status', 'suspended')->count(),
            'inactive' => $admins->where('status', 'inactive')->count(),
        ];

        return Inertia::render('superAdmin/AdminManagement', [
            'admins' => $admins,
            'stats' => $stats
        ]);
    }

    /**
     * Show shop registrations page
     */
    public function showShopRegistrations(): Response
    {
        return Inertia::render('superAdmin/ShopRegistrations', [
            'registrations' => []
        ]);
    }

    /**
     * Show registered shops page
     */
    public function showRegisteredShops(): Response
    {
        // Fetch all approved shop owners (including suspended ones)
        $shops = ShopOwner::whereIn('status', ['approved', 'suspended'])
            ->with('documents')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($shopOwner) {
                return [
                    'id' => $shopOwner->id,
                    'first_name' => $shopOwner->first_name,
                    'last_name' => $shopOwner->last_name,
                    'fullName' => $shopOwner->full_name,
                    'email' => $shopOwner->email,
                    'contact_number' => $shopOwner->phone,
                    'phone' => $shopOwner->phone,
                    'business_name' => $shopOwner->business_name,
                    'business_address' => $shopOwner->business_address,
                    'business_type' => $shopOwner->business_type,
                    'registration_type' => $shopOwner->registration_type,
                    'operating_hours' => is_array($shopOwner->operating_hours) ? $shopOwner->operating_hours : [],
                    'status' => $shopOwner->status,
                    'suspension_reason' => $shopOwner->suspension_reason,
                    'created_at' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                    'approved_at' => $shopOwner->updated_at->format('Y-m-d H:i:s'),
                    'documentUrls' => $shopOwner->documents->map(function ($doc) {
                        return '/storage/' . $doc->file_path;
                    })->toArray(),
                ];
            });

        // Calculate statistics
        $stats = [
            'total' => $shops->count(),
            'active' => $shops->where('status', 'approved')->count(),
            'suspended' => $shops->where('status', 'suspended')->count(),
            'thisMonth' => ShopOwner::whereIn('status', ['approved', 'suspended'])
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return Inertia::render('superAdmin/RegisteredShops', [
            'shops' => $shops,
            'stats' => $stats
        ]);
    }

    /**
     * Show data reports dashboard (placeholder values for now)
     */
    public function showDataReports(): Response
    {
        return Inertia::render('superAdmin/DataReportAccess', [
            'stats' => [
                'totalUsers' => 0,
                'totalShopOwners' => 0,
                'pendingApprovals' => 0,
                'approvedShopOwners' => 0,
                'rejectedShopOwners' => 0,
                'recentRegistrations' => 0,
                'recentApprovals' => 0,
                'totalDocuments' => 0,
                'pendingDocuments' => 0,
                'approvedDocuments' => 0,
                'monthlyGrowth' => [
                    'current' => 0,
                    'previous' => 0,
                    'percentage' => 0,
                ],
            ],
        ]);
    }

    /**
     * Show user management page (placeholder values for now)
     */
    public function showUserManagement(): Response
    {
        // Load users with optional employee relation so Shop Owner suspends reflect here
        $usersQuery = User::orderBy('created_at', 'desc')->with(['employee']);
        $users = $usersQuery->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'firstName' => $u->first_name ?? null,
                'lastName' => $u->last_name ?? null,
                'name' => $u->name,
                'email' => $u->email,
                'address' => $u->address ?? null,
                'phone' => $u->phone ?? null,
                'age' => $u->age ?? null,
                'role' => $u->role ?? null,
                'status' => $u->status ?? 'active',
                'createdAt' => $u->created_at?->toDateTimeString(),
                'lastLogin' => $u->last_login?->toDateTimeString() ?? null,
                'employee' => $u->employee ? [
                    'id' => $u->employee->id,
                    'name' => $u->employee->name,
                    'phone' => $u->employee->phone,
                    'position' => $u->employee->position,
                    'department' => $u->employee->department,
                    'branch' => $u->employee->branch,
                    'functionalRole' => $u->employee->functional_role,
                    'salary' => $u->employee->salary,
                    'hireDate' => $u->employee->hire_date,
                    'status' => $u->employee->status,
                ] : null,
            ];
        })->toArray();

        $stats = [
            'total' => count($users),
            'active' => collect($users)->where('status', 'active')->count(),
            'suspended' => collect($users)->where('status', 'suspended')->count(),
            'thisMonth' => User::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
        ];

        return Inertia::render('superAdmin/SuperAdminUserManagement', [
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    /**
     * Admin action stubs
     */
    public function suspendUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $user->update(['status' => 'suspended']);

            // If this user is associated with an employee record, mark employee as inactive too
            try {
                $employee = \App\Models\Employee::where('email', $user->email)->first();
                if ($employee) {
                    $employee->update(['status' => 'inactive']);
                }
            } catch (\Exception $e) {
                // non-fatal
            }

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'user_suspended',
                'target_type' => 'user',
                'target_id' => $user->id,
                'metadata' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Inertia')) {
                return response()->json(['success' => true, 'message' => 'User suspended successfully.']);
            }

            return redirect()->back()->with('success', 'User suspended successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to suspend user.']);
        }
    }

    public function activateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $user->update(['status' => 'active']);

            // If this user is associated with an employee record, mark employee as active too
            try {
                $employee = \App\Models\Employee::where('email', $user->email)->first();
                if ($employee) {
                    $employee->update(['status' => 'active']);
                }
            } catch (\Exception $e) {
                // non-fatal
            }

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'user_activated',
                'target_type' => 'user',
                'target_id' => $user->id,
                'metadata' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Inertia')) {
                return response()->json(['success' => true, 'message' => 'User activated successfully.']);
            }

            return redirect()->back()->with('success', 'User activated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to activate user.']);
        }
    }

    /**
     * Return JSON list of users for polling
     */
    public function usersList(Request $request)
    {
        $status = $request->query('status');
        $query = User::orderBy('created_at', 'desc')->with('employee');
        if ($status) {
            $query->where('status', $status);
        }

        $users = $query->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status ?? 'active',
                'role' => $u->role ?? null,
                'createdAt' => $u->created_at?->toDateTimeString(),
                'employee' => $u->employee ? [
                    'id' => $u->employee->id,
                    'status' => $u->employee->status,
                ] : null,
            ];
        });

        return response()->json(['data' => $users], 200);
    }

    public function deleteUser($id)
    {
        try {
            $user = User::findOrFail($id);
            $payload = [
                'email' => $user->email,
                'name' => $user->name,
            ];

            $user->delete();

            // Audit log the deletion by the super admin
            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'user_deleted',
                'target_type' => 'user',
                'target_id' => $id,
                'metadata' => $payload,
            ]);

            return redirect()->back()->with('success', 'User deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to delete user.']);
        }
    }

    public function suspendAdmin($id)
    {
        try {
            $admin = SuperAdmin::findOrFail($id);
            $admin->update(['status' => 'suspended']);

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'admin_suspended',
                'target_type' => 'super_admin',
                'target_id' => $admin->id,
                'metadata' => [
                    'email' => $admin->email,
                    'first_name' => $admin->first_name,
                    'last_name' => $admin->last_name,
                ],
            ]);

            return redirect()->back()->with('success', 'Admin suspended successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to suspend admin.']);
        }
    }

    public function activateAdmin($id)
    {
        try {
            $admin = SuperAdmin::findOrFail($id);
            $admin->update(['status' => 'active']);

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'admin_activated',
                'target_type' => 'super_admin',
                'target_id' => $admin->id,
                'metadata' => [
                    'email' => $admin->email,
                    'first_name' => $admin->first_name,
                    'last_name' => $admin->last_name,
                ],
            ]);

            return redirect()->back()->with('success', 'Admin activated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to activate admin.']);
        }
    }

    public function deleteAdmin($id)
    {
        try {
            $admin = SuperAdmin::findOrFail($id);
            $payload = [
                'email' => $admin->email,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
            ];

            $admin->delete();

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'admin_deleted',
                'target_type' => 'super_admin',
                'target_id' => $id,
                'metadata' => $payload,
            ]);

            return redirect()->back()->with('success', 'Admin deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to delete admin.']);
        }
    }

    public function suspendShop(Request $request, $id)
    {
        $validated = $request->validate([
            'suspension_reason' => 'nullable|string|max:1000'
        ]);

        try {
            $shop = ShopOwner::findOrFail($id);
            $shop->update([
                'status' => 'suspended',
                'suspension_reason' => $validated['suspension_reason'] ?? null
            ]);

            return redirect()->back()->with('success', 'Shop suspended successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to suspend shop. Please try again.']);
        }
    }

    public function activateShop($id)
    {
        try {
            $shop = ShopOwner::findOrFail($id);
            $shop->update([
                'status' => 'approved',
                'suspension_reason' => null
            ]);

            // Audit log activation
            AuditLog::create([
                'shop_owner_id' => $shop->id,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'shop_activated',
                'target_type' => 'shop_owner',
                'target_id' => $shop->id,
                'metadata' => [
                    'email' => $shop->email,
                    'business_name' => $shop->business_name ?? null,
                ],
            ]);

            return redirect()->back()->with('success', 'Shop activated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to activate shop. Please try again.']);
        }
    }

    public function deleteShop($id)
    {
        try {
            $shop = ShopOwner::findOrFail($id);

            $payload = [
                'email' => $shop->email,
                'business_name' => $shop->business_name ?? null,
            ];

            $shop->delete();

            // Audit log shop deletion
            AuditLog::create([
                'shop_owner_id' => $id,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'shop_deleted',
                'target_type' => 'shop_owner',
                'target_id' => $id,
                'metadata' => $payload,
            ]);

            return redirect()->back()->with('success', 'Shop deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to delete shop. Please try again.']);
        }
    }

    /**
     * Show flagged accounts page
     */
    public function showFlaggedAccounts(): Response
    {
        return Inertia::render('superAdmin/FlaggedAccounts');
    }

    /**
     * Approve shop owner registration
     */
    public function approveShopOwner(Request $request, $id)
    {
        try {
            $shop = ShopOwner::findOrFail($id);

            $shop->update([
                'status' => 'approved',
            ]);

            // Audit log approval action
            AuditLog::create([
                'shop_owner_id' => $shop->id,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'shop_owner_approved',
                'target_type' => 'shop_owner',
                'target_id' => $shop->id,
                'metadata' => [
                    'email' => $shop->email,
                    'business_name' => $shop->business_name ?? null,
                ],
            ]);

            return back()->with('success', 'Shop owner approved successfully');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to approve shop owner']);
        }
    }

    /**
     * Reject shop owner registration
     */
    public function rejectShopOwner(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string'
        ]);

        // TODO: Implement rejection logic
        return back()->with('success', 'Shop owner rejected successfully');
    }

    /**
     * Create a new admin
     */
    public function createAdmin(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|min:2|max:255',
            'last_name' => 'required|string|min:2|max:255',
            'email' => 'required|email|unique:super_admins,email',
            'phone' => 'required|string|min:10|max:20',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers(), 'confirmed'],
            'role' => 'required|string|in:admin,super_admin',
        ], [
            'email.unique' => 'This email address is already registered.',
            'password.min' => 'Password must be at least 8 characters.',
        ]);

        try {
            // Create the admin account
            $admin = SuperAdmin::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'status' => 'active',
            ]);

            return redirect()->route('admin.admin-management')
                ->with('success', 'Admin account created successfully');
        } catch (\Exception $e) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['error' => 'Failed to create admin account. Please try again.']);
        }
    }
}
