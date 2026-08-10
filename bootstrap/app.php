<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

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
                    && ($entry['classification'] ?? null) === 'module'
                    && is_string($actorGuard)
                    && $actorGuard !== '') {
                    $declaredMiddleware = $route->middleware();
                    $hasActorAuthentication = collect($declaredMiddleware)
                        ->contains(static fn (string $middleware): bool => str_contains($middleware, 'Authenticate:'.$actorGuard)
                            || $middleware === 'auth:'.$actorGuard);

                    if (! $hasActorAuthentication) {
                        $route->middleware('auth:'.$actorGuard);
                    }

                    if (! in_array('shop.module', $route->middleware(), true)) {
                        $route->middleware('shop.module');
                    }
                }
            }
        }
    )
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
