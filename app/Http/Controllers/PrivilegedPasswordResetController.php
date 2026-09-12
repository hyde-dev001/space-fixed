<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PrivilegedDeliveryType;
use App\Http\Requests\Privileged\ExchangePrivilegedBearerRequest;
use App\Http\Requests\Privileged\ResetPrivilegedPasswordRequest;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedCompletionProofService;
use App\Services\PrivilegedMailDispatcher;
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

final class PrivilegedPasswordResetController extends Controller
{
    private const GENERIC_FORGOT_MESSAGE = 'If an active administrator account exists, a reset link will be sent.';

    public function __construct(
        private readonly PrivilegedSecurityTokenService $tokens,
        private readonly PrivilegedCompletionProofService $completionProofs,
        private readonly PrivilegedSessionService $sessions,
        private readonly PrivilegedAudit $audit,
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
    ) {
    }

    public function showForgotPassword()
    {
        return Inertia::render('superAdmin/Auth/PrivilegedForgotPassword');
    }

    public function send(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email')));
        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if (! $validator->fails()) {
            $admin = SuperAdmin::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($admin instanceof SuperAdmin && $admin->isActive()) {
                try {
                    /** @var array{admin: SuperAdmin} $result */
                    $result = DB::transaction(function () use ($request, $admin): array {
                        $lockedAdmin = SuperAdmin::query()->lockForUpdate()->find($admin->getKey());

                        if (! $lockedAdmin instanceof SuperAdmin || ! $lockedAdmin->isActive()) {
                            throw new InvalidArgumentException('The reset request is no longer applicable.');
                        }

                        $issued = $this->tokens->issue(
                            $lockedAdmin,
                            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
                            null,
                        );
                        $this->audit->privilegedPasswordResetRequested($request, $lockedAdmin);

                        $this->privilegedMailDispatcher->dispatch(
                            type: PrivilegedDeliveryType::PRIVILEGED_PASSWORD_RESET,
                            businessEventId: 'privileged-password-reset:'.$lockedAdmin->getKey().':'.$issued['token']->getKey(),
                            recipientType: 'super_admin',
                            recipientId: (int) $lockedAdmin->getKey(),
                            payload: [
                                'recipient_name' => trim($lockedAdmin->first_name.' '.$lockedAdmin->last_name),
                                'email' => (string) $lockedAdmin->email,
                                'raw_token' => $issued['raw_token'],
                            ],
                            correlationId: $this->audit->correlationId($request),
                        );

                        return [
                            'admin' => $lockedAdmin,
                        ];
                    });
                } catch (Throwable) {
                    // The public response remains generic whether auditing or delivery fails.
                }
            } else {
                try {
                    $this->audit->privilegedPasswordResetRequested($request, null);
                } catch (Throwable) {
                    // A public reset request must not reveal account state or audit failures.
                }
            }
        }

        return $this->genericForgotResponse($request);
    }

    public function showResetPassword()
    {
        return Inertia::render('superAdmin/Auth/PrivilegedResetPassword');
    }

    public function exchange(ExchangePrivilegedBearerRequest $request)
    {
        $rawToken = (string) $request->validated('token');

        try {
            $authorization = $this->tokens->authorize($rawToken, PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET);
            $subject = SuperAdmin::query()->find($authorization['subject_id']);

            if (! $subject instanceof SuperAdmin) {
                throw new InvalidArgumentException('Invalid security token.');
            }

            $completionProof = $this->completionProofs->issue(
                tokenId: $authorization['token_id'],
                subjectId: $authorization['subject_id'],
                purpose: PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
                tokenExpiresAt: $authorization['expires_at'],
            );
            $this->audit->privilegedPasswordResetExchangeSucceeded($request, $subject);
        } catch (Throwable) {
            try {
                $this->audit->privilegedPasswordResetExchangeFailed($request);
            } catch (Throwable) {
                // Keep invalid-link responses generic even if audit storage is unavailable.
            }

            return $this->invalidResetLink($request);
        }

        return response()->json([
            'authorized' => true,
            'completion_proof' => $completionProof,
        ]);
    }

    public function complete(ResetPrivilegedPasswordRequest $request)
    {
        try {
            $authorization = $this->completionProofs->authorization(
                (string) $request->validated('completion_proof'),
                PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
            );
        } catch (InvalidArgumentException) {
            return $this->invalidResetLink($request);
        }

        $password = (string) $request->validated('password');

        try {
            /** @var SuperAdmin $admin */
            $admin = $this->tokens->consumeAuthorized(
                $authorization['token_id'],
                $authorization['subject_id'],
                PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
                function (?SuperAdmin $lockedAdmin) use ($request, $password): SuperAdmin {
                    if (! $lockedAdmin instanceof SuperAdmin || ! $lockedAdmin->isActive()) {
                        throw new InvalidArgumentException('Invalid reset authorization.');
                    }

                    $lockedAdmin->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                        'password_changed_at' => now(),
                        'security_version' => (int) $lockedAdmin->security_version + 1,
                    ])->save();
                    $this->audit->privilegedPasswordResetCompleted($request, $lockedAdmin);

                    return $lockedAdmin;
                },
            );
        } catch (Throwable) {
            return $this->invalidResetLink($request);
        }

        $this->sessions->invalidateAllAfterCommit($admin);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('super_admin')->logout();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your password has been reset.']);
        }

        return redirect()->route('admin.login')->with('status', 'Your password has been reset.');
    }

    private function genericForgotResponse(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::GENERIC_FORGOT_MESSAGE]);
        }

        return redirect()->route('admin.password.request')->with('status', self::GENERIC_FORGOT_MESSAGE);
    }

    private function invalidResetLink(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The reset link is invalid or expired.'], 422);
        }

        return redirect('/admin/reset-password')->withErrors([
            'token' => 'The reset link is invalid or expired.',
        ]);
    }
}
