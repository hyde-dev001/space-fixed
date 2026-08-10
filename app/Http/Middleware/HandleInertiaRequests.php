<?php

namespace App\Http\Middleware;

use App\Models\CartItem;
use App\Models\ConversationMessage;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ErpRouteCatalog;
use App\Services\ShopModuleAccessService;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly ShopModuleAccessService $shopModuleAccess,
        private readonly ErpRouteCatalog $erpRouteCatalog,
    ) {}

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
        $erpContext = $request->attributes->get('erp.actor_context');
        $erpContext = $erpContext instanceof ErpActorContext ? $erpContext : null;
        $user = Auth::guard('user')->user();
        $isCustomer = $user && empty($user->shop_owner_id);
        $internalShopOwner = $erpContext?->tenantOwner();

        if ($internalShopOwner === null && ! Auth::guard('super_admin')->check()) {
            if (Auth::guard('shop_owner')->check()) {
                $internalShopOwner = Auth::guard('shop_owner')->user();
            } elseif ($user && ! $isCustomer) {
                $internalShopOwner = $this->shopModuleAccess->resolveShopOwnerForActor($user);
                if ($internalShopOwner && ! $user->relationLoaded('shopOwner')) {
                    $user->setRelation('shopOwner', $internalShopOwner);
                }
            }
        }

        $ownerMode = $erpContext?->isOwnerMode() ?? Auth::guard('shop_owner')->check();
        $moduleEnforcementEnabled = (bool) config('shop_modules.enforcement_enabled', false);
        $moduleStatesResolved = false;
        $moduleStatesCache = [];
        $moduleStates = function () use (&$moduleStatesResolved, &$moduleStatesCache, $internalShopOwner): array {
            if (! $moduleStatesResolved) {
                $moduleStatesCache = $internalShopOwner instanceof ShopOwner
                    ? $this->shopModuleAccess->statesFor($internalShopOwner)
                    : [];
                $moduleStatesResolved = true;
            }

            return $moduleStatesCache;
        };

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
                ->whereHas('conversation', function ($query) use ($user) {
                    $query->where('customer_id', $user->id);
                })
                ->where(function ($query) use ($user) {
                    $query->where('sender_type', '!=', 'customer')
                        ->orWhere(function ($legacyQuery) use ($user) {
                            $legacyQuery->whereNull('sender_type')
                                ->where('sender_id', '!=', $user->id);
                        });
                })
                ->count();

            $cartIconCount = CartItem::query()
                ->where('user_id', $user->id)
                ->sum('quantity');
        }

        $permissions = $this->sharedPermissions($erpContext, $user);

        return [
            ...parent::share($request),
            // CSRF token
            'csrf_token' => csrf_token(),
            'orderStatusCount' => $orderStatusCount,
            'repairStatusCount' => $repairStatusCount,
            'userIconCount' => $orderStatusCount + $repairStatusCount,
            'chatIconCount' => $chatIconCount,
            'cartIconCount' => $cartIconCount,
            'ownerMode' => $ownerMode,
            'moduleStates' => $moduleStates,
            'shopModuleEnforcementEnabled' => $moduleEnforcementEnabled,
            'erpCapabilities' => $this->erpCapabilities(
                context: $erpContext,
                tenantOwner: $internalShopOwner,
                enforceState: $moduleEnforcementEnabled,
            ),
            'erpUrls' => $this->erpUrls($ownerMode),

            // Share session flash data
            'success' => fn() => $request->session()->get('success'),
            'error' => fn() => $request->session()->get('error'),
            'employee' => fn() => $request->session()->get('employee'),
            'user_id' => fn() => $request->session()->get('user_id'),
            'invite_url' => fn() => $request->session()->get('invite_url'),
            'invite_expires_at' => fn() => $request->session()->get('invite_expires_at'),
            'work_email' => fn() => $request->session()->get('work_email'),
            'email_sent' => fn() => $request->session()->get('email_sent'),

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
                    'repair_payment_policy' => Auth::guard('shop_owner')->user()->repair_payment_policy === 'full_upfront'
                        ? 'full_upfront'
                        : 'deposit_50',
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
                    'profile_photo' => Auth::guard('user')->user()->profile_photo,
                    'profile_photo_url' => Auth::guard('user')->user()->profile_photo
                        ? (str_starts_with(Auth::guard('user')->user()->profile_photo, '/')
                            ? Auth::guard('user')->user()->profile_photo
                            : '/storage/' . ltrim(Auth::guard('user')->user()->profile_photo, '/'))
                        : null,
                    'role' => Auth::guard('user')->user()->role ?? null,
                    'shop_owner_id' => Auth::guard('user')->user()->shop_owner_id ?? null,
                    'force_password_change' => (bool) (Auth::guard('user')->user()->force_password_change ?? false),
                    'roles' => Auth::guard('user')->user()->getRoleNames()->toArray(), // Spatie roles
                    'shop_owner' => Auth::guard('user')->user()->shopOwner ? [
                        'business_type' => Auth::guard('user')->user()->shopOwner->business_type,
                        'registration_type' => Auth::guard('user')->user()->shopOwner->registration_type,
                        'business_name' => Auth::guard('user')->user()->shopOwner->business_name,
                        'repair_payment_policy' => Auth::guard('user')->user()->shopOwner->repair_payment_policy === 'full_upfront'
                            ? 'full_upfront'
                            : 'deposit_50',
                    ] : null,
                ] : null,

                'erpActor' => $erpContext === null ? null : $this->erpActor($erpContext),

                'shopModuleEnforcementEnabled' => $moduleEnforcementEnabled,

                // Share permissions for all guards
                'permissions' => $permissions,

                ...($internalShopOwner ? [
                    'shopModules' => $moduleStates,
                ] : []),
            ],
        ];
    }

    /**
     * @return array{type: string, id: int, name: string, guard: string, ownerMode: bool, tenantOwnerId: int}
     */
    private function erpActor(ErpActorContext $context): array
    {
        $actor = $context->actor();
        $name = $context->isOwnerMode() && $context->ownerActor() instanceof ShopOwner
            ? (string) $context->ownerActor()->business_name
            : ($context->employeeActor()?->name ?? trim((string) ($actor->first_name ?? '').' '.(string) ($actor->last_name ?? '')));

        return [
            'type' => $context->isOwnerMode() ? 'shop_owner' : 'employee',
            'id' => (int) $actor->getAuthIdentifier(),
            'name' => $name,
            'guard' => $context->guard(),
            'ownerMode' => $context->isOwnerMode(),
            'tenantOwnerId' => (int) $context->tenantOwner()->getKey(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sharedPermissions(?ErpActorContext $context, ?User $user): array
    {
        if ($context?->isOwnerMode()) {
            return [];
        }

        if ($context !== null && $context->employeeActor() instanceof User) {
            return $context->employeeActor()->getAllPermissions()->pluck('name')->toArray();
        }

        if ($user instanceof User) {
            return $user->getAllPermissions()->pluck('name')->toArray();
        }

        return Auth::guard('super_admin')->check() ? ['*'] : [];
    }

    /**
     * @return array<string, array{allowed: bool, method: string, routeName: string, url: string|null, reason: string|null}>
     */
    private function erpCapabilities(
        ?ErpActorContext $context,
        ?ShopOwner $tenantOwner,
        bool $enforceState,
    ): array {
        if ($context === null || ! $tenantOwner instanceof ShopOwner) {
            return [];
        }

        $capabilities = [];
        $moduleStateLoaded = false;

        foreach ($this->erpRouteCatalog->all() as $routeName => $entry) {
            if (! is_array($entry) || ! in_array($entry['classification'] ?? null, ['core', 'module'], true)) {
                continue;
            }

            $audience = $entry['audience'] ?? null;
            if ($context->isOwnerMode()) {
                if ($audience === 'user') {
                    foreach ($entry['methods'] ?? [] as $method) {
                        $exposure = $this->erpRouteCatalog->ownerExposure((string) $method, (string) $routeName);
                        if ($exposure === null) {
                            continue;
                        }

                        $ownerEntry = $this->erpRouteCatalog->entry($exposure['route_name']);
                        if (($ownerEntry['owner_access'] ?? null) !== 'allowed') {
                            continue;
                        }

                        $this->addErpCapability(
                            capabilities: $capabilities,
                            key: $this->erpRouteCatalog->canonicalClientKey((string) $method, (string) $routeName),
                            method: (string) $method,
                            routeName: $exposure['route_name'],
                            entry: $ownerEntry,
                            tenantOwner: $tenantOwner,
                            enforceState: $enforceState,
                            moduleStateLoaded: $moduleStateLoaded,
                        );
                    }

                    continue;
                }

                if ($audience !== 'shop_owner' || ($entry['owner_access'] ?? null) !== 'allowed'
                    || is_string($entry['paired_route'] ?? null)) {
                    continue;
                }
            } elseif ($audience !== 'user') {
                continue;
            }

            foreach ($entry['methods'] ?? [] as $method) {
                $this->addErpCapability(
                    capabilities: $capabilities,
                    key: $this->erpRouteCatalog->canonicalClientKey((string) $method, (string) $routeName),
                    method: (string) $method,
                    routeName: (string) $routeName,
                    entry: $entry,
                    tenantOwner: $tenantOwner,
                    enforceState: $enforceState,
                    moduleStateLoaded: $moduleStateLoaded,
                );
            }
        }

        return $capabilities;
    }

    /**
     * @param  array<string, array{allowed: bool, method: string, routeName: string, url: string|null, reason: string|null}>  $capabilities
     * @param  array<string, mixed>  $entry
     */
    private function addErpCapability(
        array &$capabilities,
        string $key,
        string $method,
        string $routeName,
        array $entry,
        ShopOwner $tenantOwner,
        bool $enforceState,
        bool &$moduleStateLoaded,
    ): void {
        $decision = null;
        if (($entry['classification'] ?? null) === 'module') {
            if ($enforceState && ! $moduleStateLoaded) {
                $tenantOwner->loadMissing('modules');
                $moduleStateLoaded = true;
            }

            $mode = is_string($entry['mode'] ?? null) ? $entry['mode'] : '';
            $moduleKeys = is_array($entry['module_keys'] ?? null) ? $entry['module_keys'] : [];
            $decision = $this->shopModuleAccess->decideGate(
                owner: $tenantOwner,
                mode: $mode,
                moduleKeys: array_values(array_map('strval', $moduleKeys)),
                enforceState: $enforceState,
            );
        }

        $allowed = $decision?->allowed ?? true;
        $url = $this->routeUrl($routeName);
        $capabilities[$key] = [
            'allowed' => $allowed,
            'method' => strtoupper($method),
            'routeName' => $routeName,
            'url' => $url,
            'reason' => $allowed ? null : ($decision?->code ?? 'ERP_ROUTE_NOT_ALLOWED'),
        ];
    }

    private function routeUrl(string $routeName): ?string
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);
        if (! $route instanceof Route || $route->parameterNames() !== []) {
            return null;
        }

        return route($routeName);
    }

    /**
     * @return array{portal: string|null, settings: string|null, workspace: string|null, notifications: string|null, profile: string|null, logout: string|null, manageModules: string|null}
     */
    private function erpUrls(bool $ownerMode): array
    {
        if (! $ownerMode) {
            return [
                'portal' => null,
                'settings' => null,
                'workspace' => null,
                'notifications' => null,
                'profile' => null,
                'logout' => null,
                'manageModules' => null,
            ];
        }

        $settings = $this->namedRouteUrl('shop-owner.settings');

        return [
            'portal' => $this->namedRouteUrl('shop-owner.dashboard'),
            'settings' => $settings,
            'workspace' => (bool) config('shop_modules.owner_erp_workspace_enabled', false)
                ? $this->namedRouteUrl('shop-owner.erp.workspace')
                : null,
            'notifications' => $this->namedRouteUrl('shop-owner.notifications.index'),
            'profile' => $this->namedRouteUrl('shop-owner.shop-profile'),
            'logout' => $this->namedRouteUrl('shop-owner.logout'),
            'manageModules' => $settings,
        ];
    }

    private function namedRouteUrl(string $routeName): ?string
    {
        return RouteFacade::has($routeName) ? route($routeName) : null;
    }
}
