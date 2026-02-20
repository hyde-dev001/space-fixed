<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

echo "Testing HR Notifications API Logic\n";
echo str_repeat("=", 80) . "\n\n";

$user = User::find(122);
if (!$user) {
    die("User 122 not found!\n");
}

echo "User: {$user->name} ({$user->email})\n";
echo "Shop Owner ID: {$user->shop_owner_id}\n\n";

// Simulate what the controller does
echo "Simulating HR NotificationController::index() logic:\n";
echo str_repeat("-", 80) . "\n";

$query = Notification::where('user_id', $user->id);

// Filter by shop_id if user is ERP staff
if ($user->shop_owner_id) {
    $query->where(function($q) use ($user) {
        $q->where('shop_id', $user->shop_owner_id)
          ->orWhereNull('shop_id');
    });
}

$notifications = $query->orderBy('created_at', 'desc')->limit(10)->get();

echo "Total found: " . $notifications->count() . "\n\n";

foreach ($notifications as $notif) {
    $type = $notif->type instanceof \App\Enums\NotificationType ? $notif->type->value : $notif->type;
    echo "✓ ID: {$notif->id} | Type: {$type}\n";
    echo "  Title: {$notif->title}\n";
    echo "  Shop ID: {$notif->shop_id}\n";
    echo "  Created: {$notif->created_at}\n\n";
}

// Test unread count
$query = Notification::where('user_id', $user->id);
if ($user->shop_owner_id) {
    $query->where(function($q) use ($user) {
        $q->where('shop_id', $user->shop_owner_id)
          ->orWhereNull('shop_id');
    });
}
$count = $query->whereNull('read_at')->count();

echo "\nUnread count: {$count}\n";

echo "\n" . str_repeat("=", 80) . "\n";
echo "✓ API logic test complete!\n";
