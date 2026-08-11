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
use App\Http\Controllers\Api\Finance\TaxRateController;
use App\Http\Controllers\Api\Finance\FinanceSummaryController;
use App\Http\Controllers\Api\Finance\PayslipApprovalController as FinancePayslipApprovalController;
use App\Http\Controllers\ERP\HR\AuditLogController;
use App\Http\Controllers\Erp\HR\PayrollController;
use App\Http\Controllers\Erp\PurchaseRequestController as ErpPurchaseRequestController;
use App\Http\Controllers\Api\PriceChangeRequestController;
use App\Http\Controllers\Api\RepairServiceController;
use App\Http\Controllers\Api\RefundApprovalController;

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
 * Finance tax operations are independently capability-protected.
 */
Route::prefix('api/finance')->middleware(['web', 'auth:user', 'permission:manage-finance-tax', 'shop.isolation'])->group(function () {
    Route::prefix('tax-rates')->group(function () {
        Route::get('/', [TaxRateController::class, 'index'])->name('finance.tax-rates.index');
        Route::post('/', [TaxRateController::class, 'store'])->name('finance.tax-rates.store');
        Route::get('/effective', [TaxRateController::class, 'effective'])->name('finance.tax-rates.effective');
        Route::get('/default', [TaxRateController::class, 'getDefault'])->name('finance.tax-rates.default');
        Route::post('/calculate', [TaxRateController::class, 'calculate'])->name('finance.tax-rates.calculate');
        Route::get('/{id}', [TaxRateController::class, 'show'])->whereNumber('id')->name('finance.tax-rates.show');
        Route::put('/{id}', [TaxRateController::class, 'update'])->whereNumber('id')->name('finance.tax-rates.update');
        Route::delete('/{id}', [TaxRateController::class, 'destroy'])->whereNumber('id')->name('finance.tax-rates.destroy');
    });
});

Route::prefix('api/finance')->middleware(['web', 'auth:user', 'permission:access-finance-dashboard', 'shop.isolation'])->group(function () {
    Route::get('/dashboard', FinanceSummaryController::class)->name('finance.dashboard.summary');
});

/**
 * Finance Module Routes - General Operations
 * Accessible by users with any Finance permissions (including pricing approvals)
 */
