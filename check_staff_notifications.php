<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;
use App\Models\User;

echo "=== Check Staff Notifications ===\n\n";

$staffUser = User::find(134);
echo "Staff User: {$staffUser->name} (ID: {$staffUser->id})\n";
echo "Shop Owner ID: {$staffUser->shop_owner_id}\n\n";

$notifications = Notification::where('user_id', 134)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

echo "Total notifications: " . $notifications->count() . "\n\n";

foreach ($notifications as $notif) {
    echo "Notification ID: {$notif->id}\n";
    echo "  Type: {$notif->type->value}\n";
    echo "  Title: {$notif->title}\n";
    echo "  Message: {$notif->message}\n";
    echo "  Shop ID: " . ($notif->shop_id ?? 'NULL') . "\n";
    echo "  Is Read: " . ($notif->is_read ? 'YES' : 'NO') . "\n";
    echo "  Created: {$notif->created_at}\n";
    echo "  ---\n";
}

echo "\n";
echo "Checking query used by ErpNotificationController:\n";
$erpNotifications = Notification::where('user_id', 134)
    ->where('shop_id', $staffUser->shop_owner_id)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

echo "Found by ERP controller query: " . $erpNotifications->count() . "\n";
