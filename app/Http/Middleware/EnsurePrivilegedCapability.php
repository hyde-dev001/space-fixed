<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $admin = $request->user('super_admin');

        abort_unless($admin instanceof SuperAdmin, 401);
        abort_unless($admin->hasCapability($capability), 403);

        return $next($request);
    }
}
