<?php

namespace App\Http\Middleware;

use App\Enums\EmployeeStatus;
use App\Enums\ShopOwnerStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $authenticatedShopOwner = Auth::guard('shop_owner')->user();

        if ($this->isOwnerLifecycleException($routeName, $authenticatedShopOwner)) {
            return $next($request);
        }

        $validApplicationGuardPresent = Auth::guard('super_admin')->check();
        $firstDenial = null;
        $removedInvalidGuard = false;

        if (Auth::guard('shop_owner')->check()) {
            $shopOwner = $authenticatedShopOwner instanceof ShopOwner
                ? ShopOwner::withTrashed()->find($authenticatedShopOwner->getKey())
                : null;

            if (! $shopOwner || $shopOwner->trashed() || ! $this->isShopOwnerOperational($shopOwner->status)) {
                $this->logoutGuard('shop_owner');
                $removedInvalidGuard = true;
                $firstDenial ??= [
                    route('shop-owner.login.form'),
                    'Your shop account is unavailable. Please contact support.',
                    ! $shopOwner || $shopOwner->trashed() || ! $this->isShopOwnerSuspended($shopOwner->status)
                        ? 'account_unavailable'
                        : 'account_suspended',
                ];
            } else {
                $validApplicationGuardPresent = true;
            }
        }

        if (Auth::guard('user')->check()) {
            $authenticatedUser = Auth::guard('user')->user();
            $user = $authenticatedUser instanceof User
                ? User::withTrashed()->find($authenticatedUser->getKey())
                : null;

            if (! $user || $user->trashed() || ! $this->isUserActive($user->status)) {
                $this->logoutGuard('user');
                $removedInvalidGuard = true;
                $firstDenial ??= [
                    route('login'),
                    'Your account is unavailable. Please contact support.',
                    ! $user || $user->trashed() || ! $this->isUserSuspended($user->status)
                        ? 'account_unavailable'
                        : 'account_suspended',
                ];
            } elseif (! is_null($user->shop_owner_id)) {
                $shopOwner = ShopOwner::withTrashed()->find($user->shop_owner_id);

                if (! $shopOwner || $shopOwner->trashed() || ! $this->isShopOwnerOperational($shopOwner->status)) {
                    $this->logoutGuard('user');
                    $removedInvalidGuard = true;
                    $firstDenial ??= [
                        route('login'),
                        'Your account is unavailable. Please contact your administrator.',
                        ! $shopOwner || $shopOwner->trashed() || ! $this->isShopOwnerSuspended($shopOwner->status)
                            ? 'account_unavailable'
                            : 'account_suspended',
                    ];
                } else {
                    $employees = Employee::withTrashed()
                        ->where('shop_owner_id', $user->shop_owner_id)
                        ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
                        ->orderBy('id')
                        ->get();

                    if ($employees->count() > 1
                        || ($employees->count() === 1
                            && ($employees->first()->trashed() || ! $this->isEmployeeActive($employees->first()->status)))) {
                        $this->logoutGuard('user');
                        $removedInvalidGuard = true;
                        $firstDenial ??= [
                            route('login'),
                            'Your account is unavailable. Please contact your administrator.',
                            $employees->count() === 1 && $this->isEmployeeSuspended($employees->first()->status)
                                ? 'account_suspended'
                                : 'account_unavailable',
                        ];
                    } else {
                        $validApplicationGuardPresent = true;
                    }
                }
            } else {
                $validApplicationGuardPresent = true;
            }
        }

        if ($removedInvalidGuard && $request->hasSession()) {
            $request->session()->regenerate();
        }

        if (! $validApplicationGuardPresent && is_array($firstDenial)) {
            return $this->suspendedResponse($request, ...$firstDenial);
        }

        return $next($request);
    }

    private function isOwnerLifecycleException(string $routeName, mixed $shopOwner): bool
    {
        if (! $shopOwner instanceof ShopOwner) {
            return false;
        }

        if ($routeName === 'shop-owner.pending-approval') {
            return $this->isShopOwnerPending($shopOwner->status);
        }

        return in_array($routeName, [
            'shop-owner.documents.show',
            'shop-owner.resubmission.form',
            'shop-owner.resubmission.submit',
            'shop-owner.resubmission.document',
        ], true) && $this->isShopOwnerRejected($shopOwner->status);
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

    private function isShopOwnerRejected(mixed $status): bool
    {
        if ($status instanceof ShopOwnerStatus) {
            return $status === ShopOwnerStatus::REJECTED;
        }

        return (string) $status === ShopOwnerStatus::REJECTED->value;
    }

    private function isShopOwnerPending(mixed $status): bool
    {
        if ($status instanceof ShopOwnerStatus) {
            return $status === ShopOwnerStatus::PENDING;
        }

        return (string) $status === ShopOwnerStatus::PENDING->value;
    }

    private function isShopOwnerOperational(mixed $status): bool
    {
        if ($status instanceof ShopOwnerStatus) {
            return $status === ShopOwnerStatus::APPROVED;
        }

        return (string) $status === ShopOwnerStatus::APPROVED->value;
    }

    private function logoutGuard(string $guard): void
    {
        Auth::guard($guard)->logout();
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
