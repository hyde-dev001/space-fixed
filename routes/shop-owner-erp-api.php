<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CRM\CRMCustomerController;
use App\Http\Controllers\Api\CRM\CRMDashboardController;
use App\Http\Controllers\Api\CRM\CRMReviewController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Erp\HR\AuditLogController as HRAuditLogController;
use App\Http\Controllers\Erp\HR\AuditLogController as FinanceAuditLogController;
use App\Http\Controllers\Api\Logistics\RiderProfileController;
use App\Http\Controllers\Api\Logistics\ShipmentController;
use App\Http\Controllers\Logistics\ErpLogisticsController;
use App\Http\Controllers\Erp\InventoryDashboardController;
use App\Http\Controllers\Erp\ProductInventoryController;
use App\Http\Controllers\Erp\StockMovementController;
use App\Http\Controllers\Erp\SupplierController;
use App\Http\Controllers\ShopOwner\ShopOwnerOperationsMonitoringController;
use App\Http\Controllers\Api\Staff\CustomerController as StaffCustomerApiController;
use App\Http\Controllers\Repairer\DashboardController as RepairerDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/shop-owner/erp')
    ->name('shop-owner.erp.api.')
    ->group(function (): void {
        Route::get('/hr/audit-logs', [HRAuditLogController::class, 'index'])
            ->name('hr.audit-logs');
        Route::get('/finance/audit-logs', [FinanceAuditLogController::class, 'index'])
            ->name('finance.audit-logs');
        Route::get('/manager/reports', [ManagerController::class, 'getReports'])
            ->name('manager.reports');
        Route::get('/manager/audit-logs', [ActivityLogController::class, 'index'])
            ->name('manager.audit-logs');

        // Company-owner monitoring projections reuse the normalized Manager
        // read shape without exposing Manager mutation routes.
        Route::prefix('operations')->name('operations.')->group(function (): void {
            Route::get('/orders', [ShopOwnerOperationsMonitoringController::class, 'orders'])
                ->name('orders');
            Route::get('/orders/{id}', [ShopOwnerOperationsMonitoringController::class, 'showOrder'])
                ->whereNumber('id')
                ->name('orders.show');
            Route::get('/repairs', [ShopOwnerOperationsMonitoringController::class, 'repairs'])
                ->name('repairs');
            Route::get('/repairs/{id}', [ShopOwnerOperationsMonitoringController::class, 'showRepair'])
                ->whereNumber('id')
                ->name('repairs.show');
        });

        Route::get('/inventory/dashboard', [InventoryDashboardController::class, 'index'])
            ->name('inventory.dashboard');
        Route::get('/inventory/products', [ProductInventoryController::class, 'index'])
            ->name('inventory.products.index');
        Route::get('/inventory/movements', [StockMovementController::class, 'index'])
            ->name('inventory.movements.index');
        Route::get('/procurement/suppliers', [SupplierController::class, 'index'])
            ->name('procurement.suppliers.index');

        Route::get('/staff/customers', [StaffCustomerApiController::class, 'index'])
            ->name('staff.customers');
        Route::get('/staff/repair-dashboard', [RepairerDashboardController::class, 'getDashboardData'])
            ->name('staff.repair-dashboard');

        Route::get('/crm/dashboard-stats', [CRMDashboardController::class, 'index'])
            ->name('crm.dashboard-stats');
        Route::get('/crm/customers', [CRMCustomerController::class, 'index'])
            ->name('crm.customers.index');
        Route::get('/crm/customers/{id}', [CRMCustomerController::class, 'show'])
            ->name('crm.customers.show');
        Route::get('/crm/reviews', [CRMReviewController::class, 'index'])
            ->name('crm.reviews.index');

        Route::get('/logistics/dashboard-stats', [ErpLogisticsController::class, 'dashboardStats'])
            ->name('logistics.dashboard-stats');
        Route::get('/logistics/shipments', [ShipmentController::class, 'index'])
            ->name('logistics.shipments.index');
        Route::get('/logistics/shipments/{shipment}', [ShipmentController::class, 'show'])
            ->name('logistics.shipments.show');
        Route::get('/logistics/riders', [RiderProfileController::class, 'index'])
            ->name('logistics.riders.index');
    });
