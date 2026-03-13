<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Find a finance user with approve-payroll + access-audit-logs
$users = App\Models\User::whereNotNull('shop_owner_id')->get();

echo "=== Finance-capable users ===\n";
foreach ($users as $u) {
    $perms = $u->getAllPermissions()->pluck('name')->toArray();
    $hasFinance = in_array('access-finance-dashboard', $perms);
    $hasPayroll = in_array('approve-payroll', $perms);
    $hasAudit   = in_array('access-audit-logs', $perms);
    if ($hasFinance || $hasPayroll || $hasAudit) {
        echo "  ID={$u->id} email={$u->email} role={$u->role}\n";
        echo "  Spatie roles: " . implode(', ', $u->getRoleNames()->toArray()) . "\n";
        echo "  finance_dashboard=$hasFinance | approve_payroll=$hasPayroll | audit_logs=$hasAudit\n";
        echo "  perms: " . implode(', ', $perms) . "\n\n";
    }
}

// Check finance_accounts table
echo "=== finance_accounts table ===\n";
try {
    $accounts = DB::table('finance_accounts')->get();
    echo "Count: " . $accounts->count() . "\n";
    foreach ($accounts->take(3) as $acc) {
        echo "  ID={$acc->id} name={$acc->name} type={$acc->type}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check payrolls with pending approval status
echo "\n=== payrolls pending approval ===\n";
try {
    $payrolls = DB::table('payrolls')
        ->where('approval_status', 'pending')
        ->orWhereNull('approval_status')
        ->limit(5)
        ->get();
    echo "Count: " . $payrolls->count() . "\n";
    foreach ($payrolls as $p) {
        echo "  ID={$p->id} status={$p->status} approval_status={$p->approval_status} shop_owner_id={$p->shop_owner_id}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check old_role middleware – what role is 'Finance Staff'?
echo "\n=== Users with Finance Staff / Finance Manager spatie role ===\n";
try {
    $role = Spatie\Permission\Models\Role::where('name', 'Finance Staff')->first();
    if ($role) {
        echo "Finance Staff role exists, ID={$role->id}\n";
    } else {
        // list all roles
        $roles = Spatie\Permission\Models\Role::all();
        echo "All Spatie roles: " . implode(', ', $roles->pluck('name')->toArray()) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check price_change_requests table
echo "\n=== price_change_requests (pending) ===\n";
try {
    $pcr = DB::table('price_change_requests')->where('status', 'pending')->limit(3)->get();
    echo "Pending count: " . $pcr->count() . "\n";
    $all = DB::table('price_change_requests')->limit(5)->get();
    foreach ($all as $p) {
        echo "  ID={$p->id} status={$p->status}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check repair_services with Under Review status
echo "\n=== repair_services (Under Review) ===\n";
try {
    $rs = DB::table('repair_services')->where('status', 'Under Review')->limit(3)->get();
    echo "Under Review count: " . $rs->count() . "\n";
    $all = DB::table('repair_services')->limit(5)->get();
    foreach ($all as $r) {
        echo "  ID={$r->id} status={$r->status}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
