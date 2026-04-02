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
use App\Http\Controllers\Api\PriceChangeRequestController;
use App\Http\Controllers\Api\RepairServiceController;
use App\Http\Controllers\ShopOwner\SuspensionFinalApprovalController;
use App\Http\Controllers\ShopOwner\PurchaseRequestController as ShopOwnerPurchaseRequestController;
use App\Http\Controllers\ShopOwner\ExpenseController as ShopOwnerExpenseController;
use App\Http\Controllers\ShopOwner\PremiumCheckoutController;
use App\Http\Controllers\Api\RefundApprovalController;

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
        Route::post('/{id}/approve', [ShopOwnerPurchaseRequestController::class, 'approve'])->name('shop_owner.purchase-requests.approve');
        Route::post('/{id}/reject', [ShopOwnerPurchaseRequestController::class, 'reject'])->name('shop_owner.purchase-requests.reject');
    });

    // ============================================
    // EXPENSE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ShopOwnerExpenseController::class, 'index'])->name('shop_owner.expenses.index');
        Route::post('/{id}/approve', [ShopOwnerExpenseController::class, 'approve'])->name('shop_owner.expenses.approve');
        Route::post('/{id}/reject', [ShopOwnerExpenseController::class, 'reject'])->name('shop_owner.expenses.reject');
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
    // REFUND APPROVALS (Shop Owner)
    // ============================================
    Route::prefix('refunds')->group(function () {
        Route::get('/', [RefundApprovalController::class, 'shopOwnerIndex'])->name('shop_owner.refunds.index');
        Route::post('/{id}/approve', [RefundApprovalController::class, 'shopOwnerApprove'])->name('shop_owner.refunds.approve');
        Route::post('/{id}/reject', [RefundApprovalController::class, 'shopOwnerReject'])->name('shop_owner.refunds.reject');
        Route::post('/{id}/execute-gateway-refund', [RefundApprovalController::class, 'shopOwnerExecuteGatewayRefund'])->name('shop_owner.refunds.execute');
    });

    // ============================================
    // AUDIT LOGS (Shop Owner View)
    // ============================================
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('shop_owner.audit.index');
        Route::get('/stats', [AuditLogController::class, 'stats'])->name('shop_owner.audit.stats');
        Route::get('/export', [AuditLogController::class, 'export'])->name('shop_owner.audit.export');
    });

    // ============================================
    // PRICE CHANGE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('price-changes')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/pending', [PriceChangeRequestController::class, 'ownerPending'])->name('shop_owner.price-changes.pending');
        Route::get('/all', [PriceChangeRequestController::class, 'ownerAll'])->name('shop_owner.price-changes.all');
        Route::post('/{id}/approve', [PriceChangeRequestController::class, 'ownerApprove'])->name('shop_owner.price-changes.approve');
        Route::post('/{id}/reject', [PriceChangeRequestController::class, 'ownerReject'])->name('shop_owner.price-changes.reject');
    });

    // ============================================
    // REPAIR SERVICE PRICE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('repair-price-changes')->middleware('check.business.type:repair,both')->group(function () {
        Route::get('/pending', [RepairServiceController::class, 'ownerPending'])->name('shop_owner.repair-price-changes.pending');
        Route::get('/all', [RepairServiceController::class, 'ownerAll'])->name('shop_owner.repair-price-changes.all');
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
    // PRODUCT MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('products')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/meta/showroom-entitlement', [\App\Http\Controllers\Api\ProductController::class, 'showroomEntitlement'])->name('shop_owner.products.showroom-entitlement');
        Route::get('/', [\App\Http\Controllers\Api\ProductController::class, 'myProducts'])
            ->middleware('throttle:120,1')
            ->name('shop_owner.products.index');
        Route::post('/', [\App\Http\Controllers\Api\ProductController::class, 'store'])->name('shop_owner.products.store');
        Route::get('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'show'])->name('shop_owner.products.show');
        Route::put('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'update'])->name('shop_owner.products.update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'destroy'])
            ->middleware('throttle:120,1')
            ->name('shop_owner.products.destroy');
        Route::post('/upload-image', [\App\Http\Controllers\Api\ProductController::class, 'uploadImage'])
            ->middleware('throttle:180,1')
            ->name('shop_owner.products.upload-image');
        Route::get('/{id}/variants', [\App\Http\Controllers\Api\ProductController::class, 'getVariants'])->name('shop_owner.products.variants');
        
        // Color variants
        Route::get('/{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'getColorVariants'])->name('shop_owner.products.color-variants.index');
        Route::post('/{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'storeColorVariant'])->name('shop_owner.products.color-variants.store');
        Route::put('/{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariant'])->name('shop_owner.products.color-variants.update');
        Route::delete('/{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariant'])
            ->middleware('throttle:180,1')
            ->name('shop_owner.products.color-variants.destroy');
        
        // Color variant images
        Route::post('/{productId}/color-variants/{colorVariantId}/images', [\App\Http\Controllers\Api\ProductController::class, 'uploadColorVariantImage'])
            ->middleware('throttle:240,1')
            ->name('shop_owner.products.color-variants.images.store');
        Route::delete('/{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariantImage'])
            ->middleware('throttle:240,1')
            ->name('shop_owner.products.color-variants.images.destroy');
        Route::post('/{productId}/color-variants/{colorVariantId}/images/reorder', [\App\Http\Controllers\Api\ProductController::class, 'reorderColorVariantImages'])
            ->middleware('throttle:240,1')
            ->name('shop_owner.products.color-variants.images.reorder');
    });

    // ============================================
    // INVENTORY MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('inventory')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/overview', [\App\Http\Controllers\Api\StaffInventoryController::class, 'index'])->name('shop_owner.inventory.overview');
    });

    // ============================================
    // ORDER MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('orders')->middleware('check.business.type:retail,both')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopOwner\OrderController::class, 'index'])->name('shop_owner.orders.index');
        Route::get('/{id}', [\App\Http\Controllers\ShopOwner\OrderController::class, 'show'])->name('shop_owner.orders.show');
        Route::patch('/{id}/status', [\App\Http\Controllers\ShopOwner\OrderController::class, 'updateStatus'])->name('shop_owner.orders.update-status');
        Route::post('/{id}/activate-pickup', [\App\Http\Controllers\ShopOwner\OrderController::class, 'activatePickup'])->name('shop_owner.orders.activate-pickup');
        Route::post('/{id}/arrange-return-pickup', [\App\Http\Controllers\ShopOwner\OrderController::class, 'arrangeReturnPickup'])->name('shop_owner.orders.arrange-return-pickup');
        Route::post('/{id}/confirm-return-received', [\App\Http\Controllers\ShopOwner\OrderController::class, 'confirmReturnReceived'])->name('shop_owner.orders.confirm-return-received');
    });

    // ============================================
    // REPAIR MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('repairs')->middleware('check.business.type:repair,both')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'myAssignedRepairs'])->name('shop_owner.repairs.index');
        Route::post('/{id}/accept', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'acceptRepair'])->name('shop_owner.repairs.accept');
        Route::post('/{id}/reject', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectRepair'])->name('shop_owner.repairs.reject');
        Route::post('/{id}/mark-received', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markAsReceived'])->name('shop_owner.repairs.mark-received');
        Route::patch('/{id}/delivery-method', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'changeDeliveryMethod'])->name('shop_owner.repairs.delivery-method');
        Route::post('/{id}/start-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'startWork'])->name('shop_owner.repairs.start-work');
        Route::post('/{id}/resume-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'resumeWork'])->name('shop_owner.repairs.resume-work');
        Route::post('/{id}/mark-completed', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markCompleted'])->name('shop_owner.repairs.mark-completed');
        Route::post('/{id}/mark-ready', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markReadyForPickup'])->name('shop_owner.repairs.mark-ready');
        Route::post('/{id}/activate-pickup', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePickup'])->name('shop_owner.repairs.activate-pickup');
        Route::post('/{id}/activate-payment', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePaymentForRepair'])->name('shop_owner.repairs.activate-payment');
        Route::post('/{id}/mark-paid-in-shop', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markPaidInShop'])->name('shop_owner.repairs.mark-paid-in-shop');
        Route::get('/high-value-pending', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getHighValuePendingApprovals'])->name('shop_owner.repairs.high-value-pending');
        Route::post('/{id}/approve-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveHighValueRepair'])->name('shop_owner.repairs.approve-high-value');
        Route::post('/{id}/reject-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectHighValueRepair'])->name('shop_owner.repairs.reject-high-value');
        Route::post('/{id}/ship', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'shipRepair'])->name('shop_owner.repairs.ship');
    });

    // ============================================
    // REPAIR SERVICES (Shop Owner)
    // ============================================
    Route::prefix('repair-services')->middleware('check.business.type:repair,both')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'index'])->name('shop_owner.repair-services.index');
        Route::post('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'store'])->name('shop_owner.repair-services.store');
        Route::get('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'show'])->name('shop_owner.repair-services.show');
        Route::put('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'update'])->name('shop_owner.repair-services.update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'destroy'])->name('shop_owner.repair-services.destroy');
    });

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
    Route::prefix('conversations')->group(function () {
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
