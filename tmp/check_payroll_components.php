<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Check TaxBracket table
$brackets = DB::table('tax_brackets')->where('shop_owner_id', 1)->count();
echo "TaxBrackets for shop 1: $brackets\n";

// Check if the service logs $totalDeductions correctly
// The service sets total_deductions from $deductions->sum which only counts TYPE_DEDUCTION components
// For a standard payroll with no deduction components, it would be 0
// SSS/PhilHealth/PagIBIG columns are separate on the payrolls table

// Let's check what components were created in a fresh test
$user = App\Models\User::first();
if ($user) {
    Illuminate\Support\Facades\Auth::guard('user')->login($user);
}

$emp = App\Models\Employee::first();
$service = new App\Services\HR\PayrollService();
$payroll = $service->generatePayroll($emp, '2099-02', [], ['attendance_days' => 22]);
$payroll->update(['status' => 'pending']);

echo "\nComponents generated:\n";
foreach ($payroll->components as $c) {
    echo "  [{$c->component_type}] {$c->component_name}: {$c->calculated_amount}\n";
}
echo "\ngross_salary: {$payroll->gross_salary}\n";
echo "total_deductions: {$payroll->total_deductions}\n";
echo "tax_amount: {$payroll->tax_amount}\n";
echo "net_salary: {$payroll->net_salary}\n";

// Clean up
$payroll->components()->delete();
$payroll->delete();
echo "\nCleanup done.\n";
