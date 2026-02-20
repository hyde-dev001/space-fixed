<?php

/**
 * PHASE 3 TEST SCRIPT
 * Tests API endpoints for all notification controllers
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Facade;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Facade::setFacadeApplication($app);

// Test Colors
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$blue = "\033[34m";
$reset = "\033[0m";

echo "{$blue}╔══════════════════════════════════════════════════════════════╗{$reset}\n";
echo "{$blue}║        PHASE 3: API Controllers & Routes Test Suite         ║{$reset}\n";
echo "{$blue}╚══════════════════════════════════════════════════════════════╝{$reset}\n\n";

$passedTests = 0;
$failedTests = 0;

function test($description, $callback) {
    global $passedTests, $failedTests, $green, $red, $yellow, $reset;
    
    echo "{$yellow}Testing:{$reset} $description ... ";
    
    try {
        $result = $callback();
        if ($result) {
            echo "{$green}✓ PASSED{$reset}\n";
            $passedTests++;
        } else {
            echo "{$red}✗ FAILED{$reset}\n";
            $failedTests++;
        }
    } catch (\Exception $e) {
        echo "{$red}✗ FAILED{$reset}\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failedTests++;
    }
}

// ==================== SETUP ====================

// Get test users
$customer = \App\Models\User::whereNull('shop_owner_id')->first();
$shopOwner = \App\Models\ShopOwner::first();
$employee = \App\Models\User::whereNotNull('shop_owner_id')->first();

if (!$customer) {
    echo "{$red}ERROR: No test customer found{$reset}\n";
    exit(1);
}

if (!$shopOwner) {
    echo "{$red}ERROR: No test shop owner found{$reset}\n";
    exit(1);
}

echo "{$blue}Test Users:{$reset}\n";
echo "  Customer ID: {$customer->id}\n";
echo "  Shop Owner ID: {$shopOwner->id}\n";
if ($employee) {
    echo "  Employee ID: {$employee->id}\n";
}
echo "\n";

// Create test notifications for each user type
$notificationService = app(\App\Services\NotificationService::class);

// Customer notifications
$customerNotif1 = $notificationService->notifyOrderPlaced($customer->id, [
    'order_number' => 'TEST-001',
    'total' => 1500.00
]);

$customerNotif2 = $notificationService->notifyPaymentReceived($customer->id, [
    'amount' => 1500.00
]);

// Shop owner notifications
$shopOwnerNotif1 = $notificationService->notifyNewOrder($shopOwner->id, [
    'order_number' => 'ORD-001',
    'total' => 2500.00
]);

$shopOwnerNotif2 = $notificationService->notifyLowStockAlert($shopOwner->id, [
    'product_name' => 'Test Product',
    'quantity' => 5
]);

// Employee notifications (if employee exists)
if ($employee) {
    $employeeNotif = $notificationService->notifyTaskAssigned($employee->id, [
        'task_name' => 'Test Task'
    ], $employee->shop_owner_id);
}

echo "{$blue}Created test notifications{$reset}\n\n";

// ==================== CUSTOMER CONTROLLER TESTS ====================

echo "{$blue}Testing Customer Notification Controller:{$reset}\n";

test('Customer Controller exists', function() {
    return class_exists(\App\Http\Controllers\NotificationController::class);
});

test('Customer can get notification list', function() use ($customer) {
    $controller = app(\App\Http\Controllers\NotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/notifications', 'GET');
    $request->setUserResolver(function() use ($customer) {
        return $customer;
    });
    
    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true);
    
    return isset($data['data']) && is_array($data['data']);
});

test('Customer can get unread count', function() use ($customer) {
    $controller = app(\App\Http\Controllers\NotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/notifications/unread-count', 'GET');
    $request->setUserResolver(function() use ($customer) {
        return $customer;
    });
    
    $response = $controller->unreadCount($request);
    $data = json_decode($response->getContent(), true);
    
    return isset($data['count']) && is_numeric($data['count']);
});

test('Customer can get recent notifications', function() use ($customer) {
    $controller = app(\App\Http\Controllers\NotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/notifications/recent', 'GET');
    $request->setUserResolver(function() use ($customer) {
        return $customer;
    });
    
    $response = $controller->recent($request);
    $data = json_decode($response->getContent(), true);
    
    return is_array($data);
});

test('Customer can mark notification as read', function() use ($customer, $customerNotif1) {
    if (!$customerNotif1) return false;
    
    $controller = app(\App\Http\Controllers\NotificationController::class);
    $request = \Illuminate\Http\Request::create("/api/notifications/{$customerNotif1->id}/read", 'POST');
    $request->setUserResolver(function() use ($customer) {
        return $customer;
    });
    
    $response = $controller->markAsRead($request, $customerNotif1->id);
    $data = json_decode($response->getContent(), true);
    
    return $data['success'] === true;
});

test('Customer can mark all as read', function() use ($customer) {
    $controller = app(\App\Http\Controllers\NotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/notifications/mark-all-read', 'POST');
    $request->setUserResolver(function() use ($customer) {
        return $customer;
    });
    
    $response = $controller->markAllAsRead($request);
    $data = json_decode($response->getContent(), true);
    
    return $data['success'] === true;
});

test('Customer can get notification stats', function() use ($customer) {
    $controller = app(\App\Http\Controllers\NotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/notifications/stats', 'GET');
    $request->setUserResolver(function() use ($customer) {
        return $customer;
    });
    
    $response = $controller->stats($request);
    $data = json_decode($response->getContent(), true);
    
    return isset($data['total']) && isset($data['unread']);
});

echo "\n";

// ==================== SHOP OWNER CONTROLLER TESTS ====================

echo "{$blue}Testing Shop Owner Notification Controller:{$reset}\n";

test('Shop Owner Controller exists', function() {
    return class_exists(\App\Http\Controllers\ShopOwnerNotificationController::class);
});

test('Shop Owner can get notification list', function() use ($shopOwner) {
    $controller = app(\App\Http\Controllers\ShopOwnerNotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/shop-owner/notifications', 'GET');
    $request->setUserResolver(function() use ($shopOwner) {
        return $shopOwner;
    });
    
    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true);
    
    return isset($data['data']) && is_array($data['data']);
});

test('Shop Owner can get unread count', function() use ($shopOwner) {
    $controller = app(\App\Http\Controllers\ShopOwnerNotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/shop-owner/notifications/unread-count', 'GET');
    $request->setUserResolver(function() use ($shopOwner) {
        return $shopOwner;
    });
    
    $response = $controller->unreadCount($request);
    $data = json_decode($response->getContent(), true);
    
    return isset($data['count']) && is_numeric($data['count']);
});

test('Shop Owner can mark notification as read', function() use ($shopOwner, $shopOwnerNotif1) {
    if (!$shopOwnerNotif1) return false;
    
    $controller = app(\App\Http\Controllers\ShopOwnerNotificationController::class);
    $request = \Illuminate\Http\Request::create("/api/shop-owner/notifications/{$shopOwnerNotif1->id}/read", 'POST');
    $request->setUserResolver(function() use ($shopOwner) {
        return $shopOwner;
    });
    
    $response = $controller->markAsRead($request, $shopOwnerNotif1->id);
    $data = json_decode($response->getContent(), true);
    
    return $data['success'] === true;
});

test('Shop Owner can get notification stats', function() use ($shopOwner) {
    $controller = app(\App\Http\Controllers\ShopOwnerNotificationController::class);
    $request = \Illuminate\Http\Request::create('/api/shop-owner/notifications/stats', 'GET');
    $request->setUserResolver(function() use ($shopOwner) {
        return $shopOwner;
    });
    
    $response = $controller->stats($request);
    $data = json_decode($response->getContent(), true);
    
    return isset($data['total']) && isset($data['unread']);
});

echo "\n";

// ==================== ERP CONTROLLER TESTS ====================

if ($employee) {
    echo "{$blue}Testing ERP Notification Controller:{$reset}\n";

    test('ERP Controller exists', function() {
        return class_exists(\App\Http\Controllers\ErpNotificationController::class);
    });

    test('ERP user can get notification list', function() use ($employee) {
        $controller = app(\App\Http\Controllers\ErpNotificationController::class);
        $request = \Illuminate\Http\Request::create('/api/hr/notifications', 'GET');
        $request->setUserResolver(function() use ($employee) {
            return $employee;
        });
        
        $response = $controller->index($request);
        $data = json_decode($response->getContent(), true);
        
        return isset($data['data']) && is_array($data['data']);
    });

    test('ERP user can get unread count', function() use ($employee) {
        $controller = app(\App\Http\Controllers\ErpNotificationController::class);
        $request = \Illuminate\Http\Request::create('/api/hr/notifications/unread-count', 'GET');
        $request->setUserResolver(function() use ($employee) {
            return $employee;
        });
        
        $response = $controller->unreadCount($request);
        $data = json_decode($response->getContent(), true);
        
        return isset($data['count']) && is_numeric($data['count']);
    });

    echo "\n";
}

// ==================== ROUTE REGISTRATION TESTS ====================

echo "{$blue}Testing Route Registration:{$reset}\n";

test('Customer notification routes registered', function() {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn($route) => $route->uri());
    return $routes->contains('api/notifications') && 
           $routes->contains('api/notifications/unread-count') &&
           $routes->contains('api/notifications/recent');
});

test('Shop Owner notification routes registered', function() {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn($route) => $route->uri());
    return $routes->contains('api/shop-owner/notifications') && 
           $routes->contains('api/shop-owner/notifications/unread-count');
});

test('ERP notification routes registered', function() {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn($route) => $route->uri());
    return $routes->contains('api/hr/notifications') && 
           $routes->contains('api/hr/notifications/unread-count');
});

echo "\n";

// ==================== FINAL RESULTS ====================

echo "{$blue}╔══════════════════════════════════════════════════════════════╗{$reset}\n";
echo "{$blue}║                      TEST RESULTS                            ║{$reset}\n";
echo "{$blue}╚══════════════════════════════════════════════════════════════╝{$reset}\n\n";

$total = $passedTests + $failedTests;
$percentage = $total > 0 ? round(($passedTests / $total) * 100, 2) : 0;

echo "Total Tests: {$total}\n";
echo "{$green}Passed: {$passedTests}{$reset}\n";
echo "{$red}Failed: {$failedTests}{$reset}\n";
echo "Success Rate: {$percentage}%\n\n";

if ($failedTests === 0) {
    echo "{$green}✓ ALL TESTS PASSED!{$reset}\n";
    echo "{$green}Phase 3 implementation is complete and working correctly.{$reset}\n\n";
    exit(0);
} else {
    echo "{$yellow}⚠ Some tests failed. Please review the implementation.{$reset}\n\n";
    exit(1);
}
