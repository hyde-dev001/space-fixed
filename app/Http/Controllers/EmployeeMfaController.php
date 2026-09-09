<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\User;
use App\Services\EmployeeMfaService;
use App\Services\EmployeeSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Throwable;

final class EmployeeMfaController extends Controller
{
    private const SETUP_SESSION_KEY = 'employee_mfa_pending_setup';
    private const LOGIN_SESSION_KEYS = [
        'employee_mfa_pending_user_id',
        'employee_mfa_pending_security_version',
        'employee_mfa_pending_remember',
    ];

    public function __construct(
        private readonly EmployeeMfaService $mfa,
        private readonly EmployeeSecurityService $security,
    ) {
    }

    public function setup(Request $request)
    {
        $user = $this->employeeUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        if ($user->hasEmployeeTotpEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled.',
            ], 409);
        }

        $secret = $this->mfa->generateSecret();
        $request->session()->put(self::SETUP_SESSION_KEY, [
            'user_id' => $user->getKey(),
            'secret' => Crypt::encryptString($secret),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        $uri = $this->mfa->provisioningUri($user, $secret);

        return $this->sensitiveResponse([
            'qr_code' => $this->mfa->qrDataUri($uri),
            'manual_key' => $secret,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    public function verifySetup(Request $request)
    {
        $user = $this->employeeUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $pending = $request->session()->get(self::SETUP_SESSION_KEY);
        if (! is_array($pending)
            || (int) ($pending['user_id'] ?? 0) !== (int) $user->getKey()
            || (int) ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return response()->json([
                'message' => 'The setup session is invalid or expired.',
            ], 422);
        }

        try {
            $secret = Crypt::decryptString((string) ($pending['secret'] ?? ''));
        } catch (Throwable) {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return response()->json([
                'message' => 'The setup session is invalid or expired.',
            ], 422);
        }

        $result = DB::transaction(function () use ($user, $secret, $validated): array|false {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->whereNotNull('shop_owner_id')
                ->lockForUpdate()
                ->first();

            if (! $lockedUser || $lockedUser->hasEmployeeTotpEnabled()) {
                return false;
            }

            if (! $this->mfa->verifyEnrollment($secret, (string) $validated['code'])) {
                return false;
            }

            $recoveryCodes = $this->mfa->generateRecoveryCodes();
            $lockedUser->forceFill([
                'employee_totp_secret' => $secret,
                'employee_totp_enabled_at' => now(),
                'employee_totp_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
                'employee_totp_last_used_timestep' => null,
            ])->save();

            $employee = $this->employeeFor($lockedUser);
            $this->security->audit(
                $lockedUser,
                'employee_totp_enabled',
                'Employee enabled two-factor authentication.',
                $employee,
            );

            return $recoveryCodes;
        });

        if ($result === false) {
            return response()->json([
                'message' => 'The verification code is invalid or expired.',
            ], 422);
        }

        $request->session()->forget(self::SETUP_SESSION_KEY);

        return $this->sensitiveResponse([
            'message' => 'Two-factor authentication enabled.',
            'recovery_codes' => $result,
        ]);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $this->employeeUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $result = DB::transaction(function () use ($user, $validated): array|false {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->whereNotNull('shop_owner_id')
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || ! $lockedUser->hasEmployeeTotpEnabled()
                || ! Hash::check((string) $validated['current_password'], (string) $lockedUser->password)
                || ! $this->mfa->consumeTotp($lockedUser, trim((string) $validated['code']), intdiv(now()->timestamp, 30))) {
                return false;
            }

            $recoveryCodes = $this->mfa->generateRecoveryCodes();
            $lockedUser->forceFill([
                'employee_totp_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
            ])->save();

            $this->security->audit(
                $lockedUser,
                'employee_totp_recovery_codes_regenerated',
                'Employee regenerated two-factor recovery codes.',
                $this->employeeFor($lockedUser),
            );

            return $recoveryCodes;
        });

        if ($result === false) {
            return response()->json([
                'message' => 'The password or verification code is invalid.',
            ], 422);
        }

        return $this->sensitiveResponse([
            'message' => 'Recovery codes regenerated.',
            'recovery_codes' => $result,
        ]);
    }

    public function disable(Request $request)
    {
        $user = $this->employeeUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $securityVersion = DB::transaction(function () use ($request, $user, $validated): int|false {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->whereNotNull('shop_owner_id')
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || ! $lockedUser->hasEmployeeTotpEnabled()
                || ! Hash::check((string) $validated['current_password'], (string) $lockedUser->password)
                || ! $this->mfa->consumeTotp($lockedUser, trim((string) $validated['code']), intdiv(now()->timestamp, 30))) {
                return false;
            }

            $securityVersion = $this->security->invalidateAccess($lockedUser, $request);
            $lockedUser->forceFill([
                'employee_totp_secret' => null,
                'employee_totp_enabled_at' => null,
                'employee_totp_recovery_codes' => null,
                'employee_totp_last_used_timestep' => null,
            ])->save();

            $this->security->audit(
                $lockedUser,
                'employee_totp_disabled',
                'Employee disabled two-factor authentication.',
                $this->employeeFor($lockedUser),
            );

            return $securityVersion;
        });

        if ($securityVersion === false) {
            return response()->json([
                'message' => 'The password or verification code is invalid.',
            ], 422);
        }

        $user->refresh();
        $request->session()->regenerate();
        $this->security->markAuthenticated($request, $user);
        $request->session()->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    public function challenge(Request $request)
    {
        $user = $this->pendingLoginUser($request);

        if (! $user) {
            $this->forgetLoginChallenge($request);

            return redirect()->route('login');
        }

        return Inertia::render('ERP/EmployeeMfaChallenge', [
            'companyAccount' => $user->email,
        ]);
    }

    public function verifyLogin(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $pendingUser = $this->pendingLoginUser($request);
        if (! $pendingUser) {
            $this->forgetLoginChallenge($request);

            return $this->invalidLoginCode($request);
        }

        $attempts = (int) $request->session()->get('employee_mfa_attempts', 0);
        if ($attempts >= 5) {
            $this->forgetLoginChallenge($request);

            return $this->invalidLoginCode($request);
        }

        $result = DB::transaction(function () use ($request, $pendingUser, $validated): User|false {
            $lockedUser = User::query()
                ->whereKey($pendingUser->getKey())
                ->whereNotNull('shop_owner_id')
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || $lockedUser->status !== 'active'
                || (int) $lockedUser->security_version !== (int) $request->session()->get('employee_mfa_pending_security_version')
                || ! $lockedUser->hasEmployeeTotpEnabled()
                || ! $this->mfa->consumeSecondFactor(
                    $lockedUser,
                    trim((string) $validated['code']),
                    intdiv(now()->timestamp, 30),
                )) {
                return false;
            }

            $lockedUser->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            return $lockedUser;
        });

        if ($result === false) {
            $request->session()->put('employee_mfa_attempts', $attempts + 1);

            return $this->invalidLoginCode($request);
        }

        $remember = (bool) $request->session()->get('employee_mfa_pending_remember', false);
        $request->session()->forget('employee_mfa_attempts');
        $this->forgetLoginChallenge($request);
        $request->session()->regenerate();

        Auth::guard('user')->login($result, $remember);
        $this->security->audit(
            $result,
            'employee_login_succeeded',
            'Employee signed in successfully.',
            $this->employeeFor($result),
            \App\Models\HR\AuditLog::SEVERITY_INFO,
        );

        $this->security->markAuthenticated($request, $result);
        $request->session()->save();

        $this->security->audit(
            $result,
            'employee_mfa_verified',
            'Employee completed two-factor authentication during sign-in.',
            $this->employeeFor($result),
        );

        $redirect = $result->force_password_change
            ? route('erp.profile')
            : route('erp.time-in');

        if ($request->header('X-Inertia')) {
            return redirect($redirect)->with('success', 'Welcome back!');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect);
    }

    public function resetForManagement(Request $request, int $employeeId)
    {
        $actor = Auth::guard('user')->user();

        if (! ($actor instanceof User) || ! $actor->isEmployeeAccount() || $actor->shop_owner_id === null || ! $actor->can('reset-employee-mfa')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('shop_owner_id', $actor->shop_owner_id)
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $target = User::query()
            ->where('shop_owner_id', $actor->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $employee->email)])
            ->first();

        if (! $target) {
            return response()->json(['message' => 'Employee account not found.'], 404);
        }

        if ((int) $target->getKey() === (int) $actor->getKey()) {
            return response()->json(['message' => 'You cannot reset your own MFA here.'], 422);
        }

        DB::transaction(function () use ($target, $employee): void {
            $lockedTarget = User::query()
                ->whereKey($target->getKey())
                ->where('shop_owner_id', $employee->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->security->invalidateAccess($lockedTarget);
            $lockedTarget->forceFill([
                'employee_totp_secret' => null,
                'employee_totp_enabled_at' => null,
                'employee_totp_recovery_codes' => null,
                'employee_totp_last_used_timestep' => null,
            ])->save();

            $this->security->audit(
                $lockedTarget,
                'employee_totp_reset_by_management',
                'Employee two-factor authentication was reset by authorized management.',
                $employee,
            );
        });

        return response()->json([
            'message' => 'Employee two-factor authentication was reset.',
        ]);
    }

    private function sensitiveResponse(array $payload, int $status = 200)
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, private');
    }

    private function employeeUser(): ?User
    {
        $user = Auth::guard('user')->user();

        return $user instanceof User && $user->isEmployeeAccount() ? $user : null;
    }

    private function pendingLoginUser(Request $request): ?User
    {
        $userId = (int) $request->session()->get('employee_mfa_pending_user_id');
        $version = (int) $request->session()->get('employee_mfa_pending_security_version');

        if ($userId <= 0 || $version <= 0) {
            return null;
        }

        $user = User::query()
            ->whereKey($userId)
            ->whereNotNull('shop_owner_id')
            ->first();

        if (! $user
            || (int) $user->security_version !== $version
            || ! $user->hasEmployeeTotpEnabled()) {
            return null;
        }

        return $user;
    }

    private function forgetLoginChallenge(Request $request): void
    {
        $request->session()->forget(array_merge(
            self::LOGIN_SESSION_KEYS,
            ['employee_mfa_attempts'],
        ));
    }

    private function invalidLoginCode(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The verification code is invalid or expired.',
            ], 422);
        }

        return redirect()
            ->route('erp.mfa.challenge')
            ->withErrors(['code' => 'The verification code is invalid or expired.']);
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();
    }
}