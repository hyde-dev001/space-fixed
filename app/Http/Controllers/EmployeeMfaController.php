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
        'user_id' => 'employee_mfa_pending_user_id',
        'security_version' => 'employee_mfa_pending_security_version',
        'remember' => 'employee_mfa_pending_remember',
    ];
    private const CUSTOMER_SETUP_SESSION_KEY = 'customer_mfa_pending_setup';
    private const CUSTOMER_LOGIN_SESSION_KEYS = [
        'user_id' => 'customer_mfa_pending_user_id',
        'security_version' => 'customer_mfa_pending_security_version',
        'remember' => 'customer_mfa_pending_remember',
    ];

    public function __construct(
        private readonly EmployeeMfaService $mfa,
        private readonly EmployeeSecurityService $security,
    ) {
    }

    public function setup(Request $request)
    {
        return $this->setupForAccount($request, false);
    }

    public function customerSetup(Request $request)
    {
        return $this->setupForAccount($request, true);
    }

    private function setupForAccount(Request $request, bool $customer)
    {
        $user = $this->accountUser($customer);

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

        if ($this->hasTotpEnabled($user, $customer)) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled.',
            ], 409);
        }

        $secret = $this->mfa->generateSecret();
        $expiresAt = now()->addMinutes(10);
        $request->session()->put($this->setupSessionKey($customer), [
            'user_id' => $user->getKey(),
            'secret' => Crypt::encryptString($secret),
            'expires_at' => $expiresAt->timestamp,
        ]);

        $uri = $this->mfa->provisioningUri($user, $secret);

        return $this->sensitiveResponse([
            'qr_code' => $this->mfa->qrDataUri($uri),
            'manual_key' => $secret,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function verifySetup(Request $request)
    {
        return $this->verifySetupForAccount($request, false);
    }

    public function customerVerifySetup(Request $request)
    {
        return $this->verifySetupForAccount($request, true);
    }

    private function verifySetupForAccount(Request $request, bool $customer)
    {
        $user = $this->accountUser($customer);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $setupSessionKey = $this->setupSessionKey($customer);
        $pending = $request->session()->get($setupSessionKey);
        if (! is_array($pending)
            || (int) ($pending['user_id'] ?? 0) !== (int) $user->getKey()
            || (int) ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget($setupSessionKey);

            return response()->json([
                'message' => 'The setup session is invalid or expired.',
            ], 422);
        }

        try {
            $secret = Crypt::decryptString((string) ($pending['secret'] ?? ''));
        } catch (Throwable) {
            $request->session()->forget($setupSessionKey);

            return response()->json([
                'message' => 'The setup session is invalid or expired.',
            ], 422);
        }

        $result = DB::transaction(function () use ($user, $secret, $validated, $customer): array|false {
            $lockedUser = $this->accountQuery($customer)
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || ! $this->isAccountType($lockedUser, $customer)
                || $this->hasTotpEnabled($lockedUser, $customer)) {
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

            $this->auditAccount(
                $lockedUser,
                $customer,
                $customer ? 'customer_totp_enabled' : 'employee_totp_enabled',
                $customer
                    ? 'Customer enabled two-factor authentication.'
                    : 'Employee enabled two-factor authentication.',
            );

            return $recoveryCodes;
        });

        if ($result === false) {
            return response()->json([
                'message' => 'The verification code is invalid or expired.',
            ], 422);
        }

        $request->session()->forget($setupSessionKey);

        return $this->sensitiveResponse([
            'message' => 'Two-factor authentication enabled.',
            'recovery_codes' => $result,
        ]);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        return $this->regenerateRecoveryCodesForAccount($request, false);
    }

    public function customerRegenerateRecoveryCodes(Request $request)
    {
        return $this->regenerateRecoveryCodesForAccount($request, true);
    }

    private function regenerateRecoveryCodesForAccount(Request $request, bool $customer)
    {
        $user = $this->accountUser($customer);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $result = DB::transaction(function () use ($user, $validated, $customer): array|false {
            $lockedUser = $this->accountQuery($customer)
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || ! $this->isAccountType($lockedUser, $customer)
                || ! $this->hasTotpEnabled($lockedUser, $customer)
                || ! Hash::check((string) $validated['current_password'], (string) $lockedUser->password)
                || ! $this->mfa->consumeTotp($lockedUser, trim((string) $validated['code']), intdiv(now()->timestamp, 30))) {
                return false;
            }

            $recoveryCodes = $this->mfa->generateRecoveryCodes();
            $lockedUser->forceFill([
                'employee_totp_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
            ])->save();

            $this->auditAccount(
                $lockedUser,
                $customer,
                $customer
                    ? 'customer_totp_recovery_codes_regenerated'
                    : 'employee_totp_recovery_codes_regenerated',
                $customer
                    ? 'Customer regenerated two-factor recovery codes.'
                    : 'Employee regenerated two-factor recovery codes.',
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
        return $this->disableForAccount($request, false);
    }

    public function customerDisable(Request $request)
    {
        return $this->disableForAccount($request, true);
    }

    private function disableForAccount(Request $request, bool $customer)
    {
        $user = $this->accountUser($customer);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $securityVersion = DB::transaction(function () use ($request, $user, $validated, $customer): int|false {
            $lockedUser = $this->accountQuery($customer)
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || ! $this->isAccountType($lockedUser, $customer)
                || ! $this->hasTotpEnabled($lockedUser, $customer)
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

            $this->auditAccount(
                $lockedUser,
                $customer,
                $customer ? 'customer_totp_disabled' : 'employee_totp_disabled',
                $customer
                    ? 'Customer disabled two-factor authentication.'
                    : 'Employee disabled two-factor authentication.',
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
        return $this->challengeForAccount($request, false);
    }

    public function customerChallenge(Request $request)
    {
        return $this->challengeForAccount($request, true);
    }

    private function challengeForAccount(Request $request, bool $customer)
    {
        $user = $this->pendingLoginUser($request, $customer);

        if (! $user) {
            $this->forgetLoginChallenge($request, $customer);

            return redirect()->route($customer ? 'user.login.form' : 'login');
        }

        $props = [
            'companyAccount' => $user->email,
        ];

        if ($customer) {
            $props['verifyRoute'] = route('customer.mfa.challenge.verify');
            $props['loginRoute'] = route('user.login.form');
        }

        return Inertia::render('ERP/EmployeeMfaChallenge', $props);
    }

    public function verifyLogin(Request $request)
    {
        return $this->verifyLoginForAccount($request, false);
    }

    public function customerVerifyLogin(Request $request)
    {
        return $this->verifyLoginForAccount($request, true);
    }

    private function verifyLoginForAccount(Request $request, bool $customer)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $sessionKeys = $this->loginSessionKeys($customer);
        $pendingUser = $this->pendingLoginUser($request, $customer);
        if (! $pendingUser) {
            $this->forgetLoginChallenge($request, $customer);

            return $this->invalidLoginCode($request, $customer);
        }

        $attemptsKey = $this->loginAttemptsKey($customer);
        $attempts = (int) $request->session()->get($attemptsKey, 0);
        if ($attempts >= 5) {
            $this->forgetLoginChallenge($request, $customer);

            return $this->invalidLoginCode($request, $customer);
        }

        $result = DB::transaction(function () use ($request, $pendingUser, $validated, $customer, $sessionKeys): User|false {
            $lockedUser = $this->accountQuery($customer)
                ->whereKey($pendingUser->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser
                || ! $this->isAccountType($lockedUser, $customer)
                || $lockedUser->status !== 'active'
                || (int) $lockedUser->security_version !== (int) $request->session()->get($sessionKeys['security_version'])
                || ! $this->hasTotpEnabled($lockedUser, $customer)
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
            $request->session()->put($attemptsKey, $attempts + 1);

            return $this->invalidLoginCode($request, $customer);
        }

        $remember = (bool) $request->session()->get($sessionKeys['remember'], false);
        $request->session()->forget($attemptsKey);
        $this->forgetLoginChallenge($request, $customer);
        $request->session()->regenerate();

        Auth::guard('user')->login($result, $remember);
        $this->auditAccount(
            $result,
            $customer,
            $customer ? 'customer_login_succeeded' : 'employee_login_succeeded',
            $customer ? 'Customer signed in successfully.' : 'Employee signed in successfully.',
            \App\Models\HR\AuditLog::SEVERITY_INFO,
        );

        $this->security->markAuthenticated($request, $result);
        $request->session()->save();

        $this->auditAccount(
            $result,
            $customer,
            $customer ? 'customer_mfa_verified' : 'employee_mfa_verified',
            $customer
                ? 'Customer completed two-factor authentication during sign-in.'
                : 'Employee completed two-factor authentication during sign-in.',
        );

        $redirect = $customer
            ? route('landing')
            : ($result->force_password_change ? route('erp.profile') : route('erp.time-in'));

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

    private function accountUser(bool $customer): ?User
    {
        $user = Auth::guard('user')->user();

        return $user instanceof User && $this->isAccountType($user, $customer) ? $user : null;
    }

    private function isAccountType(User $user, bool $customer): bool
    {
        return $customer ? $user->isCustomerAccount() : $user->isEmployeeAccount();
    }

    private function hasTotpEnabled(User $user, bool $customer): bool
    {
        return $customer ? $user->hasCustomerTotpEnabled() : $user->hasEmployeeTotpEnabled();
    }

    private function accountQuery(bool $customer)
    {
        return User::query()->when(
            $customer,
            fn ($query) => $query->whereNull('shop_owner_id'),
            fn ($query) => $query->whereNotNull('shop_owner_id'),
        );
    }

    private function setupSessionKey(bool $customer): string
    {
        return $customer ? self::CUSTOMER_SETUP_SESSION_KEY : self::SETUP_SESSION_KEY;
    }

    /** @return array{user_id: string, security_version: string, remember: string} */
    private function loginSessionKeys(bool $customer): array
    {
        return $customer ? self::CUSTOMER_LOGIN_SESSION_KEYS : self::LOGIN_SESSION_KEYS;
    }

    private function loginAttemptsKey(bool $customer): string
    {
        return $customer ? 'customer_mfa_attempts' : 'employee_mfa_attempts';
    }

    private function pendingLoginUser(Request $request, bool $customer = false): ?User
    {
        $sessionKeys = $this->loginSessionKeys($customer);
        $userId = (int) $request->session()->get($sessionKeys['user_id']);
        $version = (int) $request->session()->get($sessionKeys['security_version']);

        if ($userId <= 0 || $version <= 0) {
            return null;
        }

        $user = $this->accountQuery($customer)
            ->whereKey($userId)
            ->first();

        if (! $user
            || ! $this->isAccountType($user, $customer)
            || (int) $user->security_version !== $version
            || ! $this->hasTotpEnabled($user, $customer)) {
            return null;
        }

        return $user;
    }

    private function forgetLoginChallenge(Request $request, bool $customer = false): void
    {
        $sessionKeys = $this->loginSessionKeys($customer);
        $request->session()->forget(array_merge(
            array_values($sessionKeys),
            [$this->loginAttemptsKey($customer)],
        ));
    }

    private function invalidLoginCode(Request $request, bool $customer = false)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The verification code is invalid or expired.',
            ], 422);
        }

        return redirect()
            ->route($customer ? 'customer.mfa.challenge' : 'erp.mfa.challenge')
            ->withErrors(['code' => 'The verification code is invalid or expired.']);
    }

    private function auditAccount(
        User $user,
        bool $customer,
        string $action,
        string $description,
        string $severity = AuditLog::SEVERITY_WARNING,
    ): void {
        if ($customer) {
            $this->security->auditCustomer($user, $action, $description, $severity);

            return;
        }

        $this->security->audit($user, $action, $description, $this->employeeFor($user), $severity);
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();
    }
}
