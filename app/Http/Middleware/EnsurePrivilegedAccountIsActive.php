<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Services\PrivilegedSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePrivilegedAccountIsActive
{
    public function __construct(private readonly PrivilegedSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('super_admin');

        if (! $admin instanceof SuperAdmin || ! $admin->isActive()) {
            if ($admin instanceof SuperAdmin) {
                $this->sessions->forgetCurrent($request);
            }

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
