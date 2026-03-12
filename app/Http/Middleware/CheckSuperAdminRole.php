<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckSuperAdminRole Middleware
 * 
 * Restricts access to routes that require super_admin role.
 * Regular admins will be denied access to sensitive operations.
 * 
 * Usage:
 * Route::get('/admin/create-admin', ...)->middleware(['super_admin.auth', 'super_admin.role']);
 * 
 * This ensures:
 * 1. User is authenticated (super_admin.auth)
 * 2. User has super_admin role (super_admin.role)
 */
class CheckSuperAdminRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the authenticated super admin
        $admin = Auth::guard('super_admin')->user();

        // Check if the admin has super_admin role
        if (!$admin || $admin->role !== 'super_admin') {
            // Redirect with error message
            return redirect()->route('admin.system-monitoring')
                ->with('error', 'You do not have permission to access this resource. Super Admin access required.');
        }

        return $next($request);
    }
}
