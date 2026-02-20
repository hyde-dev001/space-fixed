<?php

/**
 * PHASE 2 TEST SCRIPT
 * Tests the unified NotificationService with all notification types
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Capsule\Manager as Capsule;

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
echo "{$blue}║         PHASE 2: Notification Service Test Suite            ║{$reset}\n";
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

// Get Service
$notificationService = app(\App\Services\NotificationService::class);

// Get Test Data
$testUser = \App\Models\User::whereNull('shop_owner_id')->first();
$testShopOwner = \App\Models\ShopOwner::first();
$testEmployee = \App\Models\Employee::first();

if (!$testUser) {
    echo "{$red}ERROR: No test customer user found in database{$reset}\n";
    exit(1);
}

if (!$testShopOwner) {
    echo "{$red}ERROR: No test shop owner found in database{$reset}\n";
    exit(1);
}

echo "{$blue}Test Users Found:{$reset}\n";
echo "  Customer ID: {$testUser->id} ({$testUser->first_name} {$testUser->last_name})\n";
echo "  Shop Owner ID: {$testShopOwner->id} ({$testShopOwner->owner_name})\n";
if ($testEmployee) {
    echo "  Employee ID: {$testEmployee->user_id}\n";
}
echo "\n";

// ==================== CUSTOMER NOTIFICATION TESTS ====================

echo "{$blue}Testing Customer Notifications:{$reset}\n";

test('Send Order Placed notification', function() use ($notificationService, $testUser) {
    $notification = $notificationService->notifyOrderPlaced($testUser->id, [
        'order_number' => 'TEST-001',
        'total' => 1500.00
    ]);
    return $notification !== null && $notification->type->value === 'order_placed';
});

test('Send Order Confirmed notification', function() use ($notificationService, $testUser) {
    $notification = $notificationService->notifyOrderConfirmed($testUser->id, [
        'order_number' => 'TEST-001',
        'estimated_delivery' => '2024-03-01'
    ]);
    return $notification !== null && $notification->type->value === 'order_confirmed';
});

test('Send Repair Status Update notification', function() use ($notificationService, $testUser) {
    $notification = $notificationService->notifyRepairStatusUpdate($testUser->id, [
        'order_number' => 'REP-001',
        'status' => 'In Progress'
    ]);
    return $notification !== null && $notification->type->value === 'repair_status_update';
});

test('Send Payment Received notification', function() use ($notificationService, $testUser) {
    $notification = $notificationService->notifyPaymentReceived($testUser->id, [
        'amount' => 1500.00,
        'action_url' => '/my-orders/123'
    ]);
    return $notification !== null && $notification->type->value === 'payment_received';
});

test('Send Message Received notification', function() use ($notificationService, $testUser) {
    $notification = $notificationService->notifyMessageReceived($testUser->id, [
        'sender_name' => 'Admin',
        'action_url' => '/messages/123'
    ]);
    return $notification !== null && $notification->type->value === 'message_received';
});

echo "\n";

// ==================== SHOP OWNER NOTIFICATION TESTS ====================

echo "{$blue}Testing Shop Owner Notifications:{$reset}\n";

test('Send New Order notification (Shop Owner)', function() use ($notificationService, $testShopOwner) {
    $notification = $notificationService->notifyNewOrder($testShopOwner->id, [
        'order_number' => 'ORD-001',
        'total' => 2500.00
    ]);
    return $notification !== null && $notification->shop_owner_id === $testShopOwner->id;
});

test('Send Price Change Request notification', function() use ($notificationService, $testShopOwner) {
    $notification = $notificationService->notifyPriceChangeRequest($testShopOwner->id, [
        'product_name' => 'Test Product',
        'old_price' => 100.00,
        'new_price' => 150.00
    ]);
    return $notification !== null && $notification->type->value === 'price_change_request';
});

test('Send Low Stock Alert notification', function() use ($notificationService, $testShopOwner) {
    $notification = $notificationService->notifyLowStockAlert($testShopOwner->id, [
        'product_name' => 'Test Product',
        'quantity' => 5
    ]);
    return $notification !== null && $notification->type->value === 'low_stock_alert';
});

test('Send Customer Message notification (Shop Owner)', function() use ($notificationService, $testShopOwner) {
    $notification = $notificationService->notifyCustomerMessage($testShopOwner->id, [
        'customer_name' => 'John Doe'
    ]);
    return $notification !== null && $notification->type->value === 'customer_message';
});

echo "\n";

// ==================== ERP NOTIFICATION TESTS ====================

if ($testEmployee) {
    echo "{$blue}Testing ERP Staff Notifications:{$reset}\n";

    test('Send Task Assigned notification', function() use ($notificationService, $testEmployee, $testShopOwner) {
        $notification = $notificationService->notifyTaskAssigned($testEmployee->user_id, [
            'task_name' => 'Review Documents'
        ], $testShopOwner->id);
        return $notification !== null && $notification->type->value === 'task_assigned';
    });

    test('Send Expense Approval notification', function() use ($notificationService, $testEmployee, $testShopOwner) {
        $notification = $notificationService->notifyExpenseApproval($testEmployee->user_id, [
            'amount' => 500.00,
            'description' => 'Office supplies'
        ], $testShopOwner->id);
        return $notification !== null && $notification->type->value === 'expense_approval';
    });

    test('Send Leave Approval notification', function() use ($notificationService, $testEmployee, $testShopOwner) {
        $notification = $notificationService->notifyLeaveApproval($testEmployee->user_id, [
            'employee_name' => 'Test Employee',
            'start_date' => '2024-03-01',
            'end_date' => '2024-03-05'
        ], $testShopOwner->id);
        return $notification !== null && $notification->type->value === 'leave_approval';
    });

    echo "\n";
}

// ==================== UTILITY METHOD TESTS ====================

echo "{$blue}Testing Utility Methods:{$reset}\n";

test('Get unread count for customer', function() use ($notificationService, $testUser) {
    $count = $notificationService->getUnreadCount($testUser->id, false);
    return is_int($count) && $count >= 0;
});

test('Get unread count for shop owner', function() use ($notificationService, $testShopOwner) {
    $count = $notificationService->getUnreadCount($testShopOwner->id, true);
    return is_int($count) && $count >= 0;
});

test('Get recent notifications for customer', function() use ($notificationService, $testUser) {
    $recent = $notificationService->getRecent($testUser->id, 5, false);
    return $recent !== null && $recent->count() >= 0;
});

test('Get recent notifications for shop owner', function() use ($notificationService, $testShopOwner) {
    $recent = $notificationService->getRecent($testShopOwner->id, 5, true);
    return $recent !== null && $recent->count() >= 0;
});

test('Mark notification as read (customer)', function() use ($notificationService, $testUser) {
    $notification = \App\Models\Notification::where('user_id', $testUser->id)->first();
    if (!$notification) return true; // No notifications to test
    
    $result = $notificationService->markAsRead($notification->id, $testUser->id, false);
    return $result === true;
});

test('Mark all as read (customer)', function() use ($notificationService, $testUser) {
    $count = $notificationService->markAllAsRead($testUser->id, false);
    return is_int($count) && $count >= 0;
});

echo "\n";

// ==================== PREFERENCE CHECKING TESTS ====================

echo "{$blue}Testing Notification Preferences:{$reset}\n";

test('Notification preferences created for customer', function() use ($testUser) {
    $prefs = \App\Models\NotificationPreference::getOrCreateForUser($testUser->id);
    return $prefs !== null && $prefs->user_id === $testUser->id;
});

test('Browser notifications enabled by default', function() use ($testUser) {
    $prefs = \App\Models\NotificationPreference::getOrCreateForUser($testUser->id);
    return $prefs->browser_order_updates === true;
});

test('Email notifications disabled by default', function() use ($testUser) {
    $prefs = \App\Models\NotificationPreference::getOrCreateForUser($testUser->id);
    return $prefs->email_order_updates === false;
});

echo "\n";

// ==================== NOTIFICATION TYPE ENUM TESTS ====================

echo "{$blue}Testing NotificationType Enum:{$reset}\n";

test('All 45 notification types exist', function() {
    $types = \App\Enums\NotificationType::cases();
    return count($types) === 45;
});

test('Customer notification types identified correctly', function() {
    $orderPlaced = \App\Enums\NotificationType::ORDER_PLACED;
    return $orderPlaced->isCustomerNotification() === true;
});

test('Shop owner notification types identified correctly', function() {
    $newOrder = \App\Enums\NotificationType::NEW_ORDER;
    return $newOrder->isShopOwnerNotification() === true;
});

test('Notification types have correct categories', function() {
    $orderPlaced = \App\Enums\NotificationType::ORDER_PLACED;
    return $orderPlaced->category() === 'orders';
});

test('Action-required notifications identified', function() {
    $expenseApproval = \App\Enums\NotificationType::EXPENSE_APPROVAL;
    return $expenseApproval->requiresAction() === true;
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
    echo "{$green}Phase 2 implementation is complete and working correctly.{$reset}\n\n";
    exit(0);
} else {
    echo "{$yellow}⚠ Some tests failed. Please review the implementation.{$reset}\n\n";
    exit(1);
}
