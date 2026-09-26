<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecentPrivilegedReauthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('super_admin');
        $reauthenticatedAt = $request->session()->get('privileged_reauthenticated_at');
        $reauthenticatedVersion = $request->session()->get('privileged_reauthenticated_security_version');
        $windowSeconds = (int) config('privileged_security.recent_reauthentication_minutes', 15) * 60;

        $isRecent = is_numeric($reauthenticatedAt)
            && (int) $reauthenticatedAt <= now()->timestamp
            && (int) $reauthenticatedAt >= now()->subSeconds($windowSeconds)->timestamp;

        if (! $admin instanceof SuperAdmin
            || ! $isRecent
            || (int) $reauthenticatedVersion !== (int) $admin->security_version) {
            $request->session()->forget([
                'privileged_reauthenticated_at',
                'privileged_reauthenticated_security_version',
            ]);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Recent reauthentication required.'], Response::HTTP_LOCKED);
            }

            $request->session()->put('privileged_reauth_intended', $this->intendedDestination($request));

            return redirect('/admin/reauthenticate');
        }

        return $next($request);
    }

    private function intendedDestination(Request $request): string
    {
        if ($request->isMethodSafe()) {
            return $request->getPathInfo();
        }

        $refererPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

        return is_string($refererPath) && str_starts_with($refererPath, '/admin/')
            ? $refererPath
            : '/admin/security';
    }
}
