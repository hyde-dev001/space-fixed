<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckManagerStaffAccess Middleware
 * 
 * Implements hierarchical role-based access:
 * - MANAGER can access both Manager and Staff pages
 * - STAFF can only access Staff pages
 * 
 * Usage in routes:
 * Route::get('/erp/manager/dashboard', ...)->middleware('manager.staff:manager');
 * Route::get('/erp/staff/dashboard', ...)->middleware('manager.staff:staff');
 */
class CheckManagerStaffAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $requiredLevel  Either 'manager' or 'staff'
     */
    public function handle(Request $request, Closure $next, string $requiredLevel): Response
    {
        // Explicitly use the 'user' guard to avoid session issues
        $user = auth()->guard('user')->user();

        // If no user is authenticated, deny access
        if (!$user) {
            if ($request->header('X-Inertia')) {
                return redirect()->route('landing')->with('error', 'Please login to continue');
            }
            return response()->json([
                'message' => 'Unauthenticated',
                'error' => 'UNAUTHENTICATED'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userRole = strtoupper($user->role ?? '');

        // Define access hierarchy: MANAGER can access both manager and staff pages
        $canAccess = false;

        if ($requiredLevel === 'manager') {
            // Only MANAGER can access manager pages
            $canAccess = ($userRole === 'MANAGER');
        } elseif ($requiredLevel === 'staff') {
            // Both MANAGER and STAFF can access staff pages
            $canAccess = in_array($userRole, ['MANAGER', 'STAFF']);
        }

        if (!$canAccess) {
            if ($request->header('X-Inertia')) {
                // Redirect based on user's role
                $targetRoute = match ($userRole) {
                    'MANAGER' => 'erp.manager.dashboard',
                    'STAFF' => 'erp.staff.dashboard',
                    'FINANCE' => 'finance.index',
                    'HR' => 'erp.hr',
                    'CRM' => 'crm.dashboard',
                    default => 'landing',
                };

                return redirect()
                    ->route($targetRoute)
                    ->with('error', 'You do not have permission to access this page');
            }

            return response()->json([
                'message' => 'You do not have permission to access this page',
                'error' => 'INSUFFICIENT_PERMISSIONS',
                'required_level' => $requiredLevel,
                'user_role' => $userRole
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
