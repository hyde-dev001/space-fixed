<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBusinessType
{
    /**
     * Handle an incoming request.
     * 
     * Checks if the authenticated shop owner's business type matches one of the allowed types.
     * Redirects to dashboard with error message if access is denied.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$allowedTypes  Allowed business types (e.g., 'retail', 'repair', 'both')
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
        
        // Normalize business type (handle 'both (retail & repair)' format)
        $businessType = $shopOwner->business_type;
        if ($businessType === 'both (retail & repair)') {
            $businessType = 'both';
        }
        
        // Check if shop owner's business type is in allowed types
        if (!in_array($businessType, $allowedTypes)) {
            $featureName = $this->getFeatureName($allowedTypes);
            
            return redirect()->route('shop-owner.dashboard')
                ->with('error', "This feature is not available for your business type. {$featureName} features require a " . implode(' or ', $allowedTypes) . " business type.");
        }
        
        return $next($request);
    }
    
    /**
     * Get user-friendly feature name from allowed types
     * 
     * @param array $allowedTypes
     * @return string
     */
    private function getFeatureName(array $allowedTypes): string
    {
        if (in_array('retail', $allowedTypes)) {
            return 'Product management';
        }
        if (in_array('repair', $allowedTypes)) {
            return 'Service and repair';
        }
        return 'This';
    }
}
