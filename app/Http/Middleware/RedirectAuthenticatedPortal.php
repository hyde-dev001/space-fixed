<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RedirectAuthenticatedPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('shop_owner')->check()) {
            return redirect()->route('shop-owner.dashboard');
        }

        $user = Auth::guard('user')->user();
        if ($user instanceof User && $user->isEmployeeAccount()) {
            return redirect()->route('erp.time-in');
        }

        return $next($request);
    }
}
