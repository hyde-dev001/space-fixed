<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Support\PrivilegedFailureResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedCapability
{
    public function __construct(private readonly PrivilegedFailureResponse $failures)
    {
    }

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $admin = $request->user('super_admin');

        abort_unless($admin instanceof SuperAdmin, 401);

        if (! $admin->hasCapability($capability)) {
            return $this->failures->capabilityDenied($request, $admin, $capability);
        }

        return $next($request);
    }
}
