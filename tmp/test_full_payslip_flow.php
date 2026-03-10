<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Authenticate as a Finance user with approve-payroll permission
$user = App\Models\User::first();
if ($user) {
    Illuminate\Support\Facades\Auth::guard('user')->login($user);
    echo "Logged in as: {$user->name} (id={$user->id})\n";
}

$emp = App\Models\Employee::first();
echo "Employee: {$emp->first_name} {$emp->last_name} (shop_owner_id={$emp->shop_owner_id})\n\n";

// 2. Generate payroll (HR step)
$service = new App\Services\HR\PayrollService();
$payroll = $service->generatePayroll($emp, '2099-03', [], [
    'attendance_days' => 18,  // worked 18 of 26 days
    'leave_days'      => 2,   // 2 days approved leave
    'absent_days'     => 6,   // 6 days unexcused absent (26 - 18 - 2)
]);
$payroll->update(['status' => 'pending']);  // reset as PayrollController::store() does

echo "=== STEP 1: HR GENERATES PAYSLIP ===\n";
echo "  status          : {$payroll->status}\n";
echo "  approval_status : {$payroll->approval_status}\n";
echo "  payroll_period  : {$payroll->payroll_period}\n";
echo "  pay_period_start: {$payroll->pay_period_start}\n";
echo "  attendance_days : {$payroll->attendance_days}\n";
echo "  leave_days      : {$payroll->leave_days}\n";
echo "  absent_days     : {$payroll->absent_days}\n";
echo "  gross_salary    : {$payroll->gross_salary}\n";
echo "  total_deductions: {$payroll->total_deductions}\n";
echo "  net_salary      : {$payroll->net_salary}\n";
echo "  components      : " . $payroll->components->count() . "\n";
foreach ($payroll->components as $c) {
    echo "    [{$c->component_type}] {$c->component_name}: {$c->calculated_amount}\n";
}

// 3. Finance approves (mimic PayslipApprovalController::approvePayslip)
$payroll->update([
    'approval_status' => 'approved',
    'approved_by'     => $user->id,
    'approved_at'     => now(),
    'approval_notes'  => 'Approved by Finance test',
]);
$payroll->refresh();

echo "\n=== STEP 2: FINANCE APPROVES PAYSLIP ===\n";
echo "  approval_status : {$payroll->approval_status}\n";
echo "  approved_at     : {$payroll->approved_at}\n";

// 4. Employee self-service view (mimic myPayslips endpoint)
$empRecord = App\Models\Employee::where('shop_owner_id', $user->shop_owner_id)
    ->where('email', $user->email)
    ->first();

if ($empRecord) {
    $myPayslips = App\Models\HR\Payroll::where('shop_owner_id', $user->shop_owner_id)
        ->where('employee_id', $empRecord->id)
        ->where('approval_status', '!=', 'rejected')
        ->with(['components'])
        ->get();
    echo "\n=== STEP 3: EMPLOYEE VIEWS PAYSLIPS ===\n";
    echo "  Payslips visible: " . $myPayslips->count() . "\n";
} else {
    echo "\n=== STEP 3: No employee record matching user email (expected in test) ===\n";
    echo "  Payroll is viewable via employee record lookup\n";
}

// Verify the Finance list endpoint returns correct shape
echo "\n=== VERIFYING Finance list shape ===\n";
$reloaded = App\Models\HR\Payroll::with('employee:id,first_name,last_name,department,position')->find($payroll->id);
echo "  employee_name : {$reloaded->employee->first_name} {$reloaded->employee->last_name}\n";
echo "  gross_pay     : {$reloaded->gross_salary}\n";
echo "  deductions    : " . ($reloaded->total_deductions ?? $reloaded->deductions) . "\n";
echo "  net_pay       : {$reloaded->net_salary}\n";
echo "  role          : " . ($reloaded->employee->position ?? 'N/A') . "\n";
echo "  status(appr)  : {$reloaded->approval_status}\n";

// Cleanup
$payroll->components()->delete();
$payroll->delete();
echo "\n=== CLEANUP DONE ===\n";
