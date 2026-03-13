<?php

/**
 * Finance Module API Routes
 * 
 * Purpose: All finance-related API endpoints
 * Middleware: web, auth:user (session-based), role-based access control
 * Protected by: FINANCE_STAFF, FINANCE_MANAGER roles + shop isolation
 * 
 * Endpoints:
 * - Invoices
 * - Expenses
 * - Audit logs (Finance module)
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Finance\InvoiceController;
use App\Http\Controllers\Api\Finance\ExpenseController;
use App\Http\Controllers\Api\Finance\PayslipApprovalController as FinancePayslipApprovalController;
use App\Http\Controllers\ERP\HR\AuditLogController;
use App\Http\Controllers\Api\PriceChangeRequestController;
use App\Http\Controllers\Api\RepairServiceController;

/**
 * Finance Module Routes - Audit Logs (requires view-finance-audit-logs permission)
 * Restricted to users with audit log permissions for security/compliance
 */
Route::prefix('api/finance')->middleware(['web', 'auth:user', 'permission:access-audit-logs', 'shop.isolation'])->group(function () {
    // ============================================
    // AUDIT LOGS (permission: view-finance-audit-logs)
    // ============================================
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('finance.audit.index');
        Route::get('/statistics', [AuditLogController::class, 'statistics'])->name('finance.audit.statistics');
        Route::get('/export', [AuditLogController::class, 'export'])->name('finance.audit.export');
        Route::get('/{id}', [AuditLogController::class, 'show'])->name('finance.audit.show');
    });
});

/**
 * Finance Module Routes - General Operations
 * Accessible by users with any Finance permissions (including pricing approvals)
 */
Route::prefix('api/finance')->middleware(['web', 'auth:user', 'permission:access-finance-dashboard|access-finance-expenses|access-finance-invoices|access-repair-price-approval|access-shoe-price-approval', 'shop.isolation'])->group(function () {

    // ============================================
    // EXPENSES
    // ============================================
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('finance.expenses.index');
        Route::get('/{id}', [ExpenseController::class, 'show'])->name('finance.expenses.show');
        Route::post('/', [ExpenseController::class, 'store'])->name('finance.expenses.store');
        Route::patch('/{id}', [ExpenseController::class, 'update'])->name('finance.expenses.update');
        Route::delete('/{id}', [ExpenseController::class, 'destroy'])->name('finance.expenses.destroy');
        
        // Approval actions (requires approve-expenses permission)
        Route::middleware('permission:approve-expenses')->group(function () {
            Route::post('/{id}/approve', [ExpenseController::class, 'approve'])->name('finance.expenses.approve');
            Route::post('/{id}/reject', [ExpenseController::class, 'reject'])->name('finance.expenses.reject');
            Route::post('/{id}/post', [ExpenseController::class, 'post'])->name('finance.expenses.post');
        });
    });


    // ============================================
    // INVOICES
    // ============================================
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('finance.invoices.index');
        Route::get('/{id}', [InvoiceController::class, 'show'])->name('finance.invoices.show');
        Route::post('/', [InvoiceController::class, 'store'])->name('finance.invoices.store');
        Route::post('/from-job', [InvoiceController::class, 'createFromJob'])->name('finance.invoices.from_job');
        Route::patch('/{id}', [InvoiceController::class, 'update'])->name('finance.invoices.update');
        Route::delete('/{id}', [InvoiceController::class, 'destroy'])->name('finance.invoices.destroy');
        Route::post('/{id}/send', [InvoiceController::class, 'send'])->name('finance.invoices.send');
        Route::post('/{id}/void', [InvoiceController::class, 'void'])->name('finance.invoices.void');
        Route::post('/{id}/mark-paid', [InvoiceController::class, 'markAsPaid'])->name('finance.invoices.mark_paid');
        
        // Post to ledger (requires access-finance-invoices permission)
        Route::middleware('permission:access-finance-invoices')->post('/{id}/post', [InvoiceController::class, 'post'])->name('finance.invoices.post');
    });

    // ============================================
    // PRICE CHANGE REQUESTS
    // ============================================
    Route::prefix('price-changes')->group(function () {
        // View all price change requests
        Route::get('/', [PriceChangeRequestController::class, 'index'])->name('finance.price-changes.index');
        
        // Finance approval actions
        Route::post('/{id}/approve', [PriceChangeRequestController::class, 'financeApprove'])->name('finance.price-changes.approve');
        Route::post('/{id}/reject', [PriceChangeRequestController::class, 'financeReject'])->name('finance.price-changes.reject');
    });

    // ============================================
    // REPAIR SERVICE PRICE CHANGE REQUESTS
    // ============================================
    Route::prefix('repair-price-changes')->group(function () {
        // View all repair service price change requests
        Route::get('/', [RepairServiceController::class, 'financePending'])->name('finance.repair-price-changes.index');
        
        // Finance approval actions
        Route::post('/{id}/approve', [RepairServiceController::class, 'financeApprove'])->name('finance.repair-price-changes.approve');
        Route::post('/{id}/reject', [RepairServiceController::class, 'financeReject'])->name('finance.repair-price-changes.reject');
    });
});

