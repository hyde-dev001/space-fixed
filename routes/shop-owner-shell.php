<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Controllers\ShopOwner\CanonicalOwnerPaymentsController;
use App\Http\Controllers\ShopOwner\ShopOwnerDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('shop-owner')
    ->name('shop-owner.shell.')
    ->middleware('auth:shop_owner')
    ->group(function (): void {
        Route::get('/home', ShopOwnerDashboardController::class)
            ->defaults('canonical_home', true)
            ->name('home');

        Route::middleware(['erp.audience', 'erp.actor', 'shop.module'])->group(function (): void {
            Route::get('/operate/retail', [WorkspaceController::class, 'module'])
                ->defaults('module', 'retail')
                ->name('operate.retail');
            Route::get('/operate/repair', [WorkspaceController::class, 'module'])
                ->defaults('module', 'repair')
                ->name('operate.repair');
            Route::get('/operate/customers', [WorkspaceController::class, 'module'])
                ->defaults('module', 'crm')
                ->name('operate.customers');
            Route::get('/operate/payments', CanonicalOwnerPaymentsController::class)
                ->name('operate.payments');

            Route::get('/oversee/finance', [WorkspaceController::class, 'module'])
                ->defaults('module', 'finance')
                ->name('oversee.finance');
            Route::get('/oversee/workforce', [WorkspaceController::class, 'module'])
                ->defaults('module', 'hr')
                ->name('oversee.workforce');
            Route::get('/oversee/inventory', [WorkspaceController::class, 'module'])
                ->defaults('module', 'inventory')
                ->name('oversee.inventory');
            Route::get('/oversee/procurement', [WorkspaceController::class, 'module'])
                ->defaults('module', 'procurement')
                ->name('oversee.procurement');
            Route::get('/oversee/logistics', [WorkspaceController::class, 'module'])
                ->defaults('module', 'logistics')
                ->name('oversee.logistics');
        });

        Route::middleware(['erp.audience', 'erp.actor'])->group(function (): void {
            Route::get('/reports', [ReadPageController::class, 'managerReports'])
                ->name('reports');
            Route::get('/audit', [ReadPageController::class, 'managerAuditLogs'])
                ->name('audit');
        });
    });
