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
    // SUSPENSION FINAL APPROVAL (Shop Owner)
    // ============================================
    Route::prefix('suspension-requests')->group(function () {
        Route::get('/', [SuspensionFinalApprovalController::class, 'index'])->name('shop_owner.suspension_requests.index');
        Route::get('/{id}', [SuspensionFinalApprovalController::class, 'show'])->name('shop_owner.suspension_requests.show');
        Route::post('/{id}/review', [SuspensionFinalApprovalController::class, 'review'])->name('shop_owner.suspension_requests.review');
    });

    // Additional shop owner specific endpoints can be added here
    // e.g., shop settings, business metrics, subscription management, etc.
});
