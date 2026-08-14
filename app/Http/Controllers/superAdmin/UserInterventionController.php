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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['prohibited'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                'active',
                'suspended',
                'inactive',
                'pending',
                'approved',
                'rejected',
                'deactivated',
                'archived',
            ])],
            'department' => ['prohibited'],
            'lifecycle' => ['sometimes', 'nullable', Rule::in(['active', 'archived', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $lifecycle = $validated['lifecycle'] ?? 'all';

        $baseQuery = User::withTrashed()->whereNull('shop_owner_id');
        $query = (clone $baseQuery)
            ->select([
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'age',
                'address',
                'valid_id_path',
                'status',
                'created_at',
                'last_login_at',
                'deleted_at',
            ]);

        if ($lifecycle === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($lifecycle === 'archived') {
            $query->whereNotNull('deleted_at');
        }

        if (($validated['q'] ?? null) !== null && $validated['q'] !== '') {
            $query->where(function (Builder $searchQuery) use ($validated): void {
                $search = (string) $validated['q'];
                $this->whereContains($searchQuery, 'first_name', $search);
                $this->whereContains($searchQuery, 'last_name', $search, 'or');
                $this->whereContains($searchQuery, 'name', $search, 'or');
                $this->whereContains($searchQuery, 'email', $search, 'or');
                $this->whereContains($searchQuery, 'phone', $search, 'or');
            });
        }

        if (($validated['status'] ?? null) === 'archived') {
            $query->whereNotNull('deleted_at');
        } elseif (($validated['status'] ?? null) !== null && $validated['status'] !== '') {
            $query->where('status', $validated['status']);
        }

        $users = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString()
            ->through(function (User $user): array {
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
                'createdAt' => $user->created_at
                    ? Carbon::parse($user->created_at)->format('Y-m-d H:i:s')
                    : null,
                'lastLogin' => $user->last_login_at
                    ? Carbon::parse($user->last_login_at)->format('Y-m-d H:i:s')
                    : null,
            ];
        });

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->whereNull('deleted_at')->count(),
            'active' => (clone $baseQuery)->whereIn('status', ['active', 'approved'])->whereNull('deleted_at')->count(),
            'suspended' => (clone $baseQuery)->where('status', 'suspended')->whereNull('deleted_at')->count(),
            'archived' => (clone $baseQuery)->whereNotNull('deleted_at')->count(),
        ];

        return Inertia::render('superAdmin/Users/SuperAdminUserManagement', [
            'users' => $users,
            'stats' => $stats,
            'filters' => [
                'q' => $validated['q'] ?? null,
                'status' => $validated['status'] ?? null,
                'lifecycle' => $validated['lifecycle'] ?? 'all',
            ],
        ]);
    }

    private function whereContains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $escaped = addcslashes($value, "\\%_");
        $query->whereRaw(
            "{$column} LIKE ? ESCAPE '\\'",
            ["%{$escaped}%"],
            $boolean,
        );
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
