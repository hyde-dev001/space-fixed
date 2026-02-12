<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GateErpAccess Middleware
 * 
 * Restricts ERP module pages to users with explicit ERP handler roles.
 * SUPER_ADMIN users are denied access to ERP modules.
 * Only HR, FINANCE, MANAGER, STAFF roles can access ERP pages.
 * 
 * Usage in routes:
 * Route::get('/hr', [...])->middleware('gate.erp.access');
 */
class GateErpAccess
{
    /**
     * ERP handler roles allowed to access ERP modules
     */
    const ALLOWED_ERP_ROLES = ['HR', 'FINANCE', 'MANAGER', 'STAFF'];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Deny unauthenticated users
        if (!$user) {
            return redirect('/');
        }

        // Deny SUPER_ADMIN from accessing ERP modules
        if ($user->role === 'SUPER_ADMIN') {
            abort(403, 'Super Admin does not have access to ERP modules. Only designated ERP handlers can access ERP pages.');
        }

        // Deny users without ERP roles
        if (!in_array($user->role, self::ALLOWED_ERP_ROLES)) {
            abort(403, 'Your role does not have access to ERP modules.');
        }

        return $next($request);
    }
}