Route::prefix('api/finance')->middleware(['web', 'auth:user', 'shop.isolation'])->group(function () {

    // ============================================
    // PURCHASE REQUEST FINANCE REVIEW
    // ============================================
    Route::prefix('purchase-requests')->middleware('permission:access-purchase-request-approval')->group(function () {
        Route::get('/', [ErpPurchaseRequestController::class, 'index'])->name('finance.purchase-requests.index');
        Route::post('/{id}/approve', [ErpPurchaseRequestController::class, 'approve'])->name('finance.purchase-requests.approve');
        Route::post('/{id}/reject', [ErpPurchaseRequestController::class, 'reject'])->name('finance.purchase-requests.reject');
    });

    // ============================================
    // EXPENSES
    // ============================================
    Route::prefix('expenses')->middleware('permission:access-finance-expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('finance.expenses.index');
        Route::post('/{id}/receipt', [ExpenseController::class, 'uploadReceipt'])->whereNumber('id')->name('finance.expenses.receipt.upload');
        Route::get('/{id}/receipt', [ExpenseController::class, 'downloadReceipt'])->whereNumber('id')->name('finance.expenses.receipt.download');
        Route::delete('/{id}/receipt', [ExpenseController::class, 'deleteReceipt'])->whereNumber('id')->name('finance.expenses.receipt.delete');
        Route::get('/{id}', [ExpenseController::class, 'show'])->name('finance.expenses.show');
        Route::post('/', [ExpenseController::class, 'store'])->name('finance.expenses.store');
        Route::patch('/{id}', [ExpenseController::class, 'update'])->name('finance.expenses.update');
        Route::delete('/{id}', [ExpenseController::class, 'destroy'])->name('finance.expenses.destroy');
        Route::post('/{id}/restore', [ExpenseController::class, 'restore'])->name('finance.expenses.restore');
        Route::middleware('permission:access-approval-workflow|approve-expenses')->group(function () {
            Route::post('/{id}/approve', [ExpenseController::class, 'approve'])->name('finance.expenses.approve');
            Route::post('/{id}/reject', [ExpenseController::class, 'reject'])->name('finance.expenses.reject');
        });
        
    });


    // ============================================
    // INVOICES
    // ============================================
    Route::prefix('invoices')->middleware('permission:access-finance-invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('finance.invoices.index');
        Route::get('/{id}', [InvoiceController::class, 'show'])->name('finance.invoices.show');
        Route::post('/', [InvoiceController::class, 'store'])->name('finance.invoices.store');
        Route::post('/from-job', [InvoiceController::class, 'createFromJob'])->name('finance.invoices.from_job');
        Route::patch('/{id}', [InvoiceController::class, 'update'])->name('finance.invoices.update');
        Route::delete('/{id}', [InvoiceController::class, 'destroy'])->name('finance.invoices.destroy');
        Route::post('/{id}/restore', [InvoiceController::class, 'restore'])->name('finance.invoices.restore');
        Route::post('/{id}/send', [InvoiceController::class, 'send'])->name('finance.invoices.send');
        Route::post('/{id}/void', [InvoiceController::class, 'void'])->name('finance.invoices.void');
        Route::get('/{id}/payments', [InvoiceController::class, 'listPayments'])->name('finance.invoices.payments.index');
        Route::post('/{id}/payments', [InvoiceController::class, 'recordPayment'])->name('finance.invoices.payments.store');
        Route::post('/{id}/payments/{paymentId}/reverse', [InvoiceController::class, 'reversePayment'])->name('finance.invoices.payments.reverse');
        Route::post('/{id}/mark-paid', [InvoiceController::class, 'markAsPaid'])->name('finance.invoices.mark_paid');
        
        // Post to ledger (requires access-finance-invoices permission)
        Route::middleware('permission:access-finance-invoices')->post('/{id}/post', [InvoiceController::class, 'post'])->name('finance.invoices.post');
    });

    // ============================================
    // PRICE CHANGE REQUESTS
    // ============================================
    Route::prefix('price-changes')->middleware('permission:access-shoe-price-approval')->group(function () {
        // View all price change requests
        Route::get('/', [PriceChangeRequestController::class, 'index'])->name('finance.price-changes.index');
        
        // Finance approval actions
        Route::post('/{id}/approve', [PriceChangeRequestController::class, 'financeApprove'])->name('finance.price-changes.approve');
        Route::post('/{id}/reject', [PriceChangeRequestController::class, 'financeReject'])->name('finance.price-changes.reject');
    });

    // ============================================
    // REPAIR SERVICE PRICE CHANGE REQUESTS
    // ============================================
    Route::prefix('repair-price-changes')->middleware('permission:access-repair-price-approval')->group(function () {
        // View all repair service price change requests
        Route::get('/', [RepairServiceController::class, 'financePending'])->name('finance.repair-price-changes.index');
        
        // Finance approval actions
        Route::post('/{id}/approve', [RepairServiceController::class, 'financeApprove'])->name('finance.repair-price-changes.approve');
        Route::post('/{id}/reject', [RepairServiceController::class, 'financeReject'])->name('finance.repair-price-changes.reject');
        Route::post('/{id}/approve-final', [RepairServiceController::class, 'financeApproveFinal'])->name('finance.repair-price-changes.approve-final');
    });

    // ============================================
    // REFUND APPROVALS
    // ============================================
    Route::prefix('refunds')->middleware('permission:access-refund-approval')->group(function () {
        Route::get('/', [RefundApprovalController::class, 'financeIndex'])->name('finance.refunds.index');
        Route::post('/{id}/approve', [RefundApprovalController::class, 'financeApprove'])->name('finance.refunds.approve');
        Route::post('/{id}/reject', [RefundApprovalController::class, 'financeReject'])->name('finance.refunds.reject');
        Route::post('/{id}/execute-gateway-refund', [RefundApprovalController::class, 'financeExecuteGatewayRefund'])->name('finance.refunds.execute');
    });
});

/**
 * Finance Payslip Approval Routes
 * Finance reviews HR-generated payslips before employee release.
 * Separated from the HR module intentionally: Finance approves, HR generates.
 * Access: Shop Owner role OR Finance approval permissions
 */
Route::prefix('api/finance/payslip-approvals')->middleware(['web', 'auth:user', 'shop.isolation'])->group(function () {
    Route::middleware('permission:access-payslip-approval')->group(function () {
    Route::get('/', [FinancePayslipApprovalController::class, 'getPayslipsForApproval'])->name('finance.payslip_approval.index');
    Route::get('/{id}', [FinancePayslipApprovalController::class, 'getPayslipForApproval'])->whereNumber('id')->name('finance.payslip_approval.show');
    Route::post('/{id}/approve', [FinancePayslipApprovalController::class, 'approvePayslip'])->whereNumber('id')->name('finance.payslip_approval.approve');
    Route::post('/{id}/reject', [FinancePayslipApprovalController::class, 'rejectPayslip'])->whereNumber('id')->name('finance.payslip_approval.reject');
    Route::post('/{id}/final-approve', [FinancePayslipApprovalController::class, 'finalApprovePayslip'])->whereNumber('id')->name('finance.payslip_approval.final_approve');
    Route::post('/batch/preview', [FinancePayslipApprovalController::class, 'batchApprovalPreview'])->name('finance.payslip_approval.batch_preview');
    Route::post('/batch/approve', [FinancePayslipApprovalController::class, 'batchApprove'])->name('finance.payslip_approval.batch_approve');
    });
    // Payroll disbursement keeps its existing controller authorization until Task 13.
    Route::post('/disburse', [PayrollController::class, 'process'])->name('finance.payslip_approval.disburse');
});

// Session-based /api/finance/session aliases removed.
// Canonical secured session routes are defined in routes/web.php.
