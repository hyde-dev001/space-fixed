<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\User;
use App\Services\EmployeeInvitationService;
use Illuminate\Http\JsonResponse;
use App\Services\EmployeeSecurityService;
use App\Support\EmployeePasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly EmployeeInvitationService $invitations,
        private readonly EmployeeSecurityService $security,
    ) {
    }

    public function show(Request $request)
    {
        $user = Auth::guard('user')->user();
        if (! $user instanceof User) {
            abort(401);
        }
        $isEmployee = $user->isEmployeeAccount();

        $securityActivity = $isEmployee
            ? $this->securityActivityQuery($user)->latest()->limit(5)->get()->map(fn (AuditLog $log): array => $this->securityActivityPayload($log))->values()->all()
            : [];

        return Inertia::render('ERP/Profile', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'job_title' => $user->position,
                'country' => $user->country,
                'city' => $user->city,
                'postal_code' => $user->postal_code,
                'tax_id' => $user->tax_id,
            ],
            'requiresPasswordChange' => (bool) $user->force_password_change,
            'security' => [
                'is_employee' => $isEmployee,
                'totp_enabled' => $isEmployee && $user->hasEmployeeTotpEnabled(),
                'activity' => $securityActivity,
                'active_sessions' => $isEmployee ? $this->security->activeSessions($request, $user) : [],
            ],
        ]);
    }

    public function securityActivity(Request $request): JsonResponse
    {
        $user = $this->employeeUser();
        $perPage = $this->perPage($request);
        $paginator = $this->securityActivityQuery($user)->latest()->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (AuditLog $log): array => $this->securityActivityPayload($log))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }



    public function logoutOtherSessions(Request $request)
    {
        $user = $this->employeeUser();
        $count = $this->security->logoutOtherSessions($request, $user);
        $user->refresh();
        $request->session()->regenerate();
        $this->security->markAuthenticated($request, $user);
        $request->session()->save();

        $employee = Employee::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();

        $this->security->audit(
            $user,
            'employee_sessions_revoked',
            'Employee logged out other active sessions.',
            $employee,
        );

        $message = $count === 1
            ? '1 other session was logged out.'
            : "{$count} other sessions were logged out.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'revoked' => $count,
            ]);
        }

        return back()->with('success', $message);
    }

    public function activeSessions(Request $request): JsonResponse
    {
        $user = $this->employeeUser();
        $paginator = $this->security->paginateActiveSessions($request, $user, $this->perPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function employeeUser(): User
    {
        $user = Auth::guard('user')->user();

        if (! $user instanceof User || ! $user->isEmployeeAccount()) {
            abort(403);
        }

        return $user;
    }

    private function perPage(Request $request): int
    {
        return min(25, max(1, $request->integer('per_page', 10)));
    }

    private function securityActivityQuery(User $user)
    {
        return AuditLog::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->where('entity_type', User::class)
            ->where('entity_id', $user->getKey())

            ->whereJsonContains('tags', 'employee_security');
    }

    private function securityActivityPayload(AuditLog $log): array
    {
        return [
            'action' => $log->action,
            'label' => match ($log->action) {
                'employee_password_changed' => 'Password changed',
                'employee_sessions_revoked' => 'Other sessions logged out',
                'employee_mfa_enabled' => 'Two-factor authentication enabled',
                'employee_mfa_disabled' => 'Two-factor authentication disabled',
                'employee_mfa_verified' => 'Two-factor authentication verified',
                'employee_mfa_reset' => 'Two-factor authentication reset',
                'employee_recovery_codes_regenerated' => 'Recovery codes regenerated',
                'employee_invitation_created' => 'Employee invitation created',
                'employee_invitation_resent' => 'Employee invitation resent',
                'employee_invitation_accepted' => 'Employee invitation accepted',
                'employee_login_succeeded' => 'Successful login',
                'employee_login_failed' => 'Failed sign-in attempt',
                default => str($log->action)->replace('_', ' ')->title()->toString(),
            },
            'description' => $log->description,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
    public function updatePassword(Request $request)
    {
        $user = Auth::guard('user')->user();
        $passwordRules = $user->isEmployeeAccount()
            ? EmployeePasswordRules::rules()
            : ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()];

        $request->validate([
            'current_password' => ['required'],
            'password' => $passwordRules,
        ]);

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        if (! $user->isEmployeeAccount()) {
            $user->update([
                'password' => Hash::make((string) $request->input('password')),
                'force_password_change' => false,
            ]);

            return back()->with('success', 'Password updated successfully');
        }

        $securityVersion = DB::transaction(function () use ($request, $user): int {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->forceFill([
                'password' => Hash::make((string) $request->input('password')),
                'force_password_change' => false,
            ])->save();

            $this->invitations->clear($lockedUser);
            $securityVersion = $this->security->invalidateAccess($lockedUser, $request);

            $employee = Employee::query()
                ->where('shop_owner_id', $lockedUser->shop_owner_id)
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $lockedUser->email)])
                ->first();

            $this->security->audit(
                $lockedUser,
                'employee_password_changed',
                'Employee changed their password.',
                $employee,
                AuditLog::SEVERITY_WARNING,
            );

            return $securityVersion;
        });

        $user->refresh();
        $request->session()->regenerate();
        $this->security->markAuthenticated($request, $user);
        $request->session()->save();

        return back()->with('success', 'Password updated successfully');
    }
}