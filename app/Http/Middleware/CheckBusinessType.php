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
        
        // Normalize business type (supports: "both", "both (retail & repair)", mixed case)
        $businessType = $this->normalizeBusinessType((string) $shopOwner->business_type);
        $normalizedAllowedTypes = array_values(array_unique(array_filter(array_map(
            fn (string $type) => $this->normalizeBusinessType($type),
            $allowedTypes
        ))));
        
        // Check if shop owner's business type is in allowed types
        if (!in_array($businessType, $normalizedAllowedTypes, true)) {
            $featureName = $this->getFeatureName($normalizedAllowedTypes);
            $message = "This feature is not available for your business type. {$featureName} features require a " . implode(' or ', $normalizedAllowedTypes) . " business type.";

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $message,
                    'business_type' => $businessType,
                    'allowed_types' => $normalizedAllowedTypes,
                ], 403);
            }

            return redirect()->route('shop-owner.dashboard')
                ->with('error', $message);
        }
        
        return $next($request);
    }

    private function normalizeBusinessType(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        if ($normalized === 'retail') {
            return 'retail';
        }

        if ($normalized === 'repair') {
            return 'repair';
        }

        return '';
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
