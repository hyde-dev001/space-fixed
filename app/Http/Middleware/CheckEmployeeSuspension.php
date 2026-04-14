<?php

namespace App\Http\Middleware;

use Closure;
use App\Enums\EmployeeStatus;
use App\Enums\ShopOwnerStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Employee;
use App\Models\ShopOwner;
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
        if (Auth::guard('shop_owner')->check()) {
            $shopOwner = Auth::guard('shop_owner')->user();

            if ($this->isShopOwnerSuspended($shopOwner?->status)) {
                $this->logoutGuard($request, 'shop_owner');

                return $this->suspendedResponse(
                    $request,
                    route('shop-owner.login.form'),
                    'Your shop account has been suspended. Please contact support.'
                );
            }
        }

        if (Auth::guard('user')->check()) {
            $user = Auth::guard('user')->user();

            if (!is_null($user->shop_owner_id)) {
                $shopOwner = ShopOwner::find($user->shop_owner_id);

                if ($shopOwner && $this->isShopOwnerSuspended($shopOwner->status)) {
                    $this->logoutGuard($request, 'user');

                    return $this->suspendedResponse(
                        $request,
                        route('login'),
                        'Your shop account has been suspended. Please contact your administrator.'
                    );
                }
            }

            $employee = Employee::where('email', $user->email)->first();

            if ($employee && $this->isEmployeeSuspended($employee->status)) {
                $this->logoutGuard($request, 'user');

                return $this->suspendedResponse(
                    $request,
                    route('login'),
                    'Your account has been suspended. Please contact your administrator.'
                );
            }
        }

        return $next($request);
    }

    private function isEmployeeSuspended(mixed $status): bool
    {
        if ($status instanceof EmployeeStatus) {
            return $status === EmployeeStatus::SUSPENDED;
        }

        return (string) $status === EmployeeStatus::SUSPENDED->value;
    }

    private function isShopOwnerSuspended(mixed $status): bool
    {
        if ($status instanceof ShopOwnerStatus) {
            return $status === ShopOwnerStatus::SUSPENDED;
        }

        return (string) $status === ShopOwnerStatus::SUSPENDED->value;
    }

    private function logoutGuard(Request $request, string $guard): void
    {
        Auth::guard($guard)->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function suspendedResponse(Request $request, string $loginUrl, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => 'account_suspended',
            ], 403);
        }

        return redirect($loginUrl)->with('error', $message);
    }
}
