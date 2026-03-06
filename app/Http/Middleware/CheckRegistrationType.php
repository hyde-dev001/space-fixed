<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRegistrationType
{
    /**
     * Handle an incoming request.
     * 
     * Checks if the authenticated shop owner's registration type matches one of the allowed types.
     * Redirects to dashboard with error message and upgrade prompt if access is denied.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$allowedTypes  Allowed registration types (e.g., 'individual', 'company')
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$allowedTypes): Response
    {
        // Get authenticated shop owner
        $shopOwner = auth()->guard('shop_owner')->user();
        
        // If not authenticated as shop owner, redirect to login
        if (!$shopOwner) {
            return redirect()->route('shop-owner.login.form')
                ->with('error', 'Please login to access this feature.');
        }
        
        // Check if shop owner's registration type is in allowed types
        if (!in_array($shopOwner->registration_type, $allowedTypes)) {
            // If trying to access Company-only feature as Individual
            if (in_array('company', $allowedTypes) && $shopOwner->registration_type === 'individual') {
                return redirect()->route('shop-owner.dashboard')
                    ->with('error', 'This feature requires a Business account.')
                    ->with('upgrade_prompt', true);
            }
            
            return redirect()->route('shop-owner.dashboard')
                ->with('error', 'This feature is not available for your account type.');
        }
        
        return $next($request);
    }
}
