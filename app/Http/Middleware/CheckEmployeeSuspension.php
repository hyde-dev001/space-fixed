<?php

namespace App\Http\Middleware;

use Closure;
use App\Enums\EmployeeStatus;
use App\Enums\ShopOwnerStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
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
        $routeName = (string) ($request->route()?->getName() ?? '');
        if (str_starts_with($routeName, 'shop-owner.erp.')
            || str_starts_with($routeName, 'shop_owner.erp.')) {
            return $next($request);
        }

        if (Auth::guard('shop_owner')->check()) {
            $authenticatedShopOwner = Auth::guard('shop_owner')->user();
            $shopOwner = $authenticatedShopOwner instanceof ShopOwner
                ? ShopOwner::withTrashed()->find($authenticatedShopOwner->getKey())
                : null;

            if ($shopOwner?->trashed() || ! $this->isShopOwnerOperational($shopOwner?->status)) {
                $this->logoutGuard($request, 'shop_owner');

                return $this->suspendedResponse(
                    $request,
                    route('shop-owner.login.form'),
                    'Your shop account is unavailable. Please contact support.',
                    $shopOwner?->trashed() || ! $this->isShopOwnerSuspended($shopOwner?->status)
                        ? 'account_unavailable'
                        : 'account_suspended',
                );
            }
        }

        if (Auth::guard('user')->check()) {
            $authenticatedUser = Auth::guard('user')->user();
            $user = $authenticatedUser instanceof User
                ? User::withTrashed()->find($authenticatedUser->getKey())
                : null;

            if ($user?->trashed() || ! $this->isUserActive($user?->status)) {
                $this->logoutGuard($request, 'user');

                return $this->suspendedResponse(
                    $request,
                    route('login'),
                    'Your account is unavailable. Please contact support.',
                    $user?->trashed() || ! $this->isUserSuspended($user?->status)
                        ? 'account_unavailable'
                        : 'account_suspended',
                );
            }

            if (!is_null($user->shop_owner_id)) {
                $shopOwner = ShopOwner::withTrashed()->find($user->shop_owner_id);

                if (!$shopOwner || $shopOwner->trashed() || ! $this->isShopOwnerOperational($shopOwner->status)) {
                    $this->logoutGuard($request, 'user');

                    return $this->suspendedResponse(
                        $request,
                        route('login'),
                        'Your account is unavailable. Please contact your administrator.',
                        $shopOwner?->trashed() || ! $this->isShopOwnerSuspended($shopOwner?->status)
                            ? 'account_unavailable'
                            : 'account_suspended',
                    );
                }
            }

            $employees = Employee::withTrashed()
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
                ->orderBy('id')
                ->get();

            if ($employees->count() > 1
                || ($employees->count() === 1
                    && ($employees->first()->trashed() || ! $this->isEmployeeActive($employees->first()->status)))) {
                $this->logoutGuard($request, 'user');

                return $this->suspendedResponse(
                    $request,
                    route('login'),
                    'Your account is unavailable. Please contact your administrator.',
                    $employees->count() === 1 && $this->isEmployeeSuspended($employees->first()->status)
                        ? 'account_suspended'
                        : 'account_unavailable',
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

    private function isEmployeeActive(mixed $status): bool
    {
        if ($status instanceof EmployeeStatus) {
            return $status === EmployeeStatus::ACTIVE;
        }

        return (string) $status === EmployeeStatus::ACTIVE->value;
    }

    private function isUserActive(mixed $status): bool
    {
        return (string) $status === 'active';
    }

    private function isUserSuspended(mixed $status): bool
    {
        return (string) $status === 'suspended';
    }

    private function isShopOwnerSuspended(mixed $status): bool
    {
        if ($status instanceof ShopOwnerStatus) {
            return $status === ShopOwnerStatus::SUSPENDED;
        }

        return (string) $status === ShopOwnerStatus::SUSPENDED->value;
    }

    private function isShopOwnerOperational(mixed $status): bool
    {
        if ($status instanceof ShopOwnerStatus) {
            return $status === ShopOwnerStatus::APPROVED;
        }

        return (string) $status === ShopOwnerStatus::APPROVED->value;
    }

    private function logoutGuard(Request $request, string $guard): void
    {
        Auth::guard($guard)->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function suspendedResponse(Request $request, string $loginUrl, string $message, string $code = 'account_suspended'): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => $code,
            ], 403);
        }

        return redirect($loginUrl)->with('error', $message);
    }
}
