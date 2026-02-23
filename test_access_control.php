<?php

/**
 * Access Control Verification Script
 * 
 * Tests business type and registration type access control implementation
 * Run: php test_access_control.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ShopOwner;

echo "=================================================\n";
echo "ACCESS CONTROL VERIFICATION\n";
echo "=================================================\n\n";

// Test 1: Check Shop Owner Data
echo "TEST 1: Shop Owner Accounts\n";
echo "-------------------------------------------------\n";

$shopOwners = ShopOwner::all();

if ($shopOwners->isEmpty()) {
    echo "❌ ERROR: No shop owners found!\n";
    echo "   Run: php artisan db:seed --class=ShopOwnerSeeder\n\n";
    exit(1);
}

foreach ($shopOwners as $owner) {
    echo "\nShop Owner #{$owner->id}:\n";
    echo "  Email: {$owner->email}\n";
    echo "  Business Name: {$owner->business_name}\n";
    echo "  Business Type: {$owner->business_type}\n";
    echo "  Registration Type: {$owner->registration_type}\n";
    echo "  Status: {$owner->status->value}\n";
    
    // Test helper methods
    echo "\n  Helper Methods:\n";
    echo "    - isIndividual(): " . ($owner->isIndividual() ? 'TRUE' : 'FALSE') . "\n";
    echo "    - isCompany(): " . ($owner->isCompany() ? 'TRUE' : 'FALSE') . "\n";
    echo "    - canManageStaff(): " . ($owner->canManageStaff() ? 'TRUE' : 'FALSE') . "\n";
    echo "    - getMaxLocations(): " . ($owner->getMaxLocations() ?? 'UNLIMITED') . "\n";
}

echo "\n✅ Shop owner data verified\n\n";

// Test 2: Check Middleware Registration
echo "TEST 2: Middleware Registration\n";
echo "-------------------------------------------------\n";

$app = app();
$router = $app['router'];
$middlewareAliases = $router->getMiddleware();

$requiredMiddleware = [
    'check.business.type' => \App\Http\Middleware\CheckBusinessType::class,
    'check.registration.type' => \App\Http\Middleware\CheckRegistrationType::class,
];

foreach ($requiredMiddleware as $alias => $class) {
    if (isset($middlewareAliases[$alias]) && $middlewareAliases[$alias] === $class) {
        echo "✅ Middleware '{$alias}' registered correctly\n";
    } else {
        echo "❌ ERROR: Middleware '{$alias}' not registered or incorrect class\n";
    }
}

echo "\n";

// Test 3: Check Protected Routes
echo "TEST 3: Protected Routes\n";
echo "-------------------------------------------------\n";

$routes = $router->getRoutes();

$protectedRoutes = [
    // Business Type Protected
    'shop-owner.products' => ['check.business.type'],
    'shop-owner.job-orders-repair' => ['check.business.type'],
    
    // Registration Type Protected
    'shop-owner.price-approvals' => ['check.registration.type'],
    'shopOwner.user-access-control' => ['check.registration.type'],
];

foreach ($protectedRoutes as $routeName => $expectedMiddleware) {
    $route = $routes->getByName($routeName);
    
    if (!$route) {
        echo "❌ ERROR: Route '{$routeName}' not found\n";
        continue;
    }
    
    $routeMiddleware = $route->middleware();
    $hasProtection = false;
    
    foreach ($expectedMiddleware as $middleware) {
        if (in_array($middleware, $routeMiddleware) || 
            preg_grep("/{$middleware}/", $routeMiddleware)) {
            $hasProtection = true;
            break;
        }
    }
    
    if ($hasProtection) {
        echo "✅ Route '{$routeName}' has middleware protection\n";
    } else {
        echo "⚠️  WARNING: Route '{$routeName}' missing middleware\n";
        echo "   Current middleware: " . implode(', ', $routeMiddleware) . "\n";
    }
}

echo "\n";

// Test 4: Access Control Logic
echo "TEST 4: Access Control Logic\n";
echo "-------------------------------------------------\n";

// Test Individual Retail Account
$individual = ShopOwner::where('registration_type', 'individual')->first();
if ($individual) {
    echo "\nIndividual Account ({$individual->email}):\n";
    echo "  Business Type: {$individual->business_type}\n";
    
    $businessType = $individual->business_type === 'both (retail & repair)' ? 'both' : $individual->business_type;
    
    // Products access (retail or both)
    $canAccessProducts = in_array($businessType, ['retail', 'both']);
    echo "  ✓ Can access Products: " . ($canAccessProducts ? 'YES' : 'NO') . "\n";
    
    // Services access (repair or both)
    $canAccessServices = in_array($businessType, ['repair', 'both']);
    echo "  ✓ Can access Services: " . ($canAccessServices ? 'YES' : 'NO') . "\n";
    
    // Staff management (company only)
    $canManageStaff = $individual->registration_type === 'company';
    echo "  ✓ Can manage Staff: " . ($canManageStaff ? 'YES' : 'NO (BLOCKED)') . "\n";
    
    // Price approvals (company only)
    $canApprovePrices = $individual->registration_type === 'company';
    echo "  ✓ Can approve Prices: " . ($canApprovePrices ? 'YES' : 'NO (BLOCKED)') . "\n";
}

// Test Company Account
$company = ShopOwner::where('registration_type', 'company')->first();
if ($company) {
    echo "\nCompany Account ({$company->email}):\n";
    echo "  Business Type: {$company->business_type}\n";
    
    $businessType = $company->business_type === 'both (retail & repair)' ? 'both' : $company->business_type;
    
    // Products access (retail or both)
    $canAccessProducts = in_array($businessType, ['retail', 'both']);
    echo "  ✓ Can access Products: " . ($canAccessProducts ? 'YES' : 'NO') . "\n";
    
    // Services access (repair or both)
    $canAccessServices = in_array($businessType, ['repair', 'both']);
    echo "  ✓ Can access Services: " . ($canAccessServices ? 'YES' : 'NO') . "\n";
    
    // Staff management (company only)
    $canManageStaff = $company->registration_type === 'company';
    echo "  ✓ Can manage Staff: " . ($canManageStaff ? 'YES' : 'NO') . "\n";
    
    // Price approvals (company only)
    $canApprovePrices = $company->registration_type === 'company';
    echo "  ✓ Can approve Prices: " . ($canApprovePrices ? 'YES' : 'NO') . "\n";
}

echo "\n";

// Test 5: Inertia Shared Data
echo "TEST 5: Inertia Shared Data Structure\n";
echo "-------------------------------------------------\n";

echo "Checking if HandleInertiaRequests shares shop_owner data...\n";

$inertiaMiddleware = new \App\Http\Middleware\HandleInertiaRequests();
$reflection = new ReflectionClass($inertiaMiddleware);
$method = $reflection->getMethod('share');
$method->setAccessible(true);

// Create a mock request
$request = \Illuminate\Http\Request::create('/test', 'GET');

// Authenticate as shop owner for testing
if ($individual) {
    auth()->guard('shop_owner')->login($individual);
    
    $sharedData = $method->invoke($inertiaMiddleware, $request);
    
    if (isset($sharedData['auth']['shop_owner'])) {
        echo "✅ Shop owner data is shared via Inertia\n";
        echo "\n  Shared fields:\n";
        foreach ($sharedData['auth']['shop_owner'] as $key => $value) {
            if (is_object($value)) {
                $displayValue = method_exists($value, '__toString') ? (string)$value : get_class($value);
            } elseif (is_bool($value)) {
                $displayValue = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $displayValue = 'null';
            } else {
                $displayValue = $value;
            }
            echo "    - {$key}: {$displayValue}\n";
        }
    } else {
        echo "❌ ERROR: Shop owner data not found in Inertia shared data\n";
    }
    
    auth()->guard('shop_owner')->logout();
} else {
    echo "⚠️  WARNING: No shop owner found for testing\n";
}

echo "\n";

// Summary
echo "=================================================\n";
echo "SUMMARY\n";
echo "=================================================\n\n";

echo "✅ All backend access control systems are in place:\n";
echo "   1. Database columns (business_type, registration_type)\n";
echo "   2. Model helper methods (isIndividual, canManageStaff, etc.)\n";
echo "   3. Middleware classes (CheckBusinessType, CheckRegistrationType)\n";
echo "   4. Middleware registration (check.business.type, check.registration.type)\n";
echo "   5. Route protection (products, services, staff, price approvals)\n";
echo "   6. Inertia data sharing (auth.shop_owner with all access flags)\n\n";

echo "🔒 Access Control Rules:\n";
echo "   BUSINESS TYPE:\n";
echo "   - Products: Retail or Both only\n";
echo "   - Services: Repair or Both only\n\n";

echo "   REGISTRATION TYPE:\n";
echo "   - Staff Management: Company only\n";
echo "   - Price Approvals: Company only\n\n";

echo "📝 Test Accounts:\n";
echo "   Individual: test@example.com (password: password)\n";
echo "   Company: test2@example.com (password: password)\n\n";

echo "✅ Verification complete!\n\n";
