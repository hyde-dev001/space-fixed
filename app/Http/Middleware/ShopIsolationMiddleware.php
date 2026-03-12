<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ShopIsolationMiddleware
 * 
 * Ensures that users with ERP module roles (HR, FINANCE, etc.)
 * can only access data from their own shop.
 * 
 * This middleware prevents users from accessing other shops' data
 * by checking the shop_owner_id on every request.
 */
class ShopIsolationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If user is not authenticated, deny access
        if (!$user) {
            return $next($request);
        }

        // Determine the current shop context
        // - For ERP users (auth:user), use their assigned shop_owner_id
        // - For authenticated shop owners (auth:shop_owner), use their own id
        $currentShopId = $user->shop_owner_id ?? ($request->user('shop_owner')?->id);

        if (!$currentShopId) {
            return response()->json([
                'message' => 'User is not assigned to any shop',
                'error' => 'SHOP_NOT_ASSIGNED'
            ], Response::HTTP_FORBIDDEN);
        }

        // Check if shop_id in request matches user's shop_owner_id
        if ($request->has('shop_id') || $request->route('shop_id')) {
            $requestedShopId = $request->input('shop_id') ?? $request->route('shop_id');
            
            if ($requestedShopId && (int) $requestedShopId !== (int) $user->shop_owner_id) {
                return response()->json([
                    'message' => 'You do not have access to this shop',
                    'error' => 'UNAUTHORIZED_SHOP_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Attach shop_owner_id to request for easy access in controllers
        $request->merge(['user_shop_id' => $currentShopId]);

        return $next($request);
    }
}
