<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Employee;
use Symfony\Component\HttpFoundation\Response;

class CheckEmployeeSuspension
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('user')->check()) {
            $user = Auth::guard('user')->user();

            $employee = Employee::where('email', $user->email)->first();

            if ($employee && $employee->status === 'suspended') {
                Auth::guard('user')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/user/login')
                    ->with('error', 'Your account has been suspended. Please contact your administrator.');
            }
        }

        return $next($request);
    }
}
