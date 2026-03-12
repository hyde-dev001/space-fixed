<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Authenticate as a test user so Auth::id() doesn't return null
$user = App\Models\User::first();
if ($user) {
    Illuminate\Support\Facades\Auth::guard('user')->login($user);
}

$emp = App\Models\Employee::first();
if (!$emp) {
    echo "No employees found in DB\n";
    exit(1);
}

echo "Testing with: {$emp->first_name} {$emp->last_name} (shop_owner_id={$emp->shop_owner_id})\n";

$service = new App\Services\HR\PayrollService();

try {
    $payroll = $service->generatePayroll($emp, '2099-01', [], ['attendance_days' => 22]);

    echo "SUCCESS\n";
    echo "  id              : {$payroll->id}\n";
    echo "  payroll_period  : {$payroll->payroll_period}\n";
    echo "  pay_period_start: {$payroll->pay_period_start}\n";
    echo "  pay_period_end  : {$payroll->pay_period_end}\n";
    echo "  basic_salary    : {$payroll->basic_salary}\n";
    echo "  gross_salary    : {$payroll->gross_salary}\n";
    echo "  total_deductions: {$payroll->total_deductions}\n";
    echo "  tax_amount      : {$payroll->tax_amount}\n";
    echo "  net_salary      : {$payroll->net_salary}\n";
    echo "  status          : {$payroll->status}\n";
    echo "  approval_status : {$payroll->approval_status}\n";
    echo "  components      : " . $payroll->components->count() . "\n";

    // Clean up
    $payroll->components()->delete();
    $payroll->delete();
    echo "Test payroll deleted.\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
