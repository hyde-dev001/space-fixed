<?php

use App\Http\Controllers\ERP\PurchaseRequestController;
use App\Http\Controllers\ERP\PurchaseOrderController;
use App\Http\Controllers\ERP\ReplenishmentRequestController;
use App\Http\Controllers\ERP\StockRequestApprovalController;
use App\Http\Controllers\ERP\SupplierController;
use App\Http\Controllers\ERP\ProcurementSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Procurement API Routes
|--------------------------------------------------------------------------
|
| Here are all the API routes for the Procurement Management System.
| These routes handle Purchase Requests, Purchase Orders, Replenishment
| Requests, Stock Request Approvals, and Procurement Settings.
|
*/

Route::middleware([
    'web',
    'auth:user',
    'permission:view-procurement|access-procurement-dashboard|access-purchase-requests|access-purchase-orders|access-stock-request-approval|access-suppliers-management|access-supplier-order-monitoring',
    'shop.isolation',
])->prefix('erp/procurement')->group(function () {
    
    // Purchase Requests Routes
    Route::prefix('purchase-requests')->group(function () {
        Route::get('/', [PurchaseRequestController::class, 'index'])->name('procurement.purchase-requests.index');
        Route::post('/', [PurchaseRequestController::class, 'store'])->name('procurement.purchase-requests.store');
        Route::get('/metrics', [PurchaseRequestController::class, 'getMetrics'])->name('procurement.purchase-requests.metrics');
        Route::get('/approved', [PurchaseRequestController::class, 'getApprovedPRs'])->name('procurement.purchase-requests.approved');
        Route::get('/{id}', [PurchaseRequestController::class, 'show'])->name('procurement.purchase-requests.show');
        Route::put('/{id}', [PurchaseRequestController::class, 'update'])->name('procurement.purchase-requests.update');
        Route::delete('/{id}', [PurchaseRequestController::class, 'destroy'])->name('procurement.purchase-requests.destroy');
        Route::post('/{id}/submit-to-finance', [PurchaseRequestController::class, 'submitToFinance'])->name('procurement.purchase-requests.submit-finance');
        Route::post('/{id}/approve', [PurchaseRequestController::class, 'approve'])->name('procurement.purchase-requests.approve');
        Route::post('/{id}/reject', [PurchaseRequestController::class, 'reject'])->name('procurement.purchase-requests.reject');
    });
    
    // Purchase Orders Routes
    Route::prefix('purchase-orders')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('procurement.purchase-orders.index');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('procurement.purchase-orders.store');
        Route::get('/metrics', [PurchaseOrderController::class, 'getMetrics'])->name('procurement.purchase-orders.metrics');
        Route::get('/{id}', [PurchaseOrderController::class, 'show'])->name('procurement.purchase-orders.show');
        Route::put('/{id}', [PurchaseOrderController::class, 'update'])->name('procurement.purchase-orders.update');
        Route::delete('/{id}', [PurchaseOrderController::class, 'destroy'])->name('procurement.purchase-orders.destroy');
        Route::post('/{id}/update-status', [PurchaseOrderController::class, 'updateStatus'])->name('procurement.purchase-orders.update-status');
        Route::post('/{id}/send-to-supplier', [PurchaseOrderController::class, 'sendToSupplier'])->name('procurement.purchase-orders.send-supplier');
        Route::post('/{id}/mark-delivered', [PurchaseOrderController::class, 'markAsDelivered'])->name('procurement.purchase-orders.mark-delivered');
        Route::post('/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('procurement.purchase-orders.cancel');
    });
    
    // Replenishment Requests Routes
    Route::prefix('replenishment-requests')->group(function () {
        Route::get('/', [ReplenishmentRequestController::class, 'index'])->name('procurement.replenishment-requests.index');
        Route::post('/', [ReplenishmentRequestController::class, 'store'])->name('procurement.replenishment-requests.store');
        Route::get('/{id}', [ReplenishmentRequestController::class, 'show'])->name('procurement.replenishment-requests.show');
        Route::put('/{id}', [ReplenishmentRequestController::class, 'update'])->name('procurement.replenishment-requests.update');
        Route::delete('/{id}', [ReplenishmentRequestController::class, 'destroy'])->name('procurement.replenishment-requests.destroy');
        Route::post('/{id}/accept', [ReplenishmentRequestController::class, 'accept'])->name('procurement.replenishment-requests.accept');
        Route::post('/{id}/reject', [ReplenishmentRequestController::class, 'reject'])->name('procurement.replenishment-requests.reject');
        Route::post('/{id}/request-details', [ReplenishmentRequestController::class, 'requestDetails'])->name('procurement.replenishment-requests.request-details');
    });
    
    // Stock Request Approvals Routes
    Route::prefix('stock-requests')->group(function () {
        Route::get('/', [StockRequestApprovalController::class, 'index'])->name('procurement.stock-requests.index');
        Route::get('/metrics', [StockRequestApprovalController::class, 'getMetrics'])->name('procurement.stock-requests.metrics');
        Route::post('/', [StockRequestApprovalController::class, 'store'])->name('procurement.stock-requests.store');
        Route::get('/{id}', [StockRequestApprovalController::class, 'show'])->name('procurement.stock-requests.show');
        Route::post('/{id}/approve', [StockRequestApprovalController::class, 'approve'])->name('procurement.stock-requests.approve');
        Route::post('/{id}/reject', [StockRequestApprovalController::class, 'reject'])->name('procurement.stock-requests.reject');
        Route::post('/{id}/request-details', [StockRequestApprovalController::class, 'requestDetails'])->name('procurement.stock-requests.request-details');
    });
    
    // Suppliers Routes (Procurement-specific enhancements)
    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('procurement.suppliers.index');
        Route::post('/', [SupplierController::class, 'store'])->name('procurement.suppliers.store');
        Route::get('/{id}', [SupplierController::class, 'show'])->name('procurement.suppliers.show');
        Route::put('/{id}', [SupplierController::class, 'update'])->name('procurement.suppliers.update');
        Route::delete('/{id}', [SupplierController::class, 'destroy'])->name('procurement.suppliers.destroy');
        Route::get('/{id}/purchase-history', [SupplierController::class, 'getPurchaseHistory'])->name('procurement.suppliers.purchase-history');
        Route::get('/{id}/performance', [SupplierController::class, 'getPerformanceMetrics'])->name('procurement.suppliers.performance');
        Route::post('/{id}/rating', [SupplierController::class, 'updatePerformanceRating'])->name('procurement.suppliers.rating');
    });
    
    // Procurement Settings Routes
    Route::prefix('settings')->group(function () {
        Route::get('/', [ProcurementSettingsController::class, 'show'])->name('procurement.settings.show');
        Route::put('/', [ProcurementSettingsController::class, 'update'])->name('procurement.settings.update');
    });
});
