<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privileged\InviteAdministratorRequest;
use App\Models\SuperAdmin;
use App\Services\AdministratorIdentityService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class AdministratorManagementController extends Controller
{
    public function __construct(
        private readonly AdministratorIdentityService $identity,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function index(Request $request): Response
    {
        $currentAdminId = auth('super_admin')->id();
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in([
                SuperAdmin::ROLE_ADMIN,
                SuperAdmin::ROLE_SUPER_ADMIN,
            ])],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                SuperAdmin::STATUS_ACTIVE,
                SuperAdmin::STATUS_PENDING_SETUP,
                SuperAdmin::STATUS_SUSPENDED,
                SuperAdmin::STATUS_INACTIVE,
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $baseQuery = SuperAdmin::query()->whereKeyNot($currentAdminId);
        $query = (clone $baseQuery)
            ->select([
                'id',
                'first_name',
                'last_name',
                'role',
                'email',
                'status',
                'mfa_secret',
                'mfa_recovery_codes',
                'mfa_confirmed_at',
                'created_at',
                'last_login_at',
            ]);

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $search = (string) $validated['search'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $this->whereContains($searchQuery, 'first_name', $search);
                $this->whereContains($searchQuery, 'last_name', $search, 'or');
                $this->whereContains($searchQuery, 'email', $search, 'or');
            });
        }

        $query
            ->when($validated['role'] ?? null, fn (Builder $builder, string $role) => $builder->where('role', $role))
            ->when($validated['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $admins = $query
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString()
            ->through(function (SuperAdmin $admin): array {
                return [
                    'id' => $admin->id,
                    'firstName' => $admin->first_name,
                    'lastName' => $admin->last_name,
                    'role' => $admin->role,
                    'email' => $admin->email,
                    'status' => $admin->status,
                    'mfa_complete' => $admin->hasCompletedMfaSetup(),
                    'recovery_code_count' => is_array($admin->mfa_recovery_codes)
                        ? count($admin->mfa_recovery_codes)
                        : 0,
                    'createdAt' => $admin->created_at->format('Y-m-d H:i:s'),
                    'lastLogin' => $admin->last_login ? $admin->last_login->format('Y-m-d H:i:s') : null,
                ];
            });

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', SuperAdmin::STATUS_ACTIVE)->count(),
            'suspended' => (clone $baseQuery)->where('status', SuperAdmin::STATUS_SUSPENDED)->count(),
            'inactive' => (clone $baseQuery)->where('status', SuperAdmin::STATUS_INACTIVE)->count(),
        ];

        return Inertia::render('superAdmin/AdminTeam/AdminManagement', [
            'admins' => $admins,
            'stats' => $stats,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'role' => $validated['role'] ?? null,
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    private function whereContains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $escaped = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->whereRaw(
            "{$column} LIKE ? ESCAPE '!'",
            ["%{$escaped}%"],
            $boolean,
        );
    }

    public function create(): Response
    {
        return Inertia::render('superAdmin/AdminTeam/CreateAdmin');
    }

    public function store(InviteAdministratorRequest $request)
    {
        try {
            $this->identity->invite(
                $request,
                $this->currentPrivilegedActor(),
                $request->validated(),
            );
        } catch (Throwable $exception) {
            $response = $this->failures->unexpected(
                request: $request,
                operation: 'privileged_invitation_create',
                exception: $exception,
                message: 'The administrator invitation could not be created.',
                code: 'privileged_invitation_create_error',
            );

            if (! $request->expectsJson()) {
                $response->withInput($request->except('password', 'password_confirmation'));
            }

            return $response;
        }

        return redirect()->route('admin.administrators.index')
            ->with('success', 'Administrator invitation created successfully.');
    }

    public function resendSetupInvitation(Request $request, int $administrator)
    {
        try {
            $this->identity->resendSetupInvitation(
                $request,
                $this->currentPrivilegedActor(),
                $administrator,
            );
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'privileged_invitation_resend',
                exception: $exception,
                message: 'The setup invitation could not be resent.',
                code: 'privileged_invitation_resend_error',
            );
        }

        return redirect()->route('admin.administrators.index')
            ->with('success', 'Administrator setup invitation resent successfully.');
    }

    public function suspend(Request $request, int $administrator)
    {
        try {
            $admin = $this->identity->suspend($request, $this->currentPrivilegedActor(), $administrator);

            return $this->identityMutationResponse($request, $admin, 'Administrator suspended successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function deactivate(Request $request, int $administrator)
    {
        try {
            $admin = $this->identity->deactivate($request, $this->currentPrivilegedActor(), $administrator);

            return $this->identityMutationResponse($request, $admin, 'Administrator deactivated successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function activate(Request $request, int $administrator)
    {
        try {
            $admin = $this->identity->activate($request, $this->currentPrivilegedActor(), $administrator);

            return $this->identityMutationResponse($request, $admin, 'Administrator activation completed.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function updateRole(Request $request, int $administrator)
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in([
                SuperAdmin::ROLE_ADMIN,
                SuperAdmin::ROLE_SUPER_ADMIN,
            ])],
        ]);

        try {
            $admin = $this->identity->updateRole(
                $request,
                $this->currentPrivilegedActor(),
                $administrator,
                (string) $validated['role'],
            );

            return $this->identityMutationResponse($request, $admin, 'Administrator role updated successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function resetMfa(Request $request, int $administrator)
    {
        try {
            $admin = $this->identity->resetMfa($request, $this->currentPrivilegedActor(), $administrator);

            return $this->identityMutationResponse($request, $admin, 'Administrator MFA reset successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    private function currentPrivilegedActor(): SuperAdmin
    {
        $actor = auth('super_admin')->user();

        if (! $actor instanceof SuperAdmin) {
            throw new AuthorizationException('A privileged actor is required.');
        }

        return $actor;
    }

    private function identityMutationResponse(Request $request, SuperAdmin $admin, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'id' => (int) $admin->getKey(),
                'status' => (string) $admin->status,
                'role' => (string) $admin->role,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function identityMutationFailure(Request $request, Throwable $exception)
    {
        if ($exception instanceof AuthorizationException) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'privileged_identity',
                exception: $exception,
                message: 'The administrator identity operation is not permitted.',
                code: 'privileged_identity_forbidden',
                forceJson: $request->expectsJson(),
                status: 403,
            );
        }

        if ($exception instanceof ValidationException) {
            return $this->failures->validation(
                request: $request,
                message: 'The administrator identity operation could not be completed.',
                code: 'privileged_identity_validation',
                forceJson: $request->expectsJson(),
            );
        }

        return $this->failures->unexpected(
            request: $request,
            operation: 'privileged_identity',
            exception: $exception,
            message: 'The administrator identity operation could not be completed.',
            code: 'privileged_identity_error',
            forceJson: $request->expectsJson(),
        );
    }
}
