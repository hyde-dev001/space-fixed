<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Repairer\DashboardController;
use App\Models\Employee;
use App\Models\Finance\Expense as FinanceExpense;
use App\Models\Finance\Invoice;
use App\Models\InventoryItem;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\Department;
use App\Models\HR\LeaveRequest;
use App\Models\HR\OvertimeRequest;
use App\Models\OrderRefund;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ReadPageController extends Controller
{
    public function hrAuditLogs(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/HR/AuditLogs');
    }

    public function financeAuditLogs(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/Finance/AuditLogs');
    }

    public function financeDashboard(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $invoices = Invoice::where('shop_id', $shopOwnerId)
            ->whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->with(['jobOrder:id,payment_status'])
            ->select(['id', 'reference', 'status', 'total', 'tax_amount', 'meta', 'date', 'job_order_id'])
            ->orderByDesc('date')
            ->get()
            ->map(static function (Invoice $invoice): array {
                $paymentStatus = strtolower((string) ($invoice->jobOrder?->payment_status ?? ''));
                $effectiveStatus = $paymentStatus === 'refunded'
                    ? 'refunded'
                    : (string) $invoice->status;

                return [
                    'id' => $invoice->id,
                    'reference' => $invoice->reference,
                    'status' => $invoice->status,
                    'effective_status' => $effectiveStatus,
                    'total' => $invoice->total,
                    'tax_amount' => $invoice->tax_amount,
                    'meta' => $invoice->meta,
                    'date' => optional($invoice->date)->toDateString(),
                ];
            })
            ->values();

        $expenses = FinanceExpense::where('shop_id', $shopOwnerId)
            ->whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->select(['id', 'reference', 'status', 'amount', 'date'])
            ->orderByDesc('date')
            ->get();

        $refunds = OrderRefund::where('shop_owner_id', $shopOwnerId)
            ->whereYear('refunded_at', now()->year)
            ->whereNotNull('refunded_at')
            ->where('status', 'succeeded')
            ->select(['id', 'order_id', 'amount', 'status', 'refunded_at', 'requested_at'])
            ->orderByDesc('refunded_at')
            ->get()
            ->map(static fn (OrderRefund $refund): array => [
                'id' => $refund->id,
                'order_id' => $refund->order_id,
                'amount' => round(max(0.0, (float) ($refund->amount ?? 0)), 2),
                'status' => $refund->status,
                'refunded_at' => optional($refund->refunded_at)->toDateTimeString(),
                'requested_at' => optional($refund->requested_at)->toDateTimeString(),
            ])
            ->values();

        return Inertia::render('ERP/Finance/Dashboard', [
            'invoices' => $invoices,
            'expenses' => $expenses,
            'refunds' => $refunds,
            'refundedRevenue' => $refunds->sum('amount'),
        ]);
    }

    public function financeInvoices(): Response|RedirectResponse
    {
        return $this->renderFinanceSection('invoice-generation');
    }

    public function financeCreateInvoice(): Response|RedirectResponse
    {
        return $this->renderFinanceSection('create-invoice');
    }

    public function financeExpenses(): Response|RedirectResponse
    {
        return $this->renderFinanceSection('expense-tracking');
    }

    public function hrDashboard(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $employees = Employee::where('shop_owner_id', $shopOwnerId)->get(['department', 'status', 'branch']);
        $activeEmployees = $employees->where('status', 'active')->count();
        $byDepartment = $employees->where('status', 'active')
            ->groupBy(fn (Employee $employee) => $employee->department ?: 'Unassigned')
            ->map(fn ($group, $department) => [
                'department' => $department,
                'count' => $group->count(),
            ])->values();
        $byStatus = $employees->groupBy('status')
            ->map(fn ($group, $status) => ['status' => $status, 'count' => $group->count()])
            ->values();

        $initialHrDashboard = [
            'headcount' => [
                'current_headcount' => $activeEmployees,
                'by_department' => $byDepartment,
                'by_location' => [],
                'by_status' => $byStatus,
                'monthly_trend' => [],
            ],
            'turnover' => [],
            'attendance' => [],
            'payroll' => [],
            'performance' => [],
            'summary' => [
                'total_employees' => $employees->count(),
                'active_employees' => $activeEmployees,
                'current_on_leave' => $employees->where('status', 'on_leave')->count(),
                'total_departments' => Department::where('shop_owner_id', $shopOwnerId)->count(),
                'pending_leave_requests' => LeaveRequest::where('shop_owner_id', $shopOwnerId)->where('status', 'pending')->count(),
                'this_month_payroll' => 0,
            ],
            'period' => [
                'start_date' => now()->subYear()->toISOString(),
                'end_date' => now()->toISOString(),
            ],
        ];

        return Inertia::render('ERP/HR/HR', [
            'initialHrDashboard' => $initialHrDashboard,
            'initialSection' => 'dashboard',
        ]);
    }

    public function hrEmployeeDirectory(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialEmployees = Employee::with('user:id,email')
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderBy('name')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'id' => $employee->id,
                'firstName' => $employee->first_name,
                'lastName' => $employee->last_name,
                'employeeId' => $employee->employee_id,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'department' => $employee->department,
                'position' => $employee->position,
                'status' => $employee->status,
                'hiredAt' => optional($employee->hire_date)->toDateString(),
                'lastActiveAt' => optional($employee->updated_at)->toISOString(),
                'location' => $employee->location ?? $employee->address,
                'linkedUser' => $employee->user?->id,
            ])
            ->values();

        return Inertia::render('ERP/HR/HR', [
            'initialEmployees' => $initialEmployees,
            'initialSection' => 'employees',
        ]);
    }

    public function hrAttendance(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialAttendance = AttendanceRecord::with('employee:id,first_name,last_name,name,email,department,position,shop_owner_id')
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderByDesc('date')
            ->paginate(200);

        return Inertia::render('ERP/HR/HR', [
            'initialAttendance' => $initialAttendance,
            'initialSection' => 'attendance',
        ]);
    }

    public function hrLeaveApprovals(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialLeaveRequests = LeaveRequest::with('employee:id,first_name,last_name,name,department,position,shop_owner_id')
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderByDesc('created_at')
            ->paginate(200);

        return Inertia::render('ERP/HR/HR', [
            'initialLeaveRequests' => $initialLeaveRequests,
            'initialSection' => 'leaves',
        ]);
    }

    public function hrOvertimeApprovals(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialOvertimeRequests = OvertimeRequest::with('employee:id,first_name,last_name,name,department,position,shop_owner_id')
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderByDesc('created_at')
            ->paginate(200);

        return Inertia::render('ERP/HR/HR', [
            'initialOvertimeRequests' => $initialOvertimeRequests,
            'initialSection' => 'overtime',
        ]);
    }

    public function hrPayrollView(): Response|RedirectResponse
    {
        return $this->renderHrSection('payroll-view');
    }

    public function hrPayrollGenerate(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialPayrollEmployees = Employee::forShopOwner($this->shopOwnerId())
            ->where('status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return Inertia::render('ERP/HR/HR', [
            'initialPayrollEmployees' => $initialPayrollEmployees,
            'initialSection' => 'payroll-generate',
        ]);
    }

    public function hrSalaryChanges(): Response|RedirectResponse
    {
        return $this->renderHrSection('salary-changes');
    }

    public function managerReports(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/Manager/Reports');
    }

    public function managerAuditLogs(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/Manager/AuditLogs');
    }

    public function inventoryDashboard(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $initialData = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(200);
        $initialMetrics = [
            'total_items' => InventoryItem::where('shop_owner_id', $shopOwnerId)->where('is_active', true)->count(),
            'low_stock_count' => InventoryItem::where('shop_owner_id', $shopOwnerId)->lowStock()->count(),
            'out_of_stock_count' => InventoryItem::where('shop_owner_id', $shopOwnerId)->outOfStock()->count(),
        ];

        return Inertia::render('ERP/inventory/InventoryDashboard', compact('initialData', 'initialMetrics'));
    }

    public function productInventory(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $this->shopOwnerId())
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(200);

        return Inertia::render('ERP/inventory/ProductInventory', compact('initialData'));
    }

    public function stockMovement(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = StockMovement::with(['inventoryItem', 'performer'])
            ->whereHas('inventoryItem', fn ($query) => $query->where('shop_owner_id', $this->shopOwnerId()))
            ->orderBy('performed_at', 'desc')
            ->paginate(200);

        return Inertia::render('ERP/inventory/StockMovement', compact('initialData'));
    }

    public function uploadInventory(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = InventoryItem::with(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderByDesc('created_at')
            ->paginate(50);

        return Inertia::render('ERP/inventory/UploadInventory', compact('initialData'));
    }

    public function inventoryStockRequest(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $initialRequests = StockRequestApproval::with(['inventoryItem.sizes', 'inventoryItem.colorVariants.sizes', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderByDesc('requested_date')
            ->paginate(200);
        $initialInventoryItems = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(200);

        return Inertia::render('ERP/inventory/StockRequest', compact('initialRequests', 'initialInventoryItems'));
    }

    public function requestMaterialApproval(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = StockRequestApproval::with(['shopOwner', 'inventoryItem.sizes', 'inventoryItem.colorVariants.sizes', 'requester', 'approver'])
            ->where('shop_owner_id', $this->shopOwnerId())
            ->where('request_source', 'repair')
            ->orderByDesc('requested_date')
            ->paginate(200);

        return Inertia::render('ERP/inventory/RequestApproval', compact('initialData'));
    }

    public function supplierOrderMonitoring(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = PurchaseOrder::with(['supplier', 'items.inventoryItem.sizes', 'receipts.items'])
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderByDesc('ordered_date')
            ->paginate(200);

        return Inertia::render('ERP/inventory/SupplierOrderMonitoring', compact('initialData'));
    }

    public function procurementSuppliers(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = Supplier::where('shop_owner_id', $this->shopOwnerId())
            ->orderBy('name')
            ->paginate(100);

        return Inertia::render('ERP/Procurement/SuppliersManagement', compact('initialData'));
    }

    public function purchaseRequest(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $initialData = PurchaseRequest::with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderByDesc('requested_date')
            ->paginate(100);
        $initialSuppliers = Supplier::where('shop_owner_id', $shopOwnerId)->orderBy('name')->get();
        $initialAcceptedRequests = StockRequestApproval::with(['inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'accepted')
            ->whereDoesntHave('purchaseRequest')
            ->orderByDesc('requested_date')
            ->paginate(200);

        return Inertia::render('ERP/Procurement/PurchaseRequest', compact('initialData', 'initialSuppliers', 'initialAcceptedRequests'));
    }

    public function purchaseOrders(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $initialData = PurchaseOrder::with(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderByDesc('ordered_date')
            ->paginate(100);
        $initialApprovedPRs = PurchaseRequest::with(['supplier', 'inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)
            ->approved()
            ->whereDoesntHave('purchaseOrders', fn ($query) => $query->whereNotIn('status', ['cancelled']))
            ->orderByDesc('approved_date')
            ->get();

        return Inertia::render('ERP/Procurement/PurchaseOrders', compact('initialData', 'initialApprovedPRs'));
    }

    public function procurementStockRequestApproval(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = StockRequestApproval::with(['shopOwner', 'inventoryItem.sizes', 'inventoryItem.colorVariants.sizes', 'requester', 'approver'])
            ->where('shop_owner_id', $this->shopOwnerId())
            ->orderByDesc('requested_date')
            ->paginate(100);

        return Inertia::render('ERP/Procurement/StockRequestApproval', compact('initialData'));
    }

    public function repairDashboard(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        try {
            $response = app(DashboardController::class)->getDashboardData();
            $initialDashboard = json_decode($response->getContent(), true);
        } catch (\Throwable) {
            $initialDashboard = null;
        }

        return Inertia::render('ERP/repairer/dashboardRepair', compact('initialDashboard'));
    }

    private function renderEmployeePage(string $component): Response|RedirectResponse
    {
        return $this->employeePasswordRedirect() ?? Inertia::render($component);
    }

    private function renderHrSection(string $section): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        return Inertia::render('ERP/HR/HR', [
            'initialSection' => $section,
        ]);
    }

    private function renderFinanceSection(string $section): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        return Inertia::render('ERP/Finance/Finance', [
            'ownerMode' => true,
            'initialSection' => $section,
            'purchaseRequests' => [],
        ]);
    }

    private function employeePasswordRedirect(): ?RedirectResponse
    {
        $context = $this->erpContext();
        $user = Auth::guard('user')->user();

        if (! $context instanceof ErpActorContext && $user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return null;
    }

    private function shopOwnerId(): int
    {
        $context = $this->erpContext();
        if ($context instanceof ErpActorContext) {
            return (int) $context->tenantOwner()->getKey();
        }

        $user = Auth::guard('user')->user();
        if (! $user) {
            abort(403);
        }

        return (int) ($user->shop_owner_id ?? $user->id);
    }

    private function erpContext(): ?ErpActorContext
    {
        $context = request()->attributes->get('erp.actor_context');

        return $context instanceof ErpActorContext ? $context : null;
    }
}
