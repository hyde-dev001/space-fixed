<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ShopOwner;
use App\Models\NotificationPreference;
use App\Services\NotificationService;
use App\Enums\NotificationType;

echo "=== Testing Shop Owner Notifications ===\n\n";

// Get first shop owner
$shopOwner = ShopOwner::first();

if (!$shopOwner) {
    echo "No shop owners found in database\n";
    exit(1);
}

echo "Shop Owner: {$shopOwner->business_name} (ID: {$shopOwner->id})\n";

// Check or create preferences
$preferences = NotificationPreference::where('shop_owner_id', $shopOwner->id)->first();

if (!$preferences) {
    echo "No preferences found, creating new one...\n";
} else {
    echo "Existing preferences found (ID: {$preferences->id})\n";
    echo "- browser_new_orders: " . ($preferences->browser_new_orders ? 'enabled' : 'disabled') . "\n";
    echo "- browser_approvals: " . ($preferences->browser_approvals ? 'enabled' : 'disabled') . "\n";
    echo "- browser_alerts: " . ($preferences->browser_alerts ? 'enabled' : 'disabled') . "\n";
}

// Test sending notification
echo "\nTesting notification send...\n";
$notificationService = new NotificationService();

$notification = $notificationService->sendToShopOwner(
    shopOwnerId: $shopOwner->id,
    type: NotificationType::NEW_ORDER,
    title: 'Test Order Notification',
    message: 'This is a test notification to verify shop owner notifications are working',
    data: ['order_number' => 'TEST-001', 'total' => 1000],
    actionUrl: '/shop-owner/orders',
    priority: 'high'
);

if ($notification) {
    echo "✓ Notification created successfully!\n";
    echo "  - ID: {$notification->id}\n";
    echo "  - Type: {$notification->type->value}\n";
    echo "  - Title: {$notification->title}\n";
    echo "  - Shop Owner ID: {$notification->shop_owner_id}\n";
} else {
    echo "✗ Failed to create notification\n";
}

echo "\n=== Test Complete ===\n";
