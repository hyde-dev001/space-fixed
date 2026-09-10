<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\User;
use App\Services\EmployeeSecurityService;
use App\Support\EmployeePasswordRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * UserProfileController
 * 
 * Handles ERP user profile and password management
 */
class UserProfileController extends Controller
{
    public function __construct(private readonly EmployeeSecurityService $security)
    {
    }

    /**
     * Show the user profile page
     * 
     * Displays the profile page where users can manage their password
     * if they need to change it on first login
     * 
     * @param Request $request
     * @return \Inertia\Response
     */
    public function show(Request $request)
    {
        $user = Auth::guard('user')->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $isEmployee = $user->isEmployeeAccount();

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
                'activity' => $isEmployee
                    ? $this->securityActivityQuery($user)->latest()->limit(5)->get()->map(fn (AuditLog $log): array => $this->securityActivityPayload($log))->values()->all()
                    : [],
                'active_sessions' => $isEmployee ? $this->security->activeSessions($request, $user) : [],
            ],
        ]);
    }

    public function securityActivity(Request $request): JsonResponse
    {
        $user = $this->employeeUser();
        $paginator = $this->securityActivityQuery($user)->latest()->paginate($this->perPage($request));

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

    public function logoutOtherSessions(Request $request)
    {
        $user = $this->employeeUser();
        $count = $this->security->logoutOtherSessions($request, $user);
        $user->refresh();
        $request->session()->regenerate();
        $this->security->markAuthenticated($request, $user);
        $request->session()->save();

        $this->security->audit(
            $user,
            'employee_sessions_revoked',
            'Employee logged out other active sessions.',
            $this->employeeFor($user),
        );

        $message = $count === 1
            ? '1 other session was logged out.'
            : $count.' other sessions were logged out.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'revoked' => $count])
            : back()->with('success', $message);
    }

    /**
     * Update the user's password
     * 
     * Validates the current password and updates to the new password
     * Clears the force_password_change flag if set
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePassword(Request $request)
    {
        $user = Auth::guard('user')->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $passwordRules = $user->isEmployeeAccount()
            ? EmployeePasswordRules::rules()
            : ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()];

        $request->validate([
            'current_password' => ['required'],
            'password' => $passwordRules,
        ]);

        if (! Hash::check((string) $request->current_password, (string) $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        if (! $user->isEmployeeAccount()) {
            $user->update([
                'password' => Hash::make((string) $request->password),
                'force_password_change' => false,
            ]);

            return back()->with('success', 'Password updated successfully');
        }

        DB::transaction(function () use ($request, $user): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->forceFill([
                'password' => Hash::make((string) $request->password),
                'force_password_change' => false,
                'invite_token' => null,
                'invite_expires_at' => null,
                'invited_at' => null,
                'invited_by' => null,
            ])->save();

            $this->security->invalidateAccess($lockedUser, $request);
            $this->security->audit(
                $lockedUser,
                'employee_password_changed',
                'Employee changed their password.',
                $this->employeeFor($lockedUser),
                AuditLog::SEVERITY_WARNING,
            );
        });

        $user->refresh();
        $request->session()->regenerate();
        $this->security->markAuthenticated($request, $user);
        $request->session()->save();

        return back()->with('success', 'Password updated successfully');
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
                'employee_totp_enabled', 'employee_mfa_enabled' => 'Two-factor authentication enabled',
                'employee_totp_disabled', 'employee_mfa_disabled' => 'Two-factor authentication disabled',
                'employee_mfa_verified', 'employee_totp_verified' => 'Two-factor authentication verified',
                'employee_totp_reset_by_management', 'employee_mfa_reset' => 'Two-factor authentication reset',
                'employee_totp_recovery_codes_regenerated', 'employee_recovery_codes_regenerated' => 'Recovery codes regenerated',
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

    private function employeeFor(User $user): ?Employee
    {
        return Employee::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();
    }
}
