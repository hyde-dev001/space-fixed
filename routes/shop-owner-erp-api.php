<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Controllers\Api\CRM\CRMCustomerController;
use App\Http\Controllers\Api\CRM\CRMDashboardController;
use App\Http\Controllers\Api\CRM\CRMReviewController;
use App\Http\Controllers\Api\Logistics\RiderProfileController;
use App\Http\Controllers\Api\Logistics\ShipmentController;
use App\Http\Controllers\Logistics\ErpLogisticsController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureOwnerErpWorkspaceEnabled::class)
    ->prefix('api/shop-owner/erp')
    ->name('shop-owner.erp.api.')
    ->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'data'])
            ->name('workspace');

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
