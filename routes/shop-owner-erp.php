<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Controllers\Api\CRM\CRMCustomerController;
use App\Http\Controllers\Api\CRM\CRMDashboardController;
use App\Http\Controllers\Api\CRM\CRMReviewController;
use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Logistics\ErpLogisticsController;
use App\Http\Controllers\Staff\CustomerController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use App\Services\ErpWorkspaceNavigationService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(EnsureOwnerErpWorkspaceEnabled::class)
    ->prefix('shop-owner/erp')
    ->name('shop-owner.erp.')
    ->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'index'])
            ->name('workspace');

        Route::get('/{module}', [WorkspaceController::class, 'module'])
            ->whereIn('module', ErpWorkspaceNavigationService::slugs())
            ->name('module');

        Route::get('/retail/dashboard', function (): \Inertia\Response {
            return Inertia::render('ShopOwner/Dashboard', [
                'erpMode' => true,
            ]);
        })->name('retail.dashboard');

        Route::get('/retail/products', function (): \Inertia\Response {
            return Inertia::render('ShopOwner/Products/product management/ProductManagementWithVariants', [
                'erpMode' => true,
            ]);
        })->name('retail.products');

        Route::prefix('retail')->name('retail.')->group(function (): void {
            Route::get('/orders', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Orders/order management/JobOrders', [
                    'erpMode' => true,
                ]);
            })->name('orders');

            Route::get('/point-of-sale', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Repairs/service management/POS', [
                    'erpMode' => true,
                ]);
            })->name('point-of-sale');

            Route::get('/discounts', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Orders/order management/discount', [
                    'erpMode' => true,
                ]);
            })->name('discounts');
        });

        Route::prefix('repair')->name('repair.')->group(function (): void {
            Route::get('/job-orders', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Repairs/service management/JobOrdersRepair', [
                    'erpMode' => true,
                ]);
            })->name('job-orders');

            Route::get('/warranty-queue', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Repairs/service management/WarrantyQueue', [
                    'erpMode' => true,
                ]);
            })->name('warranty-queue');

            Route::get('/services', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Repairs/service management/uploadService', [
                    'erpMode' => true,
                ]);
            })->name('services');

            Route::get('/stock-materials', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Repairs/individual/uploadStockMaterial', [
                    'erpMode' => true,
                ]);
            })->name('stock-materials');

            Route::get('/point-of-sale', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Repairs/service management/POS', [
                    'erpMode' => true,
                ]);
            })->name('point-of-sale');

            Route::get('/support', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Customers/customer management/repairSupport', [
                    'erpMode' => true,
                ]);
            })->name('support');
        });

        Route::prefix('crm')->name('crm.')->group(function (): void {
            Route::get('/dashboard', [CRMDashboardController::class, 'indexPage'])
                ->name('dashboard');
            Route::get('/customers', [CRMCustomerController::class, 'indexPage'])
                ->name('customers');
            Route::get('/customer-reviews', [CRMReviewController::class, 'indexPage'])
                ->name('customer-reviews');
            Route::get('/customer-support', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Customers/customer management/customerSupport', [
                    'erpMode' => true,
                ]);
            })->name('customer-support');
        });

        Route::prefix('logistics')->name('logistics.')->group(function (): void {
            Route::get('/dashboard', [ErpLogisticsController::class, 'dashboard'])
                ->name('dashboard');
            Route::get('/shipments', [ErpLogisticsController::class, 'shipments'])
                ->name('shipments');
            Route::get('/riders', [ErpLogisticsController::class, 'riders'])
                ->name('riders');
            Route::get('/batches', [ErpLogisticsController::class, 'batches'])
                ->name('batches');
            Route::get('/settings', [ErpLogisticsController::class, 'settings'])
                ->name('settings');
        });

        Route::prefix('hr')->name('hr.')->group(function (): void {
            Route::get('/dashboard', [ReadPageController::class, 'hrDashboard'])
                ->name('dashboard');
            Route::get('/employee-directory', [ReadPageController::class, 'hrEmployeeDirectory'])
                ->name('employee-directory');
            Route::get('/attendance', [ReadPageController::class, 'hrAttendance'])
                ->name('attendance');
            Route::get('/leave-approvals', [ReadPageController::class, 'hrLeaveApprovals'])
                ->name('leave-approvals');
            Route::get('/overtime-approvals', [ReadPageController::class, 'hrOvertimeApprovals'])
                ->name('overtime-approvals');
            Route::get('/payroll-view', [ReadPageController::class, 'hrPayrollView'])
                ->name('payroll-view');
            Route::get('/payroll-generate', [ReadPageController::class, 'hrPayrollGenerate'])
                ->name('payroll-generate');
            Route::get('/salary-changes', [ReadPageController::class, 'hrSalaryChanges'])
                ->name('salary-changes');
            Route::get('/suspend-accounts', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/TeamManagement/suspendAccount', [
                    'erpMode' => true,
                ]);
            })->name('suspend-accounts');
            Route::get('/audit-logs', [ReadPageController::class, 'hrAuditLogs'])
                ->name('audit-logs');
        });

        Route::prefix('finance')->name('finance.')->group(function (): void {
            Route::get('/expense-approvals', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Approvals/ExpenseApproval', [
                    'erpMode' => true,
                ]);
            })->name('expense-approvals');
            Route::get('/refund-approvals', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Approvals/refundApproval', [
                    'erpMode' => true,
                ]);
            })->name('refund-approvals');
            Route::get('/price-approvals', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Approvals/PriceApprovals', [
                    'erpMode' => true,
                ]);
            })->name('price-approvals');
            Route::get('/payslip-approvals', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Approvals/PayslipApproval', [
                    'erpMode' => true,
                ]);
            })->name('payslip-approvals');
            Route::get('/salary-adjustment-approvals', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Approvals/SalaryChangesApproval', [
                    'erpMode' => true,
                ]);
            })->name('salary-adjustment-approvals');
            Route::get('/audit-logs', [ReadPageController::class, 'financeAuditLogs'])
                ->name('audit-logs');
        });

        Route::prefix('manager')->name('manager.')->group(function (): void {
            Route::get('/reports', [ReadPageController::class, 'managerReports'])
                ->name('reports');
            Route::get('/audit-logs', [ReadPageController::class, 'managerAuditLogs'])
                ->name('audit-logs');
        });

        Route::prefix('inventory')->name('inventory.')->group(function (): void {
            Route::get('/overview', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Products/product management/InventoryOverview', [
                    'erpMode' => true,
                ]);
            })->name('overview');
            Route::get('/inventory-dashboard', [ReadPageController::class, 'inventoryDashboard'])
                ->name('inventory-dashboard');
            Route::get('/upload-stocks', [ReadPageController::class, 'uploadInventory'])
                ->name('upload-stocks');
            Route::get('/product-inventory', [ReadPageController::class, 'productInventory'])
                ->name('product-inventory');
            Route::get('/stock-movement', [ReadPageController::class, 'stockMovement'])
                ->name('stock-movement');
            Route::get('/stock-request', [ReadPageController::class, 'inventoryStockRequest'])
                ->name('stock-request');
            Route::get('/request-material-approval', [ReadPageController::class, 'requestMaterialApproval'])
                ->name('request-material-approval');
            Route::get('/supplier-order-monitoring', [ReadPageController::class, 'supplierOrderMonitoring'])
                ->name('supplier-order-monitoring');
        });

        Route::prefix('procurement')->name('procurement.')->group(function (): void {
            Route::get('/purchase-request', [ReadPageController::class, 'purchaseRequest'])
                ->name('purchase-request');
            Route::get('/purchase-orders', [ReadPageController::class, 'purchaseOrders'])
                ->name('purchase-orders');
            Route::get('/stock-request-approval', [ReadPageController::class, 'procurementStockRequestApproval'])
                ->name('stock-request-approval');
            Route::get('/suppliers-management', [ReadPageController::class, 'procurementSuppliers'])
                ->name('suppliers-management');
            Route::get('/purchase-request-approval', function (): \Inertia\Response {
                return Inertia::render('ShopOwner/Approvals/PurchaseRequestApproval', [
                    'erpMode' => true,
                ]);
            })->name('purchase-request-approval');
        });

        Route::prefix('staff')->name('staff.')->group(function (): void {
            Route::get('/customers', [CustomerController::class, 'index'])
                ->name('customers');
            Route::get('/repair-dashboard', [ReadPageController::class, 'repairDashboard'])
                ->name('repair-dashboard');
        });
    });
