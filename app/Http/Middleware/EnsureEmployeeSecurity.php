<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmployeeSecurity
{
    /** @var list<string> */
    private const PASSWORD_SETUP_ROUTES = [
        'erp.profile',
        'erp.password.update',
        'user.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('user');
        $authenticated = $guard->user();

        if (! $authenticated instanceof User || is_null($authenticated->shop_owner_id)) {
            return $next($request);
        }

        $hasSession = $request->hasSession();
        $user = User::query()->find($authenticated->getKey());
        if (! $user) {
            return $next($request);
        }

        $currentVersion = max(1, (int) ($user->security_version ?: 1));
        $sessionVersion = $hasSession ? $request->session()->get('employee_security_version') : null;

        if ($sessionVersion !== null && (int) $sessionVersion !== $currentVersion) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session has expired. Please sign in again.',
                    'code' => 'EMPLOYEE_SESSION_REVOKED',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Your session has expired. Please sign in again.',
            ]);
        }

        if ($hasSession && $sessionVersion === null) {
            $request->session()->put('employee_security_version', $currentVersion);
        }

        if ($user->force_password_change
            && ! in_array((string) ($request->route()?->getName() ?? ''), self::PASSWORD_SETUP_ROUTES, true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You must set a new password before continuing.',
                    'code' => 'PASSWORD_CHANGE_REQUIRED',
                ], Response::HTTP_FORBIDDEN);
            }

            return redirect()->route('erp.profile');
        }

        return $next($request);
    }
}
