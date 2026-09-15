<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('super_admin')->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
