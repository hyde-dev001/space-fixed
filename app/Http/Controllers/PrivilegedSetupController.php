<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Privileged\CompleteSetupPasswordRequest;
use App\Http\Requests\Privileged\ExchangePrivilegedBearerRequest;
use App\Http\Requests\Privileged\VerifyMfaCodeRequest;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMfaService;
use App\Services\PrivilegedSecurityTokenService;
use App\Services\PrivilegedSessionService;
use App\Services\PrivilegedSetupProofService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use InvalidArgumentException;

final class PrivilegedSetupController extends Controller
{
    public function __construct(
        private readonly PrivilegedMfaService $mfa,
        private readonly PrivilegedSecurityTokenService $tokens,
        private readonly PrivilegedSessionService $sessions,
        private readonly PrivilegedSetupProofService $setupProofs,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    public function showSetupPage()
    {
        return Inertia::render('superAdmin/Auth/PrivilegedSetup');
    }

    public function exchange(ExchangePrivilegedBearerRequest $request)
    {
        $rawToken = (string) $request->validated('token');

        try {
            $authorization = $this->tokens->authorize($rawToken, PrivilegedSecurityToken::PURPOSE_SETUP);
            $subject = SuperAdmin::query()->find($authorization['subject_id']);

            if (! $subject instanceof SuperAdmin) {
                throw new InvalidArgumentException('Invalid security token.');
            }

            $completionProof = $this->setupProofs->issue(
                tokenId: $authorization['token_id'],
                subjectId: $authorization['subject_id'],
                tokenExpiresAt: $authorization['expires_at'],
            );
            $this->audit->privilegedSetupExchangeSucceeded($request, $subject);
        } catch (InvalidArgumentException) {
            $this->audit->privilegedSetupExchangeFailed($request);

            return $this->invalidSetupLink($request);
        }

        return response()->json([
            'authorized' => true,
            'completion_proof' => $completionProof,
        ]);
    }

    public function completePassword(CompleteSetupPasswordRequest $request)
    {
        try {
            $authorization = $this->setupProofs->authorization(
                (string) $request->validated('completion_proof'),
            );
        } catch (InvalidArgumentException $exception) {
            Log::warning('Privileged setup completion proof rejected', [
                'correlation_id' => $request->attributes->get('privileged_audit_correlation_id'),
                'exception_class' => $exception::class,
            ]);

            return $this->invalidSetupLink($request);
        }

        $password = (string) $request->validated('password');

        try {
            /** @var SuperAdmin $admin */
            $admin = $this->tokens->consumeAuthorized(
                $authorization['token_id'],
                $authorization['subject_id'],
                PrivilegedSecurityToken::PURPOSE_SETUP,
                function (?SuperAdmin $lockedAdmin) use ($request, $password): SuperAdmin {
                    if (! $lockedAdmin instanceof SuperAdmin
                        || $lockedAdmin->status !== SuperAdmin::STATUS_PENDING_SETUP
                        || $lockedAdmin->hasCompletedMfaSetup()) {
                        throw new InvalidArgumentException('Invalid setup authorization.');
                    }

                    $attributes = [
                        'password' => $password,
                        'password_changed_at' => now(),
                        'mfa_confirmed_at' => null,
                        'mfa_recovery_codes' => null,
                        'mfa_last_used_timestep' => null,
                    ];

                    if (! is_string($lockedAdmin->mfa_secret) || $lockedAdmin->mfa_secret === '') {
                        $attributes['mfa_secret'] = $this->mfa->generateSecret();
                    }

                    $lockedAdmin->forceFill($attributes)->save();
                    $this->audit->privilegedSetupPasswordCompleted($request, $lockedAdmin);

                    return $lockedAdmin;
                },
            );
        } catch (InvalidArgumentException $exception) {
            Log::warning('Privileged setup authorization rejected', [
                'correlation_id' => $request->attributes->get('privileged_audit_correlation_id'),
                'token_id' => $authorization['token_id'],
                'subject_id' => $authorization['subject_id'],
                'exception_class' => $exception::class,
            ]);

            return $this->invalidSetupLink($request);
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('Privileged setup password completion failed', [
                'correlation_id' => $request->attributes->get('privileged_audit_correlation_id'),
                'token_id' => $authorization['token_id'],
                'subject_id' => $authorization['subject_id'],
                'exception_class' => $exception::class,
            ]);

            return $this->setupCompletionFailed($request);
        }

        $request->session()->regenerate();
        Auth::guard('super_admin')->login($admin, false);
        $request->session()->put([
            'privileged_auth_stage' => 'setup',
            'privileged_super_admin_id' => $admin->id,
            'privileged_security_version' => (int) $admin->security_version,
            'privileged_remember_requested' => false,
        ]);

        return redirect('/admin/mfa/setup');
    }

    public function showEnrollment(Request $request)
    {
        $admin = Auth::guard('super_admin')->user();

        if (! $admin instanceof SuperAdmin || ! $this->isSetupStage($request, $admin)) {
            return $this->setupUnavailable($request);
        }

        $admin = DB::transaction(function () use ($request, $admin): SuperAdmin {
            $lockedAdmin = SuperAdmin::query()->lockForUpdate()->find($admin->getKey());

            if (! $lockedAdmin instanceof SuperAdmin || ! $this->isSetupStage($request, $lockedAdmin)) {
                throw new InvalidArgumentException('Setup is no longer available.');
            }

            $needsSecret = ! is_string($lockedAdmin->mfa_secret) || $lockedAdmin->mfa_secret === '';
            $needsReset = $lockedAdmin->mfa_confirmed_at !== null && $lockedAdmin->mfa_recovery_codes === null;

            if ($needsSecret || $needsReset) {
                $lockedAdmin->forceFill([
                    'mfa_secret' => $needsSecret ? $this->mfa->generateSecret() : $lockedAdmin->mfa_secret,
                    'mfa_confirmed_at' => null,
                    'mfa_recovery_codes' => null,
                    'mfa_last_used_timestep' => null,
                ])->save();
                $this->audit->privilegedMfaEnrollmentStarted($request, $lockedAdmin);
            }

            return $lockedAdmin->fresh();
        });

        $secret = (string) $admin->mfa_secret;

        return Inertia::render('superAdmin/Auth/PrivilegedMfaEnrollment', [
            'qrCode' => $this->mfa->qrDataUri($this->mfa->provisioningUri($admin)),
            'manualSecret' => $secret,
            'issuer' => (string) config('privileged_security.issuer'),
        ]);
    }

    public function verifyEnrollment(VerifyMfaCodeRequest $request)
    {
        $admin = Auth::guard('super_admin')->user();

        if (! $admin instanceof SuperAdmin || ! $this->isSetupStage($request, $admin)) {
            return $this->setupUnavailable($request);
        }

        $code = trim((string) $request->validated('code'));

        try {
            /** @var array{recovery_codes: list<string>, acknowledgement_token: string} $result */
            $result = DB::transaction(function () use ($request, $admin, $code): array {
                $lockedAdmin = SuperAdmin::query()->lockForUpdate()->find($admin->getKey());

                if (! $lockedAdmin instanceof SuperAdmin
                    || ! $this->isSetupStage($request, $lockedAdmin)
                    || $lockedAdmin->mfa_confirmed_at !== null
                    || ! is_string($lockedAdmin->mfa_secret)
                    || $lockedAdmin->mfa_secret === '') {
                    throw new InvalidArgumentException('Invalid enrollment state.');
                }

                $this->mfa->consumeTotp($lockedAdmin, $code, intdiv(now()->timestamp, 30));
                $recoveryCodes = $this->mfa->generateRecoveryCodes();
                $lockedAdmin->forceFill([
                    'mfa_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
                    'mfa_confirmed_at' => null,
                ])->save();

                $issued = $this->tokens->issue(
                    $lockedAdmin,
                    PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK,
                    $lockedAdmin,
                );
                $this->audit->privilegedMfaEnrollmentVerified($request, $lockedAdmin);

                return [
                    'recovery_codes' => $recoveryCodes,
                    'acknowledgement_token' => $issued['raw_token'],
                ];
            });
        } catch (InvalidArgumentException) {
            $this->audit->privilegedMfaEnrollmentFailed($request, $admin);

            return $this->invalidEnrollmentCode($request);
        }

        return response()->json($result);
    }

    public function acknowledgeRecovery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->invalidAcknowledgement($request);
        }

