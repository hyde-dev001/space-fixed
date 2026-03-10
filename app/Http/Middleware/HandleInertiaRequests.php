<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Models\ConversationMessage;
use App\Models\CartItem;
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
        $user = Auth::guard('user')->user();
        $isCustomer = $user && empty($user->shop_owner_id);

        $orderStatusCount = 0;
        $repairStatusCount = 0;
        $chatIconCount = 0;
        $cartIconCount = 0;

        if ($isCustomer) {
            $orderTypes = [
                'order_placed',
                'order_confirmed',
                'order_shipped',
                'order_delivered',
                'order_cancelled',
                'order_status_update',
            ];

            $repairTypes = [
                'repair_submitted',
                'repair_assigned',
                'repair_accepted',
                'repair_rejected',
                'repair_in_progress',
                'repair_completed',
                'repair_ready_pickup',
                'repair_status_update',
            ];

            $orderStatusCount = Notification::query()
                ->where('user_id', $user->id)
                ->where('is_read', false)
                ->whereIn('type', $orderTypes)
                ->count();

            $repairStatusCount = Notification::query()
                ->where('user_id', $user->id)
                ->where('is_read', false)
                ->whereIn('type', $repairTypes)
                ->count();

            $chatIconCount = ConversationMessage::query()
                ->whereNull('read_at')
                ->where('sender_id', '!=', $user->id)
                ->whereHas('conversation', function ($query) use ($user) {
                    $query->where('customer_id', $user->id);
                })
                ->count();

            $cartIconCount = CartItem::query()
                ->where('user_id', $user->id)
                ->sum('quantity');
        }

        return [
            ...parent::share($request),
            // CSRF token
            'csrf_token' => csrf_token(),
            'orderStatusCount' => $orderStatusCount,
            'repairStatusCount' => $repairStatusCount,
            'userIconCount' => $orderStatusCount + $repairStatusCount,
            'chatIconCount' => $chatIconCount,
            'cartIconCount' => $cartIconCount,
            
            // Share session flash data
            'success' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
            'employee' => fn () => $request->session()->get('employee'),
            'user_id' => fn () => $request->session()->get('user_id'),
            'invite_url' => fn () => $request->session()->get('invite_url'),
            'invite_expires_at' => fn () => $request->session()->get('invite_expires_at'),
            'work_email' => fn () => $request->session()->get('work_email'),
            'email_sent' => fn () => $request->session()->get('email_sent'),

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
                    'profile_photo' => Auth::guard('shop_owner')->user()->profile_photo,
                    'business_name' => Auth::guard('shop_owner')->user()->business_name,
                    'email' => Auth::guard('shop_owner')->user()->email,
                    'business_type' => Auth::guard('shop_owner')->user()->business_type,
                    'registration_type' => Auth::guard('shop_owner')->user()->registration_type,
                    'status' => Auth::guard('shop_owner')->user()->status,
                    'is_individual' => Auth::guard('shop_owner')->user()->isIndividual(),
                    'is_company' => Auth::guard('shop_owner')->user()->isCompany(),
                    'can_manage_staff' => Auth::guard('shop_owner')->user()->canManageStaff(),
                    'max_locations' => Auth::guard('shop_owner')->user()->getMaxLocations(),
                ] : null,

                'user' => Auth::guard('user')->check() ? [
                    'id' => Auth::guard('user')->user()->id,
                    'first_name' => Auth::guard('user')->user()->first_name,
                    'last_name' => Auth::guard('user')->user()->last_name,
                    'name' => Auth::guard('user')->user()->name,
                    'email' => Auth::guard('user')->user()->email,
                    'role' => Auth::guard('user')->user()->role ?? null,
                    'shop_owner_id' => Auth::guard('user')->user()->shop_owner_id ?? null,
                    'force_password_change' => (bool) (Auth::guard('user')->user()->force_password_change ?? false),
                    'roles' => Auth::guard('user')->user()->getRoleNames()->toArray(), // Spatie roles
                    'shop_owner' => Auth::guard('user')->user()->shopOwner ? [
                        'business_type' => Auth::guard('user')->user()->shopOwner->business_type,
                        'registration_type' => Auth::guard('user')->user()->shopOwner->registration_type,
                        'business_name' => Auth::guard('user')->user()->shopOwner->business_name,
                    ] : null,
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
