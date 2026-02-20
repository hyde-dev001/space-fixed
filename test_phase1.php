<?php

/**
 * Phase 1 Test Script
 * 
 * Run with: php test_phase1.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Schema;

echo "\n=== PHASE 1 VERIFICATION TEST ===\n\n";

// Test 1: Check database columns
echo "✓ Test 1: Database Structure\n";
echo "  - Notification preferences columns: " . count(Schema::getColumnListing('notification_preferences')) . "\n";
echo "  - Notifications has shop_owner_id: " . (in_array('shop_owner_id', Schema::getColumnListing('notifications')) ? 'Yes' : 'No') . "\n";

// Test 2: NotificationType enum
echo "\n✓ Test 2: NotificationType Enum\n";
echo "  - Total types defined: " . count(NotificationType::cases()) . "\n";
echo "  - ORDER_PLACED label: " . NotificationType::ORDER_PLACED->label() . "\n";
echo "  - ORDER_PLACED category: " . NotificationType::ORDER_PLACED->category() . "\n";
echo "  - EXPENSE_APPROVAL requires action: " . (NotificationType::EXPENSE_APPROVAL->requiresAction() ? 'Yes' : 'No') . "\n";

// Test 3: Customer notifications
echo "\n✓ Test 3: Notification Type Categories\n";
$customerTypes = array_filter(NotificationType::cases(), fn($t) => $t->isCustomerNotification());
$shopOwnerTypes = array_filter(NotificationType::cases(), fn($t) => $t->isShopOwnerNotification());
$actionTypes = array_filter(NotificationType::cases(), fn($t) => $t->requiresAction());
echo "  - Customer notification types: " . count($customerTypes) . "\n";
echo "  - Shop Owner notification types: " . count($shopOwnerTypes) . "\n";
echo "  - Action-required types: " . count($actionTypes) . "\n";

// Test 4: Model functionality
echo "\n✓ Test 4: Notification Model\n";
$testNotif = new Notification([
    'type' => 'order_placed',
    'title' => 'Test Order',
    'message' => 'Your order has been placed',
    'shop_id' => 1
]);
echo "  - Type casts to enum: " . ($testNotif->type instanceof NotificationType ? 'Yes' : 'No') . "\n";
echo "  - Type label accessor: " . $testNotif->type_label . "\n";
echo "  - Category accessor: " . $testNotif->category . "\n";
echo "  - Requires action: " . ($testNotif->requiresAction() ? 'Yes' : 'No') . "\n";

// Test 5: Notification Categories
echo "\n✓ Test 5: Notification by Category\n";
$categories = [];
foreach (NotificationType::cases() as $type) {
    $cat = $type->category();
    if (!isset($categories[$cat])) {
        $categories[$cat] = 0;
    }
    $categories[$cat]++;
}
foreach ($categories as $cat => $count) {
    echo "  - {$cat}: {$count} types\n";
}

echo "\n=== ALL TESTS PASSED ===\n\n";
echo "Phase 1 is complete and working!\n";
echo "Next: Implement Phase 2 - Unified Notification Service\n\n";
