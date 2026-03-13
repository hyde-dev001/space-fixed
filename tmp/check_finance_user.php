<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = App\Models\User::where('email', 'finance.1@solespace.com')->first();
echo "role column: " . $u->role . "\n";
echo "spatie roles: " . implode(', ', $u->getRoleNames()->toArray()) . "\n";
echo "all permissions: " . implode(', ', $u->getAllPermissions()->pluck('name')->toArray()) . "\n";

// Also check what role the tax-rates route requires
echo "\n--- middleware check for tax-rates route ---\n";
$routes = app('router')->getRoutes()->getRoutes();
foreach ($routes as $r) {
    if (str_contains($r->uri(), 'finance/tax-rates') && str_contains(implode(',', $r->methods()), 'GET')) {
        echo "URI: " . $r->uri() . " | middleware: " . implode(', ', $r->middleware()) . "\n";
        break;
    }
}

// Check audit logs route middleware
foreach ($routes as $r) {
    if (str_contains($r->uri(), 'finance/audit-logs') && $r->methods() === ['GET','HEAD']) {
        echo "URI: " . $r->uri() . " | middleware: " . implode(', ', $r->middleware()) . "\n";
        break;
    }
}

// Check payslip-approvals route middleware
foreach ($routes as $r) {
    if (str_contains($r->uri(), 'finance/payslip-approvals') && $r->methods() === ['GET','HEAD']) {
        if (!str_contains($r->uri(), '{')) {
            echo "URI: " . $r->uri() . " | middleware: " . implode(', ', $r->middleware()) . "\n";
            break;
        }
    }
}
