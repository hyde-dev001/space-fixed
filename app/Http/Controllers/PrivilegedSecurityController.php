<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Privileged\ChangePrivilegedPasswordRequest;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMfaService;
use App\Services\PrivilegedSecurityTokenService;
use App\Services\PrivilegedSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

final class PrivilegedSecurityController extends Controller
{
    public function __construct(
        private readonly PrivilegedMfaService $mfa,
        private readonly PrivilegedSecurityTokenService $tokens,
        private readonly PrivilegedSessionService $sessions,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    public function show(Request $request)
    {
        $admin = Auth::guard('super_admin')->user();

        if (! $admin instanceof SuperAdmin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $state = [
            'role' => (string) $admin->role,
            'status' => (string) $admin->status,
            'mfa_complete' => $admin->hasCompletedMfaSetup(),
            'recovery_code_count' => is_array($admin->mfa_recovery_codes)
                ? count($admin->mfa_recovery_codes)
                : 0,
        ];

        if ($request->expectsJson()) {
            return response()->json($state);
        }

        return Inertia::render('superAdmin/Settings/Security', [
            'security' => $state,
        ]);
    }

    public function changePassword(ChangePrivilegedPasswordRequest $request)
    {
        $admin = Auth::guard('super_admin')->user();
        if (! $admin instanceof SuperAdmin || ! $this->isCurrentSecuritySession($request, $admin)) {
            return $this->securityFailure($request);
        }

        $password = (string) $request->validated('password');

        try {
            /** @var SuperAdmin $changedAdmin */
            $changedAdmin = DB::transaction(function () use ($request, $admin, $password): SuperAdmin {
                $lockedAdmin = SuperAdmin::query()->lockForUpdate()->find($admin->getKey());

                if (! $lockedAdmin instanceof SuperAdmin || ! $this->isCurrentSecuritySession($request, $lockedAdmin)) {
                    throw new InvalidArgumentException('The security session is no longer valid.');
                }

                $lockedAdmin->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                    'security_version' => (int) $lockedAdmin->security_version + 1,
                ])->save();
                $this->audit->privilegedPasswordChangeCompleted($request, $lockedAdmin);

                return $lockedAdmin;
            });
        } catch (Throwable) {
            return $this->securityFailure($request);
        }

        $this->sessions->invalidateOthersAfterCommit($request, $changedAdmin);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Password changed successfully.']);
        }

        return redirect()->route('admin.security')->with('success', 'Password changed successfully.');
    }

    public function generateRecoveryCodes(Request $request)
    {
        $admin = Auth::guard('super_admin')->user();
        if (! $admin instanceof SuperAdmin || ! $this->isCurrentSecuritySession($request, $admin)) {
            return $this->securityFailure($request);
        }

        try {
            /** @var array{admin: SuperAdmin, recovery_codes: list<string>, acknowledgement_token: string} $result */
            $result = DB::transaction(function () use ($request, $admin): array {
                $lockedAdmin = SuperAdmin::query()->lockForUpdate()->find($admin->getKey());

                if (! $lockedAdmin instanceof SuperAdmin || ! $this->isCurrentSecuritySession($request, $lockedAdmin)) {
                    throw new InvalidArgumentException('The security session is no longer valid.');
                }

                $recoveryCodes = $this->mfa->generateRecoveryCodes();
                $lockedAdmin->forceFill([
                    'mfa_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
                ])->save();
                $issued = $this->tokens->issue(
                    $lockedAdmin,
                    PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK,
                    $lockedAdmin,
                );
                $this->audit->privilegedRecoveryCodesGenerated($request, $lockedAdmin);

                return [
                    'admin' => $lockedAdmin,
                    'recovery_codes' => $recoveryCodes,
                    'acknowledgement_token' => $issued['raw_token'],
                ];
            });
        } catch (Throwable) {
            return $this->securityFailure($request);
        }

        $this->sessions->invalidateOthersAfterCommit($request, $result['admin']);

        return response()->json([
            'recovery_codes' => $result['recovery_codes'],
            'acknowledgement_token' => $result['acknowledgement_token'],
        ]);
    }

    public function acknowledgeRecovery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->securityFailure($request);
        }

        $admin = Auth::guard('super_admin')->user();
        if (! $admin instanceof SuperAdmin || ! $this->isCurrentSecuritySession($request, $admin)) {
            return $this->securityFailure($request);
        }

        try {
            $this->tokens->consume(
                (string) $request->input('token'),
                PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK,
                function (?SuperAdmin $lockedAdmin) use ($request): bool {
                    if (! $lockedAdmin instanceof SuperAdmin
                        || ! $this->isCurrentSecuritySession($request, $lockedAdmin)
                        || ! $lockedAdmin->hasCompletedMfaSetup()) {
                        throw new InvalidArgumentException('The recovery acknowledgement is invalid.');
                    }

                    $this->audit->privilegedRecoveryCodesAcknowledged($request, $lockedAdmin);

                    return true;
                },
            );
        } catch (Throwable) {
            return $this->securityFailure($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['acknowledged' => true]);
        }

        return redirect()->route('admin.security')->with('success', 'Recovery codes acknowledged.');
    }

    private function isCurrentSecuritySession(Request $request, SuperAdmin $admin): bool
    {
        $reauthenticatedAt = $request->session()->get('privileged_reauthenticated_at');
        $reauthenticatedVersion = $request->session()->get('privileged_reauthenticated_security_version');

        return $admin->isActive()
            && $admin->hasCompletedMfaSetup()
            && $request->session()->get('privileged_auth_stage') === 'complete'
            && (int) $request->session()->get('privileged_super_admin_id') === (int) $admin->getKey()
            && (int) $request->session()->get('privileged_security_version') === (int) $admin->security_version
            && is_numeric($reauthenticatedAt)
            && (int) $reauthenticatedAt >= now()->subMinutes((int) config('privileged_security.recent_reauthentication_minutes', 15))->timestamp
            && (int) $reauthenticatedVersion === (int) $admin->security_version;
    }

    private function securityFailure(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The security operation could not be completed.'], 422);
        }

        return redirect()->route('admin.security')->withErrors([
            'error' => 'The security operation could not be completed.',
        ]);
    }
}
