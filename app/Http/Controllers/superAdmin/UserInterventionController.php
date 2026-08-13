<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Concerns\RespondsToAccountLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\AccountArchiveRequest;
use App\Http\Requests\SuperAdmin\AccountReactivationRequest;
use App\Http\Requests\SuperAdmin\AccountRestoreRequest;
use App\Http\Requests\SuperAdmin\AccountSuspensionRequest;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class UserInterventionController extends Controller
{
    use RespondsToAccountLifecycle;

    public function __construct(
        private readonly AccountLifecycleService $accountLifecycle,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function index(Request $request): Response
    {
        $q = $request->input('q');
        $role = $request->input('role');
        $status = $request->input('status');
        $department = $request->input('department');
        $lifecycle = $request->input('lifecycle', 'all');
        if (! in_array($lifecycle, ['active', 'archived', 'all'], true)) {
            $lifecycle = 'all';
        }

        $query = User::withTrashed()
            ->with(['shopOwner', 'employee'])
            ->whereNull('shop_owner_id')
            ->orderBy('created_at', 'desc');

        if ($lifecycle === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($lifecycle === 'archived') {
            $query->whereNotNull('deleted_at');
        }

        if ($q) {
            $query->where(function ($sub) use ($q): void {
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

        if ($status && $status !== 'archived') {
            $query->where('status', $status);
        }

        if ($department) {
            $query->whereHas('employee', function ($employeeQuery) use ($department): void {
                $employeeQuery->where('department', 'like', "%{$department}%");
            });
        }

        $users = $query->paginate(15)->withQueryString()->through(function (User $user): array {
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
                    $roleLabel = $roleLabel ?: null;
            }

            $createdBy = 'Direct Registration';
            if ($user->shop_owner_id && $user->shopOwner) {
                $shop = $user->shopOwner;
                $createdBy = $shop->business_name ?? ($shop->first_name.' '.$shop->last_name) ?? 'Shop Owner';
            }

            $employee = $user->employee;
            $accountStatus = $user->status ?? 'active';
            $archived = $user->trashed();

            return [
                'id' => $user->id,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'name' => $user->name ?? ($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'phone' => $user->phone,
                'age' => $user->age,
                'address' => $user->address,
                'status' => $archived ? 'archived' : $accountStatus,
                'accountStatus' => $accountStatus,
                'archived' => $archived,
                'validIdUrl' => $user->valid_id_path
                    ? route('admin.users.valid-id.show', ['user' => $user->id])
                    : null,
                'role' => $roleLabel,
                'createdBy' => $createdBy,
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'name' => $employee->name ?? null,
                    'phone' => $employee->phone ?? null,
                    'position' => $employee->position,
                    'department' => $employee->department,
                    'branch' => $employee->branch,
                    'functionalRole' => $employee->functional_role,
                    'salary' => $employee->salary,
                    'hireDate' => $employee->hire_date
                        ? Carbon::parse($employee->hire_date)->format('Y-m-d')
                        : null,
                    'status' => $employee->status,
                ] : null,
                'createdAt' => $user->created_at
                    ? Carbon::parse($user->created_at)->format('Y-m-d H:i:s')
                    : null,
                'lastLogin' => $user->last_login_at
                    ? Carbon::parse($user->last_login_at)->format('Y-m-d H:i:s')
                    : null,
            ];
        });

        return Inertia::render('superAdmin/Users/SuperAdminUserManagement', [
            'users' => $users,
        ]);
    }

    public function suspend(AccountSuspensionRequest $request, int $user)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->suspend(
                'user',
                $user,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('suspension_reason'),
            ),
            successMessage: 'User suspended successfully.',
            failures: $this->failures,
        );
    }

    public function reactivate(AccountReactivationRequest $request, int $user)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->reactivate(
                'user',
                $user,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('reactivation_reason'),
            ),
            successMessage: 'User reactivated successfully.',
            failures: $this->failures,
        );
    }

    public function archive(AccountArchiveRequest $request, int $user)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->archive(
                'user',
                $user,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('archive_reason'),
            ),
            successMessage: 'User archived successfully.',
            failures: $this->failures,
        );
    }

    public function restore(AccountRestoreRequest $request, int $user)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->restore(
                'user',
                $user,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('restore_reason'),
            ),
            successMessage: 'User restored successfully.',
            failures: $this->failures,
        );
    }

    private function currentPrivilegedActor(): SuperAdmin
    {
        $actor = auth('super_admin')->user();

        abort_unless($actor instanceof SuperAdmin, 403);

        return $actor;
    }
}
