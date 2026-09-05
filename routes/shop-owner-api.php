<?php

/**
 * Shop Owner API Routes
 * 
 * Purpose: Shop owner specific management endpoints
 * Middleware: web, auth:shop_owner (session-based), shop isolation
 * Protected by: shop_owner guard + shop isolation
 * 
 * Endpoints:
 * - Shop settings
 * - Business analytics
 * - Cross-module access (limited)
 * - Shop profile management
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ActivityLogController as CanonicalActivityLogController;
use App\Http\Controllers\Api\PriceChangeRequestController;
use App\Http\Controllers\Api\RepairServiceController;
use App\Http\Controllers\ShopOwner\SuspensionFinalApprovalController;
use App\Http\Controllers\ShopOwner\EmployeeLifecycleFinalApprovalController;
use App\Http\Controllers\ShopOwner\PurchaseRequestController as ShopOwnerPurchaseRequestController;
use App\Http\Controllers\ShopOwner\ExpenseController as ShopOwnerExpenseController;
use App\Http\Controllers\ShopOwner\PremiumCheckoutController;
use App\Http\Controllers\ShopOwner\PromoCampaignController;
use App\Http\Controllers\Api\RefundApprovalController;
use App\Http\Controllers\Erp\UploadInventoryController;
use App\Http\Controllers\Api\Finance\ExpenseController as FinanceExpenseController;
use App\Http\Controllers\Api\Finance\InvoiceController as FinanceInvoiceController;
use App\Http\Controllers\Api\Finance\FinanceSummaryController;
use App\Http\Controllers\Api\Finance\TaxRateController as FinanceTaxRateController;
use App\Http\Controllers\Erp\HR\AttendanceController as HrAttendanceController;
use App\Http\Controllers\Erp\HR\PayrollBatchController as HrPayrollBatchController;
use App\Http\Controllers\Erp\HR\PayrollController as HrPayrollController;

/**
 * Shop Owner Routes
 * All routes require authentication and shop_owner role
 */
