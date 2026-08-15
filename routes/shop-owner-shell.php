<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Controllers\ShopOwner\CanonicalOwnerPaymentsController;
use App\Http\Controllers\ShopOwner\OwnerErpFallbackController;
use App\Http\Controllers\ShopOwner\OwnerActionCenterController;
use App\Http\Controllers\ShopOwner\ShopOwnerDashboardController;
use App\Http\Controllers\ShopOwner\ShopSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('shop-owner')
    ->name('shop-owner.shell.')
    ->middleware('auth:shop_owner')
    ->group(function (): void {
        Route::get('/action-center', OwnerActionCenterController::class)
            ->name('action-center');

        Route::get('/home', ShopOwnerDashboardController::class)
            ->defaults('canonical_home', true)
            ->name('home');

        Route::get('/erp/fallback', OwnerErpFallbackController::class)
            ->name('erp-fallback');

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

        Route::get('/settings/profile', [ShopSettingsController::class, 'index'])
            ->defaults('initial_section', 'profile')
            ->name('settings.profile');
        Route::get('/settings/modules-team', [ShopSettingsController::class, 'index'])
            ->defaults('initial_section', 'modules-team')
            ->name('settings.modules-team');
        Route::get('/settings/payments-approvals', [ShopSettingsController::class, 'index'])
            ->defaults('initial_section', 'payments-approvals')
            ->name('settings.payments-approvals');
        Route::get('/settings/operations', [ShopSettingsController::class, 'index'])
            ->defaults('initial_section', 'operations')
            ->name('settings.operations');
        Route::get('/settings/policies-compliance', [ShopSettingsController::class, 'index'])
            ->defaults('initial_section', 'policies-compliance')
            ->name('settings.policies-compliance');
        Route::get('/settings/subscription', [ShopSettingsController::class, 'index'])
            ->defaults('initial_section', 'subscription')
            ->name('settings.subscription');
    });
