<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureErpAudience;
use App\Http\Middleware\ResolveErpActorContext;
use App\Http\Middleware\EnsureShopModuleEnabled;
use App\Support\Erp\ErpAccessResponder;
use App\Support\Erp\ErpActorContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/inventory-api.php'));
            
            Route::middleware('api')
                ->group(base_path('routes/hr-api.php'));
            
            Route::middleware('api')
                ->group(base_path('routes/finance-api.php'));
            
            Route::middleware('api')
                ->group(base_path('routes/permission-audit-api.php'));
            
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/procurement-api.php'));

            Route::middleware('api')
                ->group(base_path('routes/shop-owner-api.php'));

            $isErpRoute = static function (string $routeName): bool {
                foreach ([
                    'api.manager.',
                    'crm.',
                    'erp.',
                    'finance.',
                    'hr.',
                    'inventory.',
                    'procurement.',
                    'staff.',
                    'shop-owner.erp.',
                    'shop_owner.erp.',
                ] as $prefix) {
                    if (str_starts_with($routeName, $prefix)) {
                        return true;
                    }
                }

                return false;
            };

            foreach (Route::getRoutes() as $route) {
                $routeName = $route->getName();
                $routeCatalog = config('shop_modules.routes', []);
                $entry = is_string($routeName)
                    ? ($routeCatalog[$routeName] ?? config("shop_modules.routes.{$routeName}"))
                    : null;

                $actorGuard = is_array($entry)
                    ? ($entry['actor_guard'] ?? ($entry['actor_guards'][0] ?? null))
                    : null;

                if (is_array($entry)
                    && in_array(($entry['classification'] ?? null), ['core', 'module'], true)
                    && is_string($actorGuard)
                    && $actorGuard !== '') {
                    $declaredMiddleware = $route->middleware();
                    $isOperationalErpRoute = is_string($routeName) && $isErpRoute($routeName);

                    if ($isOperationalErpRoute
                        && ! in_array(EnsureErpAudience::class, $declaredMiddleware, true)) {
                        $route->middleware(EnsureErpAudience::class);
                    }

                    $hasActorAuthentication = collect($declaredMiddleware)
                        ->contains(static fn (string $middleware): bool => str_contains($middleware, 'Authenticate:'.$actorGuard)
                            || $middleware === 'auth:'.$actorGuard);

                    if (! $hasActorAuthentication) {
                        $route->middleware('auth:'.$actorGuard);
                    }

                    if ($isOperationalErpRoute
                        && ! in_array(ResolveErpActorContext::class, $route->middleware(), true)) {
                        $route->middleware(ResolveErpActorContext::class);
                    }

                    if (($entry['classification'] ?? null) === 'module'
                        && ! in_array('shop.module', $route->middleware(), true)) {
                        $route->middleware('shop.module');
                    }
                }
            }
        }
    )
    ->withScopedSingletons([
        ErpActorContext::class => static function (): ErpActorContext {
            $context = app('request')->attributes->get('erp.actor_context');

            if (! $context instanceof ErpActorContext) {
                throw new \LogicException('An ERP actor context has not been resolved for this request.');
            }

            return $context;
        },
    ])
    ->registered(function (Application $app): void {
        $app->rebinding('request', static function (Application $app): void {
            $app->forgetInstance(ErpActorContext::class);
        });
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\CheckEmployeeSuspension::class,
        ]);
        $middleware->api([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\CheckEmployeeSuspension::class,
            'throttle:60,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Http\Middleware\HandleCors::class
        ]);
        // Exclude payment endpoint from Sanctum middleware
        $middleware->validateCsrfTokens(except: [
            'api/create-payment-link',
            'api/staff/*',
        ]);
        $middleware->alias([
            'super_admin.auth' => \App\Http\Middleware\SuperAdminAuth::class,
            'super_admin.role' => \App\Http\Middleware\CheckSuperAdminRole::class,
            'shop.isolation' => \App\Http\Middleware\ShopIsolationMiddleware::class,
            'customer.account' => \App\Http\Middleware\EnsureCustomerAccount::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'old_role' => \App\Http\Middleware\RoleMiddleware::class, // Keep for rollback
            'gate.erp.access' => \App\Http\Middleware\GateErpAccess::class,
            'manager.staff' => \App\Http\Middleware\CheckManagerStaffAccess::class,
            'check.suspension' => \App\Http\Middleware\CheckEmployeeSuspension::class,
            // Shop Owner Access Control
            'check.business.type' => \App\Http\Middleware\CheckBusinessType::class,
            'check.user.business.type' => \App\Http\Middleware\CheckUserBusinessType::class,
            'check.registration.type' => \App\Http\Middleware\CheckRegistrationType::class,
            'has.active.retail.premium' => \App\Http\Middleware\HasActiveRetailPremium::class,
            'shop.module' => \App\Http\Middleware\EnsureShopModuleEnabled::class,
            'auth' => Authenticate::class,
            'erp.audience' => EnsureErpAudience::class,
            'erp.actor' => ResolveErpActorContext::class,
        ]);
        $middleware->priority([
            EnsureErpAudience::class,
            Authenticate::class,
            ResolveErpActorContext::class,
            EnsureShopModuleEnabled::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            $responder = app(ErpAccessResponder::class);
            if (! $responder->isOwnerErpRequest($request)) {
                return null;
            }

            return $responder->deny($request, 'ERP_AUTH_REQUIRED', [], null, 401);
        });
    })->create();
