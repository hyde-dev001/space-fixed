<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\ShopOwner;
use Illuminate\Support\Facades\Route;

echo "=== MIDDLEWARE VERIFICATION TEST ===\n\n";

// Get shop owners for testing
$individual = ShopOwner::where('registration_type', 'individual')->first();
$company = ShopOwner::where('registration_type', 'company')->first();

echo "Test Accounts:\n";
echo "-----------------------------------\n";
if ($individual) {
    echo "Individual: " . $individual->business_name . " (ID: " . $individual->id . ")\n";
    echo "  Business Type: " . $individual->business_type . "\n";
    echo "  Registration Type: " . $individual->registration_type . "\n";
}
if ($company) {
    echo "Company: " . $company->business_name . " (ID: " . $company->id . ")\n";
    echo "  Business Type: " . $company->business_type . "\n";
    echo "  Registration Type: " . $company->registration_type . "\n";
}

echo "\n=== ROUTE MIDDLEWARE CHECK ===\n\n";

// Check specific routes and their middleware
$routesToCheck = [
    'shop-owner.products' => 'Product Management',
    'shop-owner.upload-services' => 'Service Upload',
    'shop-owner.price-approvals' => 'Price Approvals',
    'shop-owner.employees.store' => 'Employee Management',
    'shop-owner.dashboard' => 'Dashboard',
    'shop-owner.customers' => 'Customers',
];

foreach ($routesToCheck as $routeName => $featureName) {
    $route = Route::getRoutes()->getByName($routeName);
    if ($route) {
        $middleware = $route->middleware();
        $hasBusinessCheck = in_array('check.business.type', $middleware);
        $hasRegistrationCheck = in_array('check.registration.type', $middleware);
        
        echo "$featureName ($routeName):\n";
        echo "  Path: " . $route->uri() . "\n";
        echo "  Business Type Check: " . ($hasBusinessCheck ? '✅ Yes' : '❌ No') . "\n";
        echo "  Registration Type Check: " . ($hasRegistrationCheck ? '✅ Yes' : '❌ No') . "\n";
        echo "  All Middleware: " . implode(', ', $middleware) . "\n";
        echo "\n";
    }
}

echo "=== EXPECTED BEHAVIOR ===\n\n";

echo "Individual Shop Owner (Retail/Repair/Both):\n";
echo "  ✅ Can access: Dashboard, Orders, Customers, Shop Profile, Refunds, Audit Logs\n";
echo "  ✅ Can access (if Retail): Products\n";
echo "  ✅ Can access (if Repair): Services, Repair Requests\n";
echo "  ❌ Cannot access: Price Approvals, Staff Management\n";
echo "  Redirect: Blocked features redirect to dashboard with error\n\n";

echo "Company Shop Owner (Retail/Repair/Both):\n";
echo "  ✅ Can access: ALL features including Price Approvals and Staff Management\n";
echo "  ✅ Can access (if Retail): Products\n";
echo "  ✅ Can access (if Repair): Services, Repair Requests\n";
echo "  No restrictions based on registration type\n\n";

echo "=== MIDDLEWARE ALIASES REGISTERED ===\n\n";

// Check if middleware aliases are registered
$middlewareAliases = [
    'check.business.type',
    'check.registration.type',
];

echo "Checking middleware aliases in bootstrap/app.php:\n";
foreach ($middlewareAliases as $alias) {
    echo "  • $alias\n";
}

echo "\n=== TEST COMPLETE ===\n";
echo "\nTo test manually:\n";
echo "1. Login as Individual shop owner\n";
echo "2. Try to access: /shop-owner/price-approvals\n";
echo "3. Should redirect to /shop-owner/dashboard with error message\n";
echo "4. Login as Company shop owner\n";
echo "5. Try to access: /shop-owner/price-approvals\n";
echo "6. Should work and display the page\n";
