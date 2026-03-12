<?php

use App\Http\Controllers\ERP\InventoryDashboardController;
use App\Http\Controllers\ERP\ProductInventoryController;
use App\Http\Controllers\ERP\StockMovementController;
use App\Http\Controllers\ERP\SupplierOrderMonitoringController;
use App\Http\Controllers\ERP\UploadInventoryController;
use App\Http\Controllers\ERP\SupplierController;
use App\Http\Controllers\ERP\SupplierOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory API Routes
|--------------------------------------------------------------------------
|
| All routes for the Inventory Management System.
| These routes are protected by auth:sanctum middleware.
|
*/

Route::middleware(['auth:sanctum'])->prefix('erp/inventory')->group(function () {
    
    // =====================================
    // Inventory Dashboard
    // =====================================
    Route::get('/dashboard', [InventoryDashboardController::class, 'index'])->name('inventory.dashboard');
    Route::get('/dashboard/metrics', [InventoryDashboardController::class, 'getMetrics'])->name('inventory.dashboard.metrics');
    Route::get('/dashboard/chart-data', [InventoryDashboardController::class, 'getChartData'])->name('inventory.dashboard.chart');
    Route::get('/dashboard/items/{id}', [InventoryDashboardController::class, 'show'])->name('inventory.dashboard.show');
    
    // =====================================
    // Product Inventory Management
    // =====================================
    Route::get('/products', [ProductInventoryController::class, 'index'])->name('inventory.products.index');
    Route::get('/products/{id}', [ProductInventoryController::class, 'show'])->name('inventory.products.show');
    Route::put('/products/{id}/quantity', [ProductInventoryController::class, 'updateQuantity'])->name('inventory.products.update-quantity');
    Route::post('/products/bulk-update', [ProductInventoryController::class, 'bulkUpdateQuantities'])->name('inventory.products.bulk-update');
    
    // =====================================
    // Stock Movements Tracking
    // =====================================
    Route::get('/movements', [StockMovementController::class, 'index'])->name('inventory.movements.index');
    Route::post('/movements', [StockMovementController::class, 'store'])->name('inventory.movements.store');
    Route::get('/movements/metrics', [StockMovementController::class, 'getMetrics'])->name('inventory.movements.metrics');
    Route::get('/movements/export', [StockMovementController::class, 'exportReport'])->name('inventory.movements.export');
    
    // =====================================
    // Upload/Manage Inventory Items
    // =====================================
    Route::get('/items', [UploadInventoryController::class, 'index'])->name('inventory.items.index');
    Route::post('/items', [UploadInventoryController::class, 'store'])->name('inventory.items.store');
    Route::put('/items/{id}', [UploadInventoryController::class, 'update'])->name('inventory.items.update');
    Route::delete('/items/{id}', [UploadInventoryController::class, 'destroy'])->name('inventory.items.destroy');
    // Add a new colour variant to an existing item (auto-syncs to linked product)
    Route::post('/items/{id}/colors', [UploadInventoryController::class, 'addColor'])->name('inventory.items.colors.store');
    
    // Image Management
    Route::post('/items/images', [UploadInventoryController::class, 'uploadImages'])->name('inventory.items.images.upload');
    Route::delete('/items/images/{id}', [UploadInventoryController::class, 'deleteImage'])->name('inventory.items.images.delete');
    Route::put('/items/images/{id}/thumbnail', [UploadInventoryController::class, 'setThumbnail'])->name('inventory.items.images.thumbnail');
    
    // =====================================
    // Suppliers Management
    // =====================================
    Route::apiResource('suppliers', SupplierController::class)->names([
        'index' => 'inventory.suppliers.index',
        'store' => 'inventory.suppliers.store',
        'show' => 'inventory.suppliers.show',
        'update' => 'inventory.suppliers.update',
        'destroy' => 'inventory.suppliers.destroy',
    ]);
    
    // =====================================
    // Supplier Orders Management
    // =====================================
    Route::get('/supplier-orders', [SupplierOrderController::class, 'index'])->name('inventory.supplier-orders.index');
    Route::post('/supplier-orders', [SupplierOrderController::class, 'store'])->name('inventory.supplier-orders.store');
    Route::get('/supplier-orders/{id}', [SupplierOrderController::class, 'show'])->name('inventory.supplier-orders.show');
    Route::put('/supplier-orders/{id}', [SupplierOrderController::class, 'update'])->name('inventory.supplier-orders.update');
    Route::delete('/supplier-orders/{id}', [SupplierOrderController::class, 'destroy'])->name('inventory.supplier-orders.destroy');
    
    // Supplier Order Actions
    Route::put('/supplier-orders/{id}/status', [SupplierOrderMonitoringController::class, 'updateStatus'])->name('inventory.supplier-orders.status');
    Route::post('/supplier-orders/{id}/receive', [SupplierOrderController::class, 'receiveOrder'])->name('inventory.supplier-orders.receive');
    Route::post('/supplier-orders/generate-po', [SupplierOrderController::class, 'generatePO'])->name('inventory.supplier-orders.generate-po');
    
    // =====================================
    // Supplier Order Monitoring
    // =====================================
    Route::get('/supplier-orders-monitoring', [SupplierOrderMonitoringController::class, 'index'])->name('inventory.monitoring.index');
    Route::get('/supplier-orders-monitoring/metrics', [SupplierOrderMonitoringController::class, 'getMetrics'])->name('inventory.monitoring.metrics');
    Route::get('/supplier-orders-monitoring/{id}', [SupplierOrderMonitoringController::class, 'show'])->name('inventory.monitoring.show');
    
    // =====================================
    // Inventory Alerts (Future Implementation)
    // =====================================
    // Route::get('/alerts', [InventoryAlertController::class, 'index'])->name('inventory.alerts.index');
    // Route::put('/alerts/{id}/resolve', [InventoryAlertController::class, 'resolve'])->name('inventory.alerts.resolve');
});
