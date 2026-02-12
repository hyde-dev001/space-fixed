<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // CSRF token
            'csrf_token' => csrf_token(),
            
            // Share session flash data
            'success' => fn () => $request->session()->get('success'),
            'employee' => fn () => $request->session()->get('employee'),
            'user_id' => fn () => $request->session()->get('user_id'),
            'temporary_password' => fn () => $request->session()->get('temporary_password'),

            // Share authenticated user data based on guard
            // Only include ONE authenticated user to prevent header confusion
            'auth' => [
                'super_admin' => Auth::guard('super_admin')->check() ? [
                    'id' => Auth::guard('super_admin')->user()->id,
                    'first_name' => Auth::guard('super_admin')->user()->first_name,
                    'last_name' => Auth::guard('super_admin')->user()->last_name,
                    'name' => Auth::guard('super_admin')->user()->first_name . ' ' . Auth::guard('super_admin')->user()->last_name,
                    'email' => Auth::guard('super_admin')->user()->email,
                    'role' => Auth::guard('super_admin')->user()->role,
                ] : null,

                'shop_owner' => Auth::guard('shop_owner')->check() ? [
                    'id' => Auth::guard('shop_owner')->user()->id,
                    'first_name' => Auth::guard('shop_owner')->user()->first_name,
                    'last_name' => Auth::guard('shop_owner')->user()->last_name,
                    'name' => Auth::guard('shop_owner')->user()->first_name . ' ' . Auth::guard('shop_owner')->user()->last_name,
                    'business_name' => Auth::guard('shop_owner')->user()->business_name,
                    'email' => Auth::guard('shop_owner')->user()->email,
                ] : null,

                'user' => Auth::guard('user')->check() ? [
                    'id' => Auth::guard('user')->user()->id,
                    'first_name' => Auth::guard('user')->user()->first_name,
                    'last_name' => Auth::guard('user')->user()->last_name,
                    'name' => Auth::guard('user')->user()->name,
                    'email' => Auth::guard('user')->user()->email,
                    'role' => Auth::guard('user')->user()->role ?? null,
                    'force_password_change' => (bool) (Auth::guard('user')->user()->force_password_change ?? false),
                ] : null,
                
                // Share permissions for all guards
                'permissions' => Auth::guard('user')->check() 
                    ? Auth::guard('user')->user()->getAllPermissions()->pluck('name')->toArray()
                    : (Auth::guard('shop_owner')->check() 
                        ? ['*'] // Shop owner has full access
                        : (Auth::guard('super_admin')->check() 
                            ? ['*'] // Super admin has full access
                            : [])),
            ],
        ];
    }
}
