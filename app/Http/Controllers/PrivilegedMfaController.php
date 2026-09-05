<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Privileged\VerifyMfaCodeRequest;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMfaService;
use App\Services\PrivilegedSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use InvalidArgumentException;

final class PrivilegedMfaController extends Controller
{
    public function __construct(
        private readonly PrivilegedMfaService $mfa,
        private readonly PrivilegedSessionService $sessions,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    public function show(Request $request)
    {
        $admin = Auth::guard('super_admin')->user();

        if (! $admin instanceof SuperAdmin || ! $this->isChallengeSession($request, $admin)) {
            return $this->challengeUnavailable($request);
        }

        return Inertia::render('superAdmin/Auth/PrivilegedMfaChallenge');
    }

    public function verify(VerifyMfaCodeRequest $request)
    {
        $admin = Auth::guard('super_admin')->user();

        if (! $admin instanceof SuperAdmin || ! $this->isChallengeSession($request, $admin)) {
            return $this->challengeUnavailable($request);
        }

        $code = trim((string) $request->validated('code'));
        $method = preg_match('/\A\d{6}\z/', $code) === 1 ? 'totp' : 'recovery_code';

        try {
            $authenticatedAdmin = DB::transaction(function () use ($request, $admin, $code, $method): SuperAdmin {
                $lockedAdmin = SuperAdmin::query()
                    ->whereKey($admin->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedAdmin instanceof SuperAdmin || ! $this->isChallengeSession($request, $lockedAdmin)) {
                    throw new InvalidArgumentException('The verification code is invalid.');
                }

                if ($method === 'totp') {
                    $this->mfa->consumeTotp(
                        $lockedAdmin,
                        $code,
                        intdiv(now()->timestamp, 30),
                    );
                } elseif (! $this->mfa->consumeRecoveryCode($lockedAdmin, $code)) {
                    throw new InvalidArgumentException('The verification code is invalid.');
                }

                $lockedAdmin->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => $request->ip(),
                ])->save();

                $this->audit->privilegedMfaSucceeded($request, $lockedAdmin, $method);

                if ($method === 'recovery_code') {
                    $this->audit->privilegedMfaRecoveryCodeConsumed($request, $lockedAdmin);
                }

                return $lockedAdmin;
            });
        } catch (InvalidArgumentException) {
            $this->audit->privilegedMfaFailed($request, $admin, $method);

            return $this->invalidCode($request);
        }

        $remember = (bool) $request->session()->pull('privileged_remember_requested', false);
        $request->session()->regenerate();
        Auth::guard('super_admin')->login($authenticatedAdmin, $remember);
        $this->sessions->establish($request, $authenticatedAdmin);

        return redirect()->intended(route('admin.system-monitoring'));
    }

    private function isChallengeSession(Request $request, SuperAdmin $admin): bool
    {
        return $admin->isActive()
            && $admin->hasCompletedMfaSetup()
            && $request->session()->get('privileged_auth_stage') === 'mfa_challenge'
            && (int) $request->session()->get('privileged_super_admin_id') === (int) $admin->getKey()
            && (int) $request->session()->get('privileged_security_version') === (int) $admin->security_version;
    }

    private function invalidCode(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The verification code is invalid.'], 422);
        }

        return redirect('/admin/mfa/challenge')
            ->withErrors(['code' => 'The verification code is invalid.']);
    }

    private function challengeUnavailable(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('admin.login');
    }
}
