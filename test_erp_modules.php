<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== ERP MODULE COMPREHENSIVE TEST ===" . PHP_EOL . PHP_EOL;

// Test Database Tables
echo "1. DATABASE TABLES:" . PHP_EOL;
$tables = [
    'users', 'shop_owners', 'employees', 'orders', 'order_items',
    'products', 'product_variants', 'finance_invoices', 'finance_expenses',
    'approvals', 'hr_attendance_records', 'hr_leave_requests', 'hr_payrolls',
    'notifications', 'conversations', 'audit_logs'
];

foreach ($tables as $table) {
    $exists = Schema::hasTable($table) ? '✓' : '✗';
    echo "  {$exists} {$table}" . PHP_EOL;
}

echo PHP_EOL . "2. DATA COUNTS:" . PHP_EOL;

try {
    echo "  Users (Customers): " . App\Models\User::count() . PHP_EOL;
    echo "  Shop Owners: " . App\Models\ShopOwner::count() . PHP_EOL;
    echo "  Employees: " . App\Models\Employee::count() . PHP_EOL;
    echo "  Products: " . App\Models\Product::count() . PHP_EOL;
    echo "  Orders: " . App\Models\Order::count() . PHP_EOL;
    echo "  Order Items: " . App\Models\OrderItem::count() . PHP_EOL;
    echo "  Invoices: " . App\Models\Finance\Invoice::count() . PHP_EOL;
    echo "  Expenses: " . App\Models\Finance\Expense::count() . PHP_EOL;
    echo "  Approvals: " . App\Models\Approval::count() . PHP_EOL;
    echo "  Notifications: " . App\Models\Notification::count() . PHP_EOL;
    echo "  Conversations: " . App\Models\Conversation::count() . PHP_EOL;
    echo "  Audit Logs: " . App\Models\AuditLog::count() . PHP_EOL;
} catch (\Exception $e) {
    echo "  Error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "3. HR MODULE DATA:" . PHP_EOL;
try {
    echo "  Attendance Records: " . App\Models\HR\AttendanceRecord::count() . PHP_EOL;
    echo "  Leave Requests: " . App\Models\HR\LeaveRequest::count() . PHP_EOL;
    echo "  Payrolls: " . App\Models\HR\Payroll::count() . PHP_EOL;
} catch (\Exception $e) {
    echo "  Error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "4. SAMPLE DATA CHECK:" . PHP_EOL;
try {
    $order = App\Models\Order::first();
    if ($order) {
        echo "  ✓ Sample Order: #{$order->order_number}" . PHP_EOL;
        echo "    Status: {$order->status->value}" . PHP_EOL;
        echo "    Total: ₱" . number_format($order->total_amount, 2) . PHP_EOL;
        echo "    Items: " . $order->items->count() . PHP_EOL;
    } else {
        echo "  ✗ No orders found" . PHP_EOL;
    }

    $invoice = App\Models\Finance\Invoice::first();
    if ($invoice) {
        echo "  ✓ Sample Invoice: {$invoice->reference}" . PHP_EOL;
        echo "    Status: {$invoice->status}" . PHP_EOL;
        echo "    Amount: ₱" . number_format($invoice->total, 2) . PHP_EOL;
    } else {
        echo "  ✗ No invoices found" . PHP_EOL;
    }

    $employee = App\Models\Employee::first();
    if ($employee) {
        echo "  ✓ Sample Employee: {$employee->name}" . PHP_EOL;
        echo "    Position: {$employee->position}" . PHP_EOL;
        echo "    Status: {$employee->status}" . PHP_EOL;
    } else {
        echo "  ✗ No employees found" . PHP_EOL;
    }
} catch (\Exception $e) {
    echo "  Error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "5. API ENDPOINTS CHECK:" . PHP_EOL;
$routes = \Illuminate\Support\Facades\Route::getRoutes();
$modules = ['crm', 'finance', 'hr', 'inventory', 'staff', 'shop-owner'];

foreach ($modules as $module) {
    $count = 0;
    foreach ($routes as $route) {
        if (str_contains($route->getName() ?? '', $module) || str_contains($route->uri(), $module)) {
            $count++;
        }
    }
    echo "  {$module}: {$count} routes" . PHP_EOL;
}

echo PHP_EOL . "=== TEST COMPLETE ===" . PHP_EOL;