/**
 * Finance Payslip Approval Routes
 * Finance reviews HR-generated payslips before employee release.
 * Separated from the HR module intentionally: Finance approves, HR generates.
 * Permission required: approve-payroll
 */
Route::prefix('api/finance/payslip-approvals')->middleware(['web', 'auth:user', 'permission:approve-payroll|access-payslip-approval', 'shop.isolation'])->group(function () {
    Route::get('/', [FinancePayslipApprovalController::class, 'getPayslipsForApproval'])->name('finance.payslip_approval.index');
    Route::get('/{id}', [FinancePayslipApprovalController::class, 'getPayslipForApproval'])->name('finance.payslip_approval.show');
    Route::post('/{id}/approve', [FinancePayslipApprovalController::class, 'approvePayslip'])->name('finance.payslip_approval.approve');
    Route::post('/{id}/reject', [FinancePayslipApprovalController::class, 'rejectPayslip'])->name('finance.payslip_approval.reject');
    Route::post('/batch/preview', [FinancePayslipApprovalController::class, 'batchApprovalPreview'])->name('finance.payslip_approval.batch_preview');
    Route::post('/batch/approve', [FinancePayslipApprovalController::class, 'batchApprove'])->name('finance.payslip_approval.batch_approve');
});

/**
 * Session-based Finance Routes (aliases for backward compatibility)
 * These routes map to the same controllers as the main finance routes
 * but use the /api/finance/session prefix (used by frontend code)
 */
Route::prefix('api/finance/session')->middleware(['web', 'auth:user', 'permission:access-finance-dashboard|access-finance-expenses|access-finance-invoices', 'shop.isolation'])->group(function () {
    
    // ============================================
    // EXPENSES (Session-based)
    // ============================================
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('finance.session.expenses.index');
        Route::get('/{id}', [ExpenseController::class, 'show'])->name('finance.session.expenses.show');
        Route::post('/', [ExpenseController::class, 'store'])->name('finance.session.expenses.store');
        Route::patch('/{id}', [ExpenseController::class, 'update'])->name('finance.session.expenses.update');
        Route::delete('/{id}', [ExpenseController::class, 'destroy'])->name('finance.session.expenses.destroy');
        Route::get('/{id}/receipt/download', [ExpenseController::class, 'downloadReceipt'])->name('finance.session.expenses.download');
        
        // Approval actions (requires access-finance-expenses permission)
        Route::middleware('permission:access-finance-expenses')->group(function () {
            Route::post('/{id}/approve', [ExpenseController::class, 'approve'])->name('finance.session.expenses.approve');
            Route::post('/{id}/reject', [ExpenseController::class, 'reject'])->name('finance.session.expenses.reject');
            Route::post('/{id}/post', [ExpenseController::class, 'post'])->name('finance.session.expenses.post');
        });
    });

    // ============================================
    // INVOICES (Session-based)
    // ============================================
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('finance.session.invoices.index');
        Route::get('/{id}', [InvoiceController::class, 'show'])->name('finance.session.invoices.show');
        Route::post('/', [InvoiceController::class, 'store'])->name('finance.session.invoices.store');
        Route::post('/from-job', [InvoiceController::class, 'createFromJob'])->name('finance.session.invoices.from_job');
        Route::patch('/{id}', [InvoiceController::class, 'update'])->name('finance.session.invoices.update');
        Route::delete('/{id}', [InvoiceController::class, 'destroy'])->name('finance.session.invoices.destroy');
        Route::post('/{id}/send', [InvoiceController::class, 'send'])->name('finance.session.invoices.send');
        Route::post('/{id}/void', [InvoiceController::class, 'void'])->name('finance.session.invoices.void');
        Route::post('/{id}/mark-paid', [InvoiceController::class, 'markAsPaid'])->name('finance.session.invoices.mark_paid');
        
        // Post to ledger (requires access-finance-invoices permission)
        Route::middleware('permission:access-finance-invoices')->post('/{id}/post', [InvoiceController::class, 'post'])->name('finance.session.invoices.post');
    });
});
