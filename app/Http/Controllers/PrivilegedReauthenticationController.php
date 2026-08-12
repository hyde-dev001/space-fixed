<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Privileged\ReauthenticatePrivilegedRequest;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMfaService;
use App\Services\PrivilegedSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

final class PrivilegedReauthenticationController extends Controller
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

        if (! $admin instanceof SuperAdmin || ! $this->isCompleteSession($request, $admin)) {
            return $this->unavailable($request);
        }

        return Inertia::render('superAdmin/Auth/PrivilegedReauthenticate', [
            'intended' => $this->safeDestination($request->query('intended')),
        ]);
    }

    public function authenticate(ReauthenticatePrivilegedRequest $request)
    {
        $admin = Auth::guard('super_admin')->user();
        $destination = $this->safeDestination($request->validated('intended'));
        $failureReason = 'invalid_credentials';

        $request->session()->forget([
            'privileged_reauthenticated_at',
            'privileged_reauthenticated_security_version',
        ]);

        try {
            /** @var SuperAdmin $reauthenticatedAdmin */
            $reauthenticatedAdmin = DB::transaction(function () use ($request, $admin, &$failureReason): SuperAdmin {
                $lockedAdmin = $admin instanceof SuperAdmin
                    ? SuperAdmin::query()->lockForUpdate()->find($admin->getKey())
                    : null;

                if (! $lockedAdmin instanceof SuperAdmin || ! $this->isCompleteSession($request, $lockedAdmin)) {
                    $failureReason = 'stale_session';
                    throw new InvalidArgumentException('Reauthentication failed.');
                }

                if (! Hash::check((string) $request->validated('password'), (string) $lockedAdmin->getAuthPassword())) {
                    throw new InvalidArgumentException('Reauthentication failed.');
                }

                $failureReason = 'invalid_code';
                $this->mfa->consumeTotp(
                    $lockedAdmin,
                    (string) $request->validated('code'),
                    intdiv(now()->timestamp, 30),
                );
                $this->audit->privilegedReauthenticationSucceeded($request, $lockedAdmin);

                return $lockedAdmin;
            });
        } catch (Throwable) {
            try {
                $this->audit->privilegedReauthenticationFailed($request, $admin instanceof SuperAdmin ? $admin : null, $failureReason);
            } catch (Throwable) {
                // Failed reauthentication must remain generic if audit storage is unavailable.
            }

            return $this->failed($request);
        }

        $request->session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => (int) $reauthenticatedAdmin->security_version,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'reauthenticated' => true,
                'redirect_to' => $destination,
            ]);
        }

        return redirect($destination);
    }

    private function isCompleteSession(Request $request, SuperAdmin $admin): bool
    {
        return $admin->isActive()
            && $admin->hasCompletedMfaSetup()
            && $request->session()->get('privileged_auth_stage') === 'complete'
            && (int) $request->session()->get('privileged_super_admin_id') === (int) $admin->getKey()
            && (int) $request->session()->get('privileged_security_version') === (int) $admin->security_version
            && $this->sessions->validate($request, $admin);
    }

    private function safeDestination(mixed $intended): string
    {
        if (! is_string($intended)
            || $intended === ''
            || str_starts_with($intended, '//')
            || str_contains($intended, '\\')
            || parse_url($intended, PHP_URL_SCHEME) !== null
            || parse_url($intended, PHP_URL_HOST) !== null) {
            return '/admin/security';
        }

        $path = parse_url($intended, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/admin/')) {
            return '/admin/security';
        }

        return $intended;
    }

    private function failed(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Reauthentication failed.'], 422);
        }

        return redirect('/admin/reauthenticate')->withErrors([
            'password' => 'Reauthentication failed.',
        ]);
    }

    private function unavailable(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('admin.login');
    }
}
