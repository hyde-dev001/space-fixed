<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoleMiddleware
 * 
 * Checks if the authenticated user has a specific role
 * required to access ERP modules like HR, FINANCE, etc.
 * 
 * Usage in routes:
 * Route::post('/hr/employees', [EmployeeController::class, 'store'])
 *     ->middleware('role:HR');
 */
class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // DEBUG: Log authentication attempt
        \Log::debug('RoleMiddleware - Auth Check', [
            'path' => $request->path(),
            'is_authenticated' => $user ? true : false,
            'required_roles' => $roles,
        ]);

        // If no user is authenticated, deny access
        if (!$user) {
            \Log::debug('RoleMiddleware - NO USER AUTHENTICATED');
            return response()->json([
                'message' => 'Unauthenticated',
                'error' => 'UNAUTHENTICATED'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // DEBUG: Log the check
        \Log::debug('RoleMiddleware - User Details', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_role_column' => $user->role,
            'required_roles' => $roles,
            'user_spatie_roles' => $user->getRoleNames()->toArray() ?? [],
        ]);

        // ERP modules are restricted to explicit handler roles only.
        // SUPER_ADMIN should not bypass ERP module access.

        // Check both old role column AND Spatie roles for backward compatibility
        $hasAccess = false;
        
        foreach ($roles as $role) {
            // Check old role column (case-insensitive, handles MANAGER, Manager, etc.)
            if (strcasecmp($user->role, $role) === 0) {
                \Log::debug('Access granted by old role column', ['role' => $role, 'user_role' => $user->role]);
                $hasAccess = true;
                break;
            }
            
            // Also check common variations: FINANCE_STAFF vs Finance Staff
            $roleVariations = [
                $role,
                strtoupper($role),
                strtoupper(str_replace(' ', '_', $role)), // "Finance Staff" -> "FINANCE_STAFF"
                str_replace('_', ' ', $role), // "FINANCE_STAFF" -> "Finance Staff"
            ];
            
            foreach ($roleVariations as $variation) {
                if (strcasecmp($user->role, $variation) === 0) {
                    $hasAccess = true;
                    break 2; // Break both loops
                }
            }
            
            // Check Spatie roles (if user has Spatie roles assigned)
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            // Log the denial
            \Log::debug('RoleMiddleware - ACCESS DENIED', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role_column' => $user->role,
                'user_spatie_roles' => $user->getRoleNames()->toArray(),
                'required_roles' => $roles,
                'reason' => 'No matching role found',
            ]);

            // If this is an Inertia request, redirect to the user's allowed module
            if ($request->header('X-Inertia')) {
                // Check both old role column and Spatie roles for redirect (case-insensitive)
                $targetRoute = 'landing';
                $userRole = strtoupper($user->role);
                
                if ($user->hasRole('Finance Staff') || $user->hasRole('Finance Manager') || in_array($userRole, ['FINANCE', 'FINANCE_STAFF', 'FINANCE_MANAGER'])) {
                    $targetRoute = 'finance.index';
                } elseif ($user->hasRole('HR') || $userRole === 'HR') {
                    $targetRoute = 'erp.hr';
                } elseif ($user->hasRole('CRM') || $userRole === 'CRM') {
                    $targetRoute = 'crm.dashboard';
                } elseif ($user->hasRole('Manager') || $userRole === 'MANAGER') {
                    $targetRoute = 'erp.manager.dashboard';
                } elseif ($user->hasRole('Staff') || $userRole === 'STAFF') {
                    $targetRoute = 'erp.staff.dashboard';
                }

                return redirect()
                    ->route($targetRoute)
                    ->with('error', 'You do not have permission to access this module');
            }

            return response()->json([
                'message' => 'You do not have permission to access this module',
                'error' => 'UNAUTHORIZED_ROLE',
                'required_role' => $roles[0] ?? null,
                'user_role' => $user->role
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