        $admin = Auth::guard('super_admin')->user();
        if (! $admin instanceof SuperAdmin || ! $this->isSetupStage($request, $admin)) {
            return $this->setupUnavailable($request);
        }

        $rawToken = (string) $request->input('token');

        try {
            /** @var SuperAdmin $completedAdmin */
            $completedAdmin = $this->tokens->consume(
                $rawToken,
                PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK,
                function (?SuperAdmin $lockedAdmin) use ($request): SuperAdmin {
                    if (! $lockedAdmin instanceof SuperAdmin
                        || ! $this->isSetupStage($request, $lockedAdmin)
                        || $lockedAdmin->mfa_confirmed_at !== null
                        || $lockedAdmin->mfa_recovery_codes === null
                        || $lockedAdmin->mfa_secret === null) {
                        throw new InvalidArgumentException('Invalid recovery acknowledgement.');
                    }

                    $lockedAdmin->forceFill([
                        'mfa_confirmed_at' => now(),
                        'status' => $lockedAdmin->status === SuperAdmin::STATUS_PENDING_SETUP
                            ? SuperAdmin::STATUS_ACTIVE
                            : $lockedAdmin->status,
                    ])->save();
                    $this->audit->privilegedMfaEnrollmentCompleted($request, $lockedAdmin);

                    return $lockedAdmin;
                },
            );
        } catch (\Throwable) {
            return $this->invalidAcknowledgement($request);
        }