Route::prefix('api/shop-owner')->middleware(['web', 'auth:shop_owner', 'shop.isolation'])->group(function () {
    // ============================================
    // CUSTOMERS (Shop Owner)
    // ============================================
    Route::prefix('customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopOwner\CustomerController::class, 'index'])->name('shop_owner.customers.index');
        Route::get('/{id}/orders', [\App\Http\Controllers\ShopOwner\CustomerController::class, 'orders'])->name('shop_owner.customers.orders');
        Route::get('/{id}/repairs', [\App\Http\Controllers\ShopOwner\CustomerController::class, 'repairs'])->name('shop_owner.customers.repairs');
        Route::get('/{id}/payments', [\App\Http\Controllers\ShopOwner\CustomerController::class, 'payments'])->name('shop_owner.customers.payments');
    });

    // ============================================
    // PURCHASE REQUEST APPROVAL (Shop Owner Final Approval)
    // ============================================
    Route::prefix('purchase-requests')->group(function () {
        Route::get('/', [ShopOwnerPurchaseRequestController::class, 'index'])->name('shop_owner.purchase-requests.index');
        Route::get('/{id}', [ShopOwnerPurchaseRequestController::class, 'show'])->name('shop_owner.purchase-requests.show');
        Route::post('/{id}/approve', [ShopOwnerPurchaseRequestController::class, 'approve'])->name('shop_owner.purchase-requests.approve');
        Route::post('/{id}/reject', [ShopOwnerPurchaseRequestController::class, 'reject'])->name('shop_owner.purchase-requests.reject');
    });

    // ============================================
    // EXPENSE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ShopOwnerExpenseController::class, 'index'])->name('shop_owner.expenses.index');
        Route::get('/{id}', [ShopOwnerExpenseController::class, 'show'])->name('shop_owner.expenses.show');
        Route::post('/{id}/approve', [ShopOwnerExpenseController::class, 'approve'])->name('shop_owner.expenses.approve');
        Route::post('/{id}/reject', [ShopOwnerExpenseController::class, 'reject'])->name('shop_owner.expenses.reject');
    });

    // ============================================
    // FINANCE OPERATIONS (ERP owner mode)
    // ============================================
    Route::prefix('finance')->group(function () {
        Route::get('/dashboard', FinanceSummaryController::class)
            ->name('shop_owner.finance.dashboard.summary');

        Route::prefix('invoices')->middleware(['erp.audience', 'erp.actor'])->group(function () {
            Route::get('/', [FinanceInvoiceController::class, 'index'])->name('shop_owner.finance.invoices.index');
            Route::get('/{id}', [FinanceInvoiceController::class, 'show'])->name('shop_owner.finance.invoices.show');
            Route::post('/', [FinanceInvoiceController::class, 'store'])->name('shop_owner.finance.invoices.store');
            Route::post('/from-job', [FinanceInvoiceController::class, 'createFromJob'])->name('shop_owner.finance.invoices.from_job');
            Route::patch('/{id}', [FinanceInvoiceController::class, 'update'])->name('shop_owner.finance.invoices.update');
            Route::delete('/{id}', [FinanceInvoiceController::class, 'destroy'])->name('shop_owner.finance.invoices.destroy');
            Route::post('/{id}/restore', [FinanceInvoiceController::class, 'restore'])->name('shop_owner.finance.invoices.restore');
            Route::post('/{id}/send', [FinanceInvoiceController::class, 'send'])->name('shop_owner.finance.invoices.send');
            Route::post('/{id}/void', [FinanceInvoiceController::class, 'void'])->name('shop_owner.finance.invoices.void');
            Route::post('/{id}/mark-paid', [FinanceInvoiceController::class, 'markAsPaid'])->name('shop_owner.finance.invoices.mark_paid');
            Route::post('/{id}/post', [FinanceInvoiceController::class, 'post'])->name('shop_owner.finance.invoices.post');
        });

        Route::prefix('expenses')->group(function () {
            Route::get('/', [FinanceExpenseController::class, 'index'])->name('shop_owner.finance.expenses.index');
            Route::get('/{id}', [FinanceExpenseController::class, 'show'])->name('shop_owner.finance.expenses.show');
            Route::post('/', [FinanceExpenseController::class, 'store'])->name('shop_owner.finance.expenses.store');
            Route::patch('/{id}', [FinanceExpenseController::class, 'update'])->name('shop_owner.finance.expenses.update');
            Route::delete('/{id}', [FinanceExpenseController::class, 'destroy'])->name('shop_owner.finance.expenses.destroy');
            Route::post('/{id}/restore', [FinanceExpenseController::class, 'restore'])->name('shop_owner.finance.expenses.restore');
            Route::post('/{id}/approve', [ShopOwnerExpenseController::class, 'approve'])->name('shop_owner.finance.expenses.approve');
            Route::post('/{id}/reject', [ShopOwnerExpenseController::class, 'reject'])->name('shop_owner.finance.expenses.reject');
            Route::post('/{id}/receipt', [FinanceExpenseController::class, 'uploadReceipt'])->name('shop_owner.finance.expenses.receipt.upload');
            Route::get('/{id}/receipt/download', [FinanceExpenseController::class, 'downloadReceipt'])->name('shop_owner.finance.expenses.receipt.download');
            Route::delete('/{id}/receipt', [FinanceExpenseController::class, 'deleteReceipt'])->name('shop_owner.finance.expenses.receipt.delete');
        });

        Route::get('/tax-rates', [FinanceTaxRateController::class, 'index'])->name('shop_owner.finance.tax-rates.index');
    });

    // ============================================
    // SALARY CHANGE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('salary-changes')->middleware('check.registration.type:company')->group(function () {
        Route::get('/', [\App\Http\Controllers\Erp\HR\SalaryChangeController::class, 'index'])->name('shop_owner.salary-changes.index');
        Route::get('/{id}', [\App\Http\Controllers\Erp\HR\SalaryChangeController::class, 'show'])->name('shop_owner.salary-changes.show');
        Route::post('/{id}/approve', [\App\Http\Controllers\Erp\HR\SalaryChangeController::class, 'approve'])->name('shop_owner.salary-changes.approve');
        Route::post('/{id}/reject', [\App\Http\Controllers\Erp\HR\SalaryChangeController::class, 'reject'])->name('shop_owner.salary-changes.reject');
    });

    // ============================================
    // HR PAYROLL OPERATIONS (ERP owner mode)
    // ============================================
    Route::prefix('hr')->group(function () {
        Route::get('/attendance/employee/{employeeId}', [HrAttendanceController::class, 'getByEmployee'])
            ->name('shop_owner.hr.attendance.by_employee');

        Route::prefix('payroll')->group(function () {
            Route::get('/periods', [HrPayrollController::class, 'payrollPeriods'])
                ->name('shop_owner.hr.payroll.periods');
            Route::get('/', [HrPayrollController::class, 'index'])
                ->name('shop_owner.hr.payroll.index');
            Route::post('/', [HrPayrollController::class, 'store'])
                ->name('shop_owner.hr.payroll.store');
            Route::post('/calculate-preview', [HrPayrollController::class, 'calculatePreview'])
                ->name('shop_owner.hr.payroll.calculate_preview');
            Route::get('/{id}', [HrPayrollController::class, 'show'])
                ->name('shop_owner.hr.payroll.show');

            Route::prefix('batch')->group(function () {
                Route::post('/preview', [HrPayrollBatchController::class, 'previewBatch'])
                    ->name('shop_owner.hr.payroll.batch.preview');
                Route::post('/generate', [HrPayrollBatchController::class, 'generateBatch'])
                    ->name('shop_owner.hr.payroll.batch.generate');
                Route::post('/retry', [HrPayrollBatchController::class, 'retryBatch'])
                    ->name('shop_owner.hr.payroll.batch.retry');
                Route::post('/export', [HrPayrollBatchController::class, 'exportBatch'])
                    ->name('shop_owner.hr.payroll.batch.export');
            });
        });
    });

    // ============================================
    // REFUND APPROVALS (Shop Owner)
    // ============================================
    Route::prefix('refunds')->group(function () {
        Route::get('/', [RefundApprovalController::class, 'shopOwnerIndex'])->name('shop_owner.refunds.index');
        Route::get('/{id}', [RefundApprovalController::class, 'shopOwnerShow'])->name('shop_owner.refunds.show');
        Route::post('/{id}/approve', [RefundApprovalController::class, 'shopOwnerApprove'])->name('shop_owner.refunds.approve');
        Route::post('/{id}/reject', [RefundApprovalController::class, 'shopOwnerReject'])->name('shop_owner.refunds.reject');
        Route::post('/{id}/execute-gateway-refund', [RefundApprovalController::class, 'shopOwnerExecuteGatewayRefund'])->name('shop_owner.refunds.execute');
    });

    Route::prefix('repair-refunds')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'ownerIndex'])
            ->name('shop_owner.repair-refunds.index');
        Route::get('/{refund}', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'ownerShow'])
            ->name('shop_owner.repair-refunds.show');
        Route::post('/{refund}/approve', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'ownerApprove'])
            ->name('shop_owner.repair-refunds.approve');
        Route::post('/{refund}/reject', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'ownerReject'])
            ->name('shop_owner.repair-refunds.reject');
        Route::post('/{refund}/execute', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'ownerExecute'])
            ->name('shop_owner.repair-refunds.execute');
    });

    // ============================================
    // AUDIT LOGS (Shop Owner View)
    // ============================================
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [CanonicalActivityLogController::class, 'index'])
            ->middleware(['erp.audience', 'erp.actor'])
            ->name('shop_owner.audit.index');
        Route::get('/stats', [CanonicalActivityLogController::class, 'index'])
            ->middleware(['erp.audience', 'erp.actor'])
            ->name('shop_owner.audit.stats');
        Route::get('/export', [AuditLogController::class, 'export'])
            ->middleware(['erp.audience', 'erp.actor'])
            ->name('shop_owner.audit.export');
    });

    // ============================================
    // PRICE CHANGE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('price-changes')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/pending', [PriceChangeRequestController::class, 'ownerPending'])->name('shop_owner.price-changes.pending');
        Route::get('/all', [PriceChangeRequestController::class, 'ownerAll'])->name('shop_owner.price-changes.all');
        Route::get('/{id}', [PriceChangeRequestController::class, 'ownerShow'])->name('shop_owner.price-changes.show');
        Route::post('/{id}/approve', [PriceChangeRequestController::class, 'ownerApprove'])->name('shop_owner.price-changes.approve');
        Route::post('/{id}/reject', [PriceChangeRequestController::class, 'ownerReject'])->name('shop_owner.price-changes.reject');
    });

    // ============================================
    // REPAIR SERVICE PRICE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('repair-price-changes')->middleware('check.business.type:repair,both')->group(function () {
        Route::get('/pending', [RepairServiceController::class, 'ownerPending'])->name('shop_owner.repair-price-changes.pending');
        Route::get('/all', [RepairServiceController::class, 'ownerAll'])->name('shop_owner.repair-price-changes.all');
        Route::get('/{id}', [RepairServiceController::class, 'ownerShow'])->name('shop_owner.repair-price-changes.show');
        Route::post('/{id}/approve', [RepairServiceController::class, 'ownerApprove'])->name('shop_owner.repair-price-changes.approve');
        Route::post('/{id}/reject', [RepairServiceController::class, 'ownerReject'])->name('shop_owner.repair-price-changes.reject');
    });

    // ============================================
    // SUSPENSION FINAL APPROVAL (Shop Owner)
    // ============================================
    Route::prefix('suspension-requests')->group(function () {
        Route::get('/', [SuspensionFinalApprovalController::class, 'index'])->name('shop_owner.suspension_requests.index');
        Route::get('/{id}', [SuspensionFinalApprovalController::class, 'show'])->name('shop_owner.suspension_requests.show');
        Route::post('/{id}/review', [SuspensionFinalApprovalController::class, 'review'])->name('shop_owner.suspension_requests.review');
    });

    // ============================================
    // EMPLOYEE LIFECYCLE FINAL APPROVAL (Company Shop Owner)
    // ============================================
    Route::prefix('termination-requests')->group(function () {
        Route::get('/', [EmployeeLifecycleFinalApprovalController::class, 'indexTermination'])->name('shop_owner.termination_requests.index');
        Route::get('/{id}', [EmployeeLifecycleFinalApprovalController::class, 'showTermination'])->whereNumber('id')->name('shop_owner.termination_requests.show');
        Route::post('/{id}/review', [EmployeeLifecycleFinalApprovalController::class, 'reviewTermination'])->whereNumber('id')->name('shop_owner.termination_requests.review');
    });

    Route::prefix('rehire-requests')->group(function () {
        Route::get('/', [EmployeeLifecycleFinalApprovalController::class, 'indexRehire'])->name('shop_owner.rehire_requests.index');
        Route::get('/{id}', [EmployeeLifecycleFinalApprovalController::class, 'showRehire'])->whereNumber('id')->name('shop_owner.rehire_requests.show');
        Route::post('/{id}/review', [EmployeeLifecycleFinalApprovalController::class, 'reviewRehire'])->whereNumber('id')->name('shop_owner.rehire_requests.review');
    });

    // ============================================
    // NOTIFICATIONS (Shop Owner)
    // ============================================
    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'index'])->name('shop_owner.notifications.index');
        Route::get('/unread-count', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'unreadCount'])->name('shop_owner.notifications.unread-count');
        Route::get('/recent', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'recent'])->name('shop_owner.notifications.recent');
        Route::get('/stats', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'stats'])->name('shop_owner.notifications.stats');
        Route::get('/preferences', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'getPreferences'])->name('shop_owner.notifications.preferences');
        Route::put('/preferences', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'updatePreferences'])->name('shop_owner.notifications.update-preferences');
        Route::post('/{id}/read', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'markAsRead'])->name('shop_owner.notifications.mark-read');
        Route::post('/mark-all-read', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'markAllAsRead'])->name('shop_owner.notifications.mark-all-read');
        Route::delete('/{id}', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'destroy'])->name('shop_owner.notifications.destroy');
        Route::post('/{id}/unarchive', [\App\Http\Controllers\ShopOwnerNotificationController::class, 'unarchive'])->name('shop_owner.notifications.unarchive');
    });

    // Additional shop owner specific endpoints can be added here
    // e.g., shop settings, business metrics, subscription management, etc.

    // ============================================
    // PROMO CAMPAIGNS (Shop Owner Retail)
    // ============================================
    Route::prefix('promos')->middleware(['check.business.type:retail,both', 'erp.audience', 'erp.actor'])->group(function () {
        Route::get('/', [PromoCampaignController::class, 'index'])->name('shop_owner.promos.index');
        Route::post('/', [PromoCampaignController::class, 'store'])->name('shop_owner.promos.store');
        Route::put('/{id}', [PromoCampaignController::class, 'update'])->name('shop_owner.promos.update');
        Route::patch('/{id}/status', [PromoCampaignController::class, 'updateStatus'])->name('shop_owner.promos.update-status');
        Route::delete('/{id}', [PromoCampaignController::class, 'destroy'])->name('shop_owner.promos.destroy');
        Route::get('/products', [PromoCampaignController::class, 'products'])->name('shop_owner.promos.products');
    });

    // ============================================
    // PRODUCT MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('products')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/meta/showroom-entitlement', [\App\Http\Controllers\Api\ProductController::class, 'showroomEntitlement'])->name('shop_owner.products.showroom-entitlement');
        Route::get('/', [\App\Http\Controllers\Api\ProductController::class, 'myProducts'])
            ->middleware('throttle:120,1')
            ->name('shop_owner.products.index');
        Route::post('/', [\App\Http\Controllers\Api\ProductController::class, 'store'])
            ->middleware('owner.product.write')
            ->name('shop_owner.products.store');
        Route::get('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'show'])->name('shop_owner.products.show');
        Route::put('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'update'])
            ->middleware('owner.product.write')
            ->name('shop_owner.products.update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'destroy'])
            ->middleware(['throttle:120,1', 'owner.product.write'])
            ->name('shop_owner.products.destroy');
        Route::post('/{id}/restore', [\App\Http\Controllers\Api\ProductController::class, 'restore'])
            ->middleware(['throttle:120,1', 'owner.product.write'])
            ->name('shop_owner.products.restore');
        Route::post('/upload-image', [\App\Http\Controllers\Api\ProductController::class, 'uploadImage'])
            ->middleware(['throttle:180,1', 'owner.product.write'])
            ->name('shop_owner.products.upload-image');
        Route::get('/{id}/variants', [\App\Http\Controllers\Api\ProductController::class, 'getVariants'])->name('shop_owner.products.variants');
        
        // Color variants
        Route::get('/{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'getColorVariants'])->name('shop_owner.products.color-variants.index');
        Route::post('/{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'storeColorVariant'])
            ->middleware('owner.product.write')
            ->name('shop_owner.products.color-variants.store');
        Route::put('/{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariant'])
            ->middleware('owner.product.write')
            ->name('shop_owner.products.color-variants.update');
        Route::delete('/{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariant'])
            ->middleware(['throttle:180,1', 'owner.product.write'])
            ->name('shop_owner.products.color-variants.destroy');
        
        // Color variant images
        Route::post('/{productId}/color-variants/{colorVariantId}/images', [\App\Http\Controllers\Api\ProductController::class, 'uploadColorVariantImage'])
            ->middleware(['throttle:240,1', 'owner.product.write'])
            ->name('shop_owner.products.color-variants.images.store');
        Route::put('/{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariantImage'])
            ->middleware(['throttle:240,1', 'owner.product.write'])
            ->name('shop_owner.products.color-variants.images.update');
        Route::delete('/{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariantImage'])
            ->middleware(['throttle:240,1', 'owner.product.write'])
            ->name('shop_owner.products.color-variants.images.destroy');
        Route::post('/{productId}/color-variants/{colorVariantId}/images/reorder', [\App\Http\Controllers\Api\ProductController::class, 'reorderColorVariantImages'])
            ->middleware(['throttle:240,1', 'owner.product.write'])
            ->name('shop_owner.products.color-variants.images.reorder');
    });

    // ============================================
    // INVENTORY MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('inventory')->group(function () {
        Route::middleware('check.business.type:retail,both')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\StaffInventoryController::class, 'index'])->name('shop_owner.inventory.overview');
        });

        Route::middleware(['check.business.type:repair,both', 'check.registration.type:individual'])->group(function () {
            Route::get('/items', [UploadInventoryController::class, 'index'])->name('shop_owner.inventory.items.index');
            Route::post('/items', [UploadInventoryController::class, 'store'])->name('shop_owner.inventory.items.store');
            Route::put('/items/{id}', [UploadInventoryController::class, 'update'])->name('shop_owner.inventory.items.update');
            Route::delete('/items/{id}', [UploadInventoryController::class, 'destroy'])->name('shop_owner.inventory.items.destroy');
            Route::post('/items/{id}/restore', [UploadInventoryController::class, 'restore'])->name('shop_owner.inventory.items.restore');

            Route::post('/items/images', [UploadInventoryController::class, 'uploadImages'])->name('shop_owner.inventory.items.images.upload');
            Route::delete('/items/images/{id}', [UploadInventoryController::class, 'deleteImage'])->name('shop_owner.inventory.items.images.delete');
            Route::put('/items/images/{id}/thumbnail', [UploadInventoryController::class, 'setThumbnail'])->name('shop_owner.inventory.items.images.thumbnail');
        });
    });

    // ============================================
    // ORDER MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('orders')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopOwner\OrderController::class, 'index'])->name('shop_owner.orders.index');
        Route::get('/{id}', [\App\Http\Controllers\ShopOwner\OrderController::class, 'show'])->name('shop_owner.orders.show');
        Route::patch('/{id}/status', [\App\Http\Controllers\ShopOwner\OrderController::class, 'updateStatus'])->name('shop_owner.orders.update-status');
        Route::post('/{id}/correct-terminal-outcome', [\App\Http\Controllers\ShopOwner\OrderController::class, 'correctTerminalOutcome'])->name('shop_owner.orders.correct-terminal-outcome');
        Route::post('/{id}/activate-pickup', [\App\Http\Controllers\ShopOwner\OrderController::class, 'activatePickup'])->name('shop_owner.orders.activate-pickup');
        Route::post('/{id}/arrange-return-pickup', [\App\Http\Controllers\ShopOwner\OrderController::class, 'arrangeReturnPickup'])->name('shop_owner.orders.arrange-return-pickup');
        Route::post('/{id}/confirm-return-received', [\App\Http\Controllers\ShopOwner\OrderController::class, 'confirmReturnReceived'])->name('shop_owner.orders.confirm-return-received');
    });

    // ============================================
    // REPAIR MANAGEMENT (Shop Owner)
    // ============================================
    // Company owners may retain the existing read projection for compatibility,
    // but only individual owners may operate the repair workflow. Company
    // monitoring uses the normalized projection under /erp/operations.
    Route::prefix('repairs')->middleware('check.business.type:repair,both')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'myAssignedRepairs'])->name('shop_owner.repairs.index');

        Route::middleware('check.registration.type:individual')->group(function () {
            Route::post('/{id}/accept', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'acceptRepair'])->name('shop_owner.repairs.accept');
            Route::post('/{id}/reject', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectRepair'])->name('shop_owner.repairs.reject');
            Route::post('/{id}/mark-received', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markAsReceived'])->name('shop_owner.repairs.mark-received');
            Route::patch('/{id}/delivery-method', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'changeDeliveryMethod'])->name('shop_owner.repairs.delivery-method');
            Route::post('/{id}/start-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'startWork'])->name('shop_owner.repairs.start-work');
            Route::post('/{id}/resume-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'resumeWork'])->name('shop_owner.repairs.resume-work');
            Route::post('/{id}/mark-completed', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markCompleted'])->name('shop_owner.repairs.mark-completed');
            Route::get('/{id}/materials', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getRepairMaterialUsage'])->name('shop_owner.repairs.materials.index');
            Route::post('/{id}/materials', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'logRepairMaterialUsage'])->name('shop_owner.repairs.materials.store');
            Route::delete('/{id}/materials/{usageId}', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'removeRepairMaterialUsage'])->name('shop_owner.repairs.materials.destroy');
            Route::post('/{id}/mark-ready', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markReadyForPickup'])->name('shop_owner.repairs.mark-ready');
            Route::post('/{id}/activate-pickup', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePickup'])->name('shop_owner.repairs.activate-pickup');
            Route::post('/{id}/activate-payment', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePaymentForRepair'])->name('shop_owner.repairs.activate-payment');
            Route::post('/{id}/mark-paid-in-shop', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markPaidInShop'])->name('shop_owner.repairs.mark-paid-in-shop');
            Route::post('/{id}/ship', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'shipRepair'])->name('shop_owner.repairs.ship');
        });

        Route::get('/high-value-pending', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getHighValuePendingApprovals'])->name('shop_owner.repairs.high-value-pending');
        Route::post('/{id}/approve-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveHighValueRepair'])->name('shop_owner.repairs.approve-high-value');
        Route::post('/{id}/reject-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectHighValueRepair'])->name('shop_owner.repairs.reject-high-value');
    });

    // ============================================
    // REPAIR SERVICES (Shop Owner)
    // ============================================
    Route::prefix('repair-services')->middleware(['check.business.type:repair,both', 'erp.audience', 'erp.actor'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'index'])->name('shop_owner.repair-services.index');
        Route::post('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'store'])->name('shop_owner.repair-services.store');
        Route::get('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'show'])->name('shop_owner.repair-services.show');
        Route::put('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'update'])->name('shop_owner.repair-services.update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'destroy'])->name('shop_owner.repair-services.destroy');
        Route::post('/{id}/restore', [\App\Http\Controllers\Api\RepairServiceController::class, 'restore'])->name('shop_owner.repair-services.restore');
    });

    Route::get('/repair-materials', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'repairStocksOverview'])
        ->middleware(['check.business.type:repair,both', 'erp.audience', 'erp.actor'])
        ->name('shop_owner.repair-materials.index');

    // ============================================
    // CUSTOMER REVIEWS (Shop Owner)
    // ============================================
    Route::prefix('reviews')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopOwner\CustomerReviewController::class, 'index'])->name('shop_owner.reviews.index');
        // Static route MUST come before the dynamic {id} route to avoid collision
        Route::post('/report', [\App\Http\Controllers\ShopOwner\CustomerReviewController::class, 'reportReview'])->name('shop_owner.reviews.report');
    });

    // ============================================
    // REPAIR CONVERSATIONS (Shop Owner)
    // ============================================
    Route::prefix('conversations')->middleware(['erp.audience', 'erp.actor'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ConversationController::class, 'indexShopOwner'])->name('shop_owner.conversations.index');
        Route::get('/{id}', [\App\Http\Controllers\Api\ConversationController::class, 'showShopOwner'])->name('shop_owner.conversations.show');
        Route::post('/{id}/messages', [\App\Http\Controllers\Api\ConversationController::class, 'storeMessageShopOwner'])->name('shop_owner.conversations.messages.store');
        Route::post('/{id}/transfer', [\App\Http\Controllers\Api\ConversationController::class, 'transferShopOwner'])->name('shop_owner.conversations.transfer');
        Route::post('/{id}/activate-payment', [\App\Http\Controllers\Api\ConversationController::class, 'activatePaymentShopOwner'])->name('shop_owner.conversations.activate-payment');
    });

    // ============================================
    // USER ACCESS CONTROL / EMPLOYEE MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('employees')->group(function () {
        Route::post('/{userId}/regenerate-invite', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'regenerateInvite'])->name('shop_owner.employees.regenerate_invite');
        Route::post('/{userId}/send-invitation-email', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'sendInvitationEmail'])->name('shop_owner.employees.send_invitation_email');
    });

    // ============================================
    // PREMIUM SUBSCRIPTION (retail + both shops only)
    // ============================================
    Route::prefix('premium')->middleware('check.business.type:retail,both')->group(function () {
        // List available plans from DB
        Route::get('/plans', [PremiumCheckoutController::class, 'plans'])->name('shop_owner.premium.plans');
        // Current shop's subscription status
        Route::get('/subscription', [PremiumCheckoutController::class, 'currentSubscription'])->name('shop_owner.premium.subscription');
        // Preview proration and final charge for upgrade
        Route::post('/upgrade', [PremiumCheckoutController::class, 'upgrade'])->name('shop_owner.premium.upgrade.preview');
        // Confirm and process upgrade payment
        Route::post('/confirm-upgrade', [PremiumCheckoutController::class, 'confirmUpgrade'])->name('shop_owner.premium.upgrade.confirm');
        // Schedule downgrade at cycle end
        Route::post('/schedule-downgrade', [PremiumCheckoutController::class, 'scheduleDowngrade'])->name('shop_owner.premium.downgrade.schedule');
        // Toggle auto-renew for the active premium subscription
        Route::patch('/auto-renew', [PremiumCheckoutController::class, 'toggleAutoRenewal'])->name('shop_owner.premium.auto-renew');
        // Initiate PayMongo checkout for a premium plan
        Route::post('/checkout', [PremiumCheckoutController::class, 'checkout'])->name('shop_owner.premium.checkout');
        // Cancel pending or active subscription (used for manual stop)
        Route::post('/cancel', [PremiumCheckoutController::class, 'cancel'])->name('shop_owner.premium.cancel');
    });
});
