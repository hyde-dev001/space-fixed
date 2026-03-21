<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccount
{
    /**
     * Ensure only customer accounts (non-employee user accounts) can access customer routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('user.login.form');
        }

        $isCustomerAccount = is_null($user->shop_owner_id);

        if (!$isCustomerAccount) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Only customer accounts can access this endpoint.'], 403);
            }

            return redirect()->route('user.login.form')
                ->with('error', 'Please log in with a customer account to use messaging.');
        }

        return $next($request);
    }
}