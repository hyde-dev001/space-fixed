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

/**
 * Shop Owner Routes
 * All routes require authentication and shop_owner role
 */
Route::prefix('api/shop-owner')->middleware(['web', 'auth:shop_owner', 'shop.isolation'])->group(function () {
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
    Route::prefix('price-changes')->group(function () {
        Route::get('/pending', [PriceChangeRequestController::class, 'ownerPending'])->name('shop_owner.price-changes.pending');
        Route::get('/all', [PriceChangeRequestController::class, 'ownerAll'])->name('shop_owner.price-changes.all');
        Route::post('/{id}/approve', [PriceChangeRequestController::class, 'ownerApprove'])->name('shop_owner.price-changes.approve');
        Route::post('/{id}/reject', [PriceChangeRequestController::class, 'ownerReject'])->name('shop_owner.price-changes.reject');
    });

    // ============================================
    // REPAIR SERVICE PRICE APPROVALS (Shop Owner Final Approval)
    // ============================================
    Route::prefix('repair-price-changes')->group(function () {
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
    Route::prefix('products')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ProductController::class, 'myProducts'])->name('shop_owner.products.index');
        Route::post('/', [\App\Http\Controllers\Api\ProductController::class, 'store'])->name('shop_owner.products.store');
        Route::get('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'show'])->name('shop_owner.products.show');
        Route::put('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'update'])->name('shop_owner.products.update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\ProductController::class, 'destroy'])->name('shop_owner.products.destroy');
        Route::post('/upload-image', [\App\Http\Controllers\Api\ProductController::class, 'uploadImage'])->name('shop_owner.products.upload-image');
        Route::get('/{id}/variants', [\App\Http\Controllers\Api\ProductController::class, 'getVariants'])->name('shop_owner.products.variants');
        
        // Color variants
        Route::get('/{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'getColorVariants'])->name('shop_owner.products.color-variants.index');
        Route::post('/{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'storeColorVariant'])->name('shop_owner.products.color-variants.store');
        Route::put('/{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariant'])->name('shop_owner.products.color-variants.update');
        Route::delete('/{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariant'])->name('shop_owner.products.color-variants.destroy');
        
        // Color variant images
        Route::post('/{productId}/color-variants/{colorVariantId}/images', [\App\Http\Controllers\Api\ProductController::class, 'uploadColorVariantImage'])->name('shop_owner.products.color-variants.images.store');
        Route::delete('/{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariantImage'])->name('shop_owner.products.color-variants.images.destroy');
        Route::post('/{productId}/color-variants/{colorVariantId}/images/reorder', [\App\Http\Controllers\Api\ProductController::class, 'reorderColorVariantImages'])->name('shop_owner.products.color-variants.images.reorder');
    });

    // ============================================
    // ORDER MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('orders')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopOwner\OrderController::class, 'index'])->name('shop_owner.orders.index');
        Route::get('/{id}', [\App\Http\Controllers\ShopOwner\OrderController::class, 'show'])->name('shop_owner.orders.show');
        Route::patch('/{id}/status', [\App\Http\Controllers\ShopOwner\OrderController::class, 'updateStatus'])->name('shop_owner.orders.update-status');
    });

    // ============================================
    // REPAIR MANAGEMENT (Shop Owner)
    // ============================================
    Route::prefix('repairs')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'myAssignedRepairs'])->name('shop_owner.repairs.index');
        Route::post('/{id}/accept', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'acceptRepair'])->name('shop_owner.repairs.accept');
        Route::post('/{id}/reject', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectRepair'])->name('shop_owner.repairs.reject');
        Route::post('/{id}/mark-received', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markAsReceived'])->name('shop_owner.repairs.mark-received');
        Route::post('/{id}/start-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'startWork'])->name('shop_owner.repairs.start-work');
        Route::post('/{id}/resume-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'resumeWork'])->name('shop_owner.repairs.resume-work');
        Route::post('/{id}/mark-completed', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markCompleted'])->name('shop_owner.repairs.mark-completed');
        Route::post('/{id}/mark-ready', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markReadyForPickup'])->name('shop_owner.repairs.mark-ready');
        Route::post('/{id}/activate-pickup', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePickup'])->name('shop_owner.repairs.activate-pickup');
        Route::get('/high-value-pending', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'highValuePending'])->name('shop_owner.repairs.high-value-pending');
        Route::post('/{id}/approve-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveHighValueRepair'])->name('shop_owner.repairs.approve-high-value');
        Route::post('/{id}/reject-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectHighValueRepair'])->name('shop_owner.repairs.reject-high-value');
    });

    // ============================================
    // REPAIR SERVICES (Shop Owner)
    // ============================================
    Route::prefix('repair-services')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'index'])->name('shop_owner.repair-services.index');
        Route::post('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'store'])->name('shop_owner.repair-services.store');
        Route::get('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'show'])->name('shop_owner.repair-services.show');
        Route::put('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'update'])->name('shop_owner.repair-services.update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'destroy'])->name('shop_owner.repair-services.destroy');
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
});
