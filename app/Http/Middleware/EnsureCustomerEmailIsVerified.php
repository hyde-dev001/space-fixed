<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerEmailIsVerified
{
    /**
     * Restrict only customer accounts until their email address is verified.
     *
     * Employee users and shop owners share parts of the authentication stack,
     * so neither is classified as a customer by this middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isVerificationRoute($request)) {
            return $next($request);
        }

        if (! $this->isProtectedRoute($request)) {
            return $next($request);
        }

        $user = Auth::guard('user')->user();
        if (! $user instanceof User || ! $user->isCustomerAccount() || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Please verify your email address before continuing.',
            ], 403);
        }

        return redirect()->route('verification.notice')
            ->with('error', 'Please verify your email address before continuing.');
    }

    private function isVerificationRoute(Request $request): bool
    {
        return $request->routeIs(
            'verification.notice',
            'verification.send',
            'verification.verify',
            'user.logout',
        );
    }

    private function isProtectedRoute(Request $request): bool
    {
        $route = $request->route();
        if (! $route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth:user')) {
                return true;
            }
        }

        return false;
    }
}
