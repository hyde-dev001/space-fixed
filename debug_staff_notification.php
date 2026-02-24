<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

echo "=== Debug Staff Notification Query ===\n\n";

$shopOwnerId = 2; // From the log

echo "Shop Owner ID: {$shopOwnerId}\n\n";

// Run the exact query from notifyAllStaffNewOrder
$staffMembers = User::where('shop_owner_id', $shopOwnerId)
    ->whereHas('employee', function($query) {
        $query->where('status', 'active');
    })
    ->whereHas('roles', function($query) {
        $query->where('name', 'Staff');
    })
    ->where('status', 'active')
    ->whereHas('permissions', function($query) {
        $query->where('name', 'view-job-orders');
    })
    ->get();

echo "Staff members found: " . $staffMembers->count() . "\n\n";

if ($staffMembers->count() === 0) {
    echo "Debugging why no staff found...\n\n";
    
    // Check step by step
    echo "Step 1: Users with shop_owner_id = {$shopOwnerId}\n";
    $step1 = User::where('shop_owner_id', $shopOwnerId)->get();
    echo "Found: " . $step1->count() . " users\n";
    foreach ($step1 as $u) {
        echo "  - {$u->name} (ID: {$u->id}, Status: {$u->status})\n";
    }
    echo "\n";
    
    echo "Step 2: + has employee record with status = active\n";
    $step2 = User::where('shop_owner_id', $shopOwnerId)
        ->whereHas('employee', function($query) {
            $query->where('status', 'active');
        })
        ->get();
    echo "Found: " . $step2->count() . " users\n";
    foreach ($step2 as $u) {
        echo "  - {$u->name} (ID: {$u->id})\n";
        if ($u->employee) {
            echo "    Employee status: {$u->employee->status->value}\n";
        }
    }
    echo "\n";
    
    echo "Step 3: + has Staff role\n";
    $step3 = User::where('shop_owner_id', $shopOwnerId)
        ->whereHas('employee', function($query) {
            $query->where('status', 'active');
        })
        ->whereHas('roles', function($query) {
            $query->where('name', 'Staff');
        })
        ->get();
    echo "Found: " . $step3->count() . " users\n";
    foreach ($step3 as $u) {
        echo "  - {$u->name} (ID: {$u->id})\n";
        echo "    Roles: " . $u->roles->pluck('name')->implode(', ') . "\n";
    }
    echo "\n";
    
    echo "Step 4: + status = active\n";
    $step4 = User::where('shop_owner_id', $shopOwnerId)
        ->whereHas('employee', function($query) {
            $query->where('status', 'active');
        })
        ->whereHas('roles', function($query) {
            $query->where('name', 'Staff');
        })
        ->where('status', 'active')
        ->get();
    echo "Found: " . $step4->count() . " users\n";
    foreach ($step4 as $u) {
        echo "  - {$u->name} (ID: {$u->id}, User Status: {$u->status})\n";
    }
    echo "\n";
    
    echo "Step 5: + has view-job-orders permission\n";
    $step5 = User::where('shop_owner_id', $shopOwnerId)
        ->whereHas('employee', function($query) {
            $query->where('status', 'active');
        })
        ->whereHas('roles', function($query) {
            $query->where('name', 'Staff');
        })
        ->where('status', 'active')
        ->whereHas('permissions', function($query) {
            $query->where('name', 'view-job-orders');
        })
        ->get();
    echo "Found: " . $step5->count() . " users\n";
    foreach ($step5 as $u) {
        echo "  - {$u->name} (ID: {$u->id})\n";
        echo "    Has view-job-orders: " . ($u->hasPermissionTo('view-job-orders') ? 'YES' : 'NO') . "\n";
        echo "    All permissions: " . $u->getAllPermissions()->pluck('name')->implode(', ') . "\n";
    }
}
