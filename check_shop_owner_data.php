<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ShopOwner;

echo "=== SHOP OWNERS DATA CHECK ===\n\n";

$shopOwners = ShopOwner::all();

if ($shopOwners->isEmpty()) {
    echo "❌ No shop owners found in database!\n";
    echo "Need to create test shop owners.\n\n";
} else {
    echo "✅ Found " . $shopOwners->count() . " shop owner(s)\n\n";
    
    foreach ($shopOwners as $owner) {
        echo "-----------------------------------\n";
        echo "ID: " . $owner->id . "\n";
        echo "Business Name: " . $owner->business_name . "\n";
        echo "Email: " . $owner->email . "\n";
        echo "Business Type: " . ($owner->business_type ?? 'NOT SET') . "\n";
        echo "Registration Type: " . ($owner->registration_type ?? 'NOT SET') . "\n";
        echo "Status: " . $owner->status->value . "\n";
        echo "Is Individual: " . ($owner->isIndividual() ? 'Yes' : 'No') . "\n";
        echo "Is Company: " . ($owner->isCompany() ? 'Yes' : 'No') . "\n";
        echo "Can Manage Staff: " . ($owner->canManageStaff() ? 'Yes' : 'No') . "\n";
        echo "Max Locations: " . ($owner->getMaxLocations() ?? 'Unlimited') . "\n";
        echo "\n";
    }
}

// Check if columns exist
echo "=== DATABASE COLUMNS CHECK ===\n\n";
$columns = \DB::select('DESCRIBE shop_owners');
$requiredColumns = ['business_type', 'registration_type'];

foreach ($requiredColumns as $required) {
    $exists = collect($columns)->contains('Field', $required);
    echo ($exists ? '✅' : '❌') . " Column '$required' " . ($exists ? 'exists' : 'MISSING') . "\n";
}

echo "\n=== CHECK COMPLETE ===\n";
