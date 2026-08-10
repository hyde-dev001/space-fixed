<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Controllers\Api\CRM\CRMCustomerController;
use App\Http\Controllers\Api\CRM\CRMDashboardController;
use App\Http\Controllers\Api\CRM\CRMReviewController;
use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Logistics\ErpLogisticsController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureOwnerErpWorkspaceEnabled::class)
    ->prefix('shop-owner/erp')
    ->name('shop-owner.erp.')
    ->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'index'])
            ->name('workspace');

        Route::prefix('crm')->name('crm.')->group(function (): void {
            Route::get('/', [CRMDashboardController::class, 'indexPage'])
                ->name('dashboard');
            Route::get('/customers', [CRMCustomerController::class, 'indexPage'])
                ->name('customers');
            Route::get('/customer-reviews', [CRMReviewController::class, 'indexPage'])
                ->name('customer-reviews');
        });

        Route::prefix('logistics')->name('logistics.')->group(function (): void {
            Route::get('/', [ErpLogisticsController::class, 'dashboard'])
                ->name('dashboard');
            Route::get('/shipments', [ErpLogisticsController::class, 'shipments'])
                ->name('shipments');
            Route::get('/riders', [ErpLogisticsController::class, 'riders'])
                ->name('riders');
        });

        Route::get('/hr/audit-logs', [ReadPageController::class, 'hrAuditLogs'])
            ->name('hr.audit-logs');
        Route::get('/finance/audit-logs', [ReadPageController::class, 'financeAuditLogs'])
            ->name('finance.audit-logs');

        Route::prefix('manager')->name('manager.')->group(function (): void {
            Route::get('/reports', [ReadPageController::class, 'managerReports'])
                ->name('reports');
            Route::get('/audit-logs', [ReadPageController::class, 'managerAuditLogs'])
                ->name('audit-logs');
        });

        Route::prefix('inventory')->name('inventory.')->group(function (): void {
            Route::get('/inventory-dashboard', [ReadPageController::class, 'inventoryDashboard'])
                ->name('inventory-dashboard');
            Route::get('/product-inventory', [ReadPageController::class, 'productInventory'])
                ->name('product-inventory');
            Route::get('/stock-movement', [ReadPageController::class, 'stockMovement'])
                ->name('stock-movement');
        });

        Route::get('/procurement/suppliers-management', [ReadPageController::class, 'procurementSuppliers'])
            ->name('procurement.suppliers-management');
    });