        $remember = (bool) $request->session()->pull('privileged_remember_requested', false);
        $request->session()->regenerate();
        Auth::guard('super_admin')->login($completedAdmin, $remember);
        $this->sessions->establish($request, $completedAdmin);

        return redirect()->intended(route('admin.system-monitoring'));
    }

    private function isSetupStage(Request $request, SuperAdmin $admin): bool
    {
        return in_array($admin->status, [SuperAdmin::STATUS_ACTIVE, SuperAdmin::STATUS_PENDING_SETUP], true)
            && ! $admin->hasCompletedMfaSetup()
            && $request->session()->get('privileged_auth_stage') === 'setup'
            && (int) $request->session()->get('privileged_super_admin_id') === (int) $admin->getKey()
            && (int) $request->session()->get('privileged_security_version') === (int) $admin->security_version;
    }

    private function invalidSetupLink(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The setup link is invalid or expired.'], 422);
        }

        return redirect('/admin/setup')->withErrors([
            'token' => 'The setup link is invalid or expired.',
        ]);
    }

    private function setupCompletionFailed(Request $request)
    {
        $message = 'Setup could not be completed. Please try again.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 500);
        }

        return redirect('/admin/setup')->withErrors([
            'error' => $message,
        ]);
    }

    private function setupUnavailable(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The setup session is invalid.'], 401);
        }

        return redirect()->route('admin.login');
    }

    private function invalidEnrollmentCode(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The verification code is invalid.'], 422);
        }

        return redirect('/admin/mfa/setup')->withErrors([
            'code' => 'The verification code is invalid.',
        ]);
    }

    private function invalidAcknowledgement(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The recovery acknowledgement is invalid or expired.'], 422);
        }

        return redirect('/admin/mfa/setup')->withErrors([
            'token' => 'The recovery acknowledgement is invalid or expired.',
        ]);
    }
}
