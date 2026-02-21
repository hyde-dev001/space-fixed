<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;
use App\Models\NotificationPreference;

echo "=== Notification Check ===\n\n";

// Check all notifications for shop owner 1
$notifications = Notification::where('shop_owner_id', 1)->get();
echo "Total notifications for shop owner 1: " . $notifications->count() . "\n";

foreach ($notifications as $notif) {
    echo "\nNotification ID {$notif->id}:\n";
    echo "  Type: {$notif->type->value}\n";
    echo "  Title: {$notif->title}\n";
    echo "  Created: {$notif->created_at}\n";
}

echo "\n=== Preference Check ===\n\n";
$allPrefs = NotificationPreference::all();
echo "Total preferences in system: " . $allPrefs->count() . "\n";

foreach ($allPrefs as $pref) {
    if ($pref->shop_owner_id) {
        echo "\nShop Owner Preference (ID: {$pref->id}):\n";
        echo "  shop_owner_id: {$pref->shop_owner_id}\n";
        echo "  browser_new_orders: " . ($pref->browser_new_orders ?? 'null') . "\n";
    }
}
