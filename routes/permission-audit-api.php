<?php

/**
 * Permission Audit Log Routes
 * 
 * COMPLIANCE CRITICAL - Tracks all role and permission changes
 * Access: Managers and Shop Owners only
 * 
 * Endpoints:
 * - GET  /api/permission-audit-logs          List audit logs with filters
 * - GET  /api/permission-audit-logs/stats    Get statistics
 * - POST /api/permission-audit-logs/compliance-report    Generate compliance report
 * - GET  /api/permission-audit-logs/user/{id}/history   Get user permission history
 * - GET  /api/permission-audit-logs/requires-review     Get high-risk changes
 * - GET  /api/permission-audit-logs/export   Export to CSV
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PermissionAuditLogController;

Route::prefix('api/permission-audit-logs')
    ->middleware(['web', 'auth:user,shop_owner'])
    ->group(function () {
        
        // List audit logs with filters
        Route::get('/', [PermissionAuditLogController::class, 'index'])
            ->name('permission-audit-logs.index');
        
        // Statistics dashboard
        Route::get('/stats', [PermissionAuditLogController::class, 'stats'])
            ->name('permission-audit-logs.stats');
        
        // Compliance report (date range required)
        Route::post('/compliance-report', [PermissionAuditLogController::class, 'complianceReport'])
            ->name('permission-audit-logs.compliance-report');
        
        // User permission history
        Route::get('/user/{userId}/history', [PermissionAuditLogController::class, 'userHistory'])
            ->name('permission-audit-logs.user-history');
        
        // Changes requiring review (high severity)
        Route::get('/requires-review', [PermissionAuditLogController::class, 'requiresReview'])
            ->name('permission-audit-logs.requires-review');
        
        // Export to CSV
        Route::get('/export', [PermissionAuditLogController::class, 'export'])
            ->name('permission-audit-logs.export');
    });
