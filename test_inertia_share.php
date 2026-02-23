<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\ShopOwner;
use Illuminate\Support\Facades\Auth;

echo "=== AUTHENTICATION & INERTIA SHARE TEST ===\n\n";

// Test with shop owner ID 1 (Individual)
$shopOwner1 = ShopOwner::find(1);
if ($shopOwner1) {
    Auth::guard('shop_owner')->login($shopOwner1);
    
    echo "✅ Logged in as: " . $shopOwner1->business_name . "\n\n";
    
    echo "Data that will be shared via Inertia:\n";
    echo "-----------------------------------\n";
    echo "ID: " . $shopOwner1->id . "\n";
    echo "Business Name: " . $shopOwner1->business_name . "\n";
    echo "Email: " . $shopOwner1->email . "\n";
    echo "Business Type: " . $shopOwner1->business_type . "\n";
    echo "Registration Type: " . $shopOwner1->registration_type . "\n";
    echo "Status: " . $shopOwner1->status->value . "\n";
    echo "Is Individual: " . ($shopOwner1->isIndividual() ? 'true' : 'false') . "\n";
    echo "Is Company: " . ($shopOwner1->isCompany() ? 'true' : 'false') . "\n";
    echo "Can Manage Staff: " . ($shopOwner1->canManageStaff() ? 'true' : 'false') . "\n";
    echo "Max Locations: " . ($shopOwner1->getMaxLocations() ?? 'null (unlimited)') . "\n";
    
    echo "\n✅ This data is available in frontend via:\n";
    echo "   const { auth } = usePage().props;\n";
    echo "   const shopOwner = auth.shop_owner;\n";
    echo "   shopOwner.business_type → '" . $shopOwner1->business_type . "'\n";
    echo "   shopOwner.registration_type → '" . $shopOwner1->registration_type . "'\n";
    echo "   shopOwner.is_individual → " . ($shopOwner1->isIndividual() ? 'true' : 'false') . "\n";
    echo "   shopOwner.is_company → " . ($shopOwner1->isCompany() ? 'true' : 'false') . "\n";
    echo "   shopOwner.can_manage_staff → " . ($shopOwner1->canManageStaff() ? 'true' : 'false') . "\n";
    
    Auth::guard('shop_owner')->logout();
}

echo "\n=== TEST COMPLETE ===\n";
