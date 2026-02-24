<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

echo "=== Checking Staff Account Access ===\n\n";

// Find all staff users
$staffUsers = User::whereHas('roles', function($query) {
    $query->where('name', 'Staff');
})->get();

echo "Found " . $staffUsers->count() . " staff user(s)\n\n";

foreach ($staffUsers as $user) {
    echo "Staff User: {$user->name} (ID: {$user->id}, Email: {$user->email})\n";
    echo "Status: {$user->status}\n";
    
    // Check roles
    $roles = $user->roles->pluck('name')->toArray();
    echo "Roles: " . implode(', ', $roles) . "\n";
    
    // Check direct permissions
    $directPerms = $user->permissions->pluck('name')->toArray();
    echo "Direct Permissions (" . count($directPerms) . "): " . implode(', ', $directPerms) . "\n";
    
    // Check all permissions (role + direct)
    $allPerms = $user->getAllPermissions()->pluck('name')->toArray();
    echo "All Permissions (" . count($allPerms) . "): " . implode(', ', $allPerms) . "\n";
    
    // Check specific permissions for staff menu items
    echo "\nStaff Menu Item Checks:\n";
    echo "  - Dashboard (Staff role): " . ($user->hasRole('Staff') ? '✓' : '✗') . "\n";
    echo "  - Job Orders (view-job-orders): " . ($user->hasPermissionTo('view-job-orders') ? '✓' : '✗') . "\n";
    echo "  - Products (view-products): " . ($user->hasPermissionTo('view-products') ? '✓' : '✗') . "\n";
    echo "  - Shoe Pricing (view-pricing): " . ($user->hasPermissionTo('view-pricing') ? '✓' : '✗') . "\n";
    echo "  - Inventory Overview (Staff role): " . ($user->hasRole('Staff') ? '✓' : '✗') . "\n";
    
    // Check employee status
    if ($user->employee) {
        echo "\nEmployee Status: {$user->employee->status}\n";
    } else {
        echo "\nNo employee record found\n";
    }
    
    echo "\n" . str_repeat('-', 60) . "\n\n";
}
