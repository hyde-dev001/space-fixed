<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Services\PrivilegedSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePrivilegedMfaComplete
{
    public function __construct(private readonly PrivilegedSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('super_admin');

        if (! $admin instanceof SuperAdmin || ! $admin->isActive()) {
            return $this->deny($request);
        }

        if (! $admin->hasCompletedMfaSetup()) {
            if ($request->session()->get('privileged_auth_stage') === 'setup'
                && ! $request->expectsJson()) {
                return redirect('/admin/mfa/setup');
            }

            return $this->deny($request);
        }

        if (! $this->sessions->validate($request, $admin)) {
            $this->sessions->forgetCurrent($request);

            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->route('admin.login');
    }
}
