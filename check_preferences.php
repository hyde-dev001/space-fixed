<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\NotificationPreference;

$pref = NotificationPreference::where('shop_owner_id', 1)->first();

if ($pref) {
    echo "Preferences for Shop Owner 1:\n";
    echo "- browser_new_orders: " . ($pref->browser_new_orders ? 'enabled' : 'disabled') . "\n";
    echo "- browser_approvals: " . ($pref->browser_approvals ? 'enabled' : 'disabled') . "\n";
    echo "- browser_alerts: " . ($pref->browser_alerts ? 'enabled' : 'disabled') . "\n";
    echo "- email_new_orders: " . ($pref->email_new_orders ? 'enabled' : 'disabled') . "\n";
    echo "- email_approvals: " . ($pref->email_approvals ? 'enabled' : 'disabled') . "\n";
    echo "- email_alerts: " . ($pref->email_alerts ? 'enabled' : 'disabled') . "\n";
} else {
    echo "No preferences found for shop owner 1\n";
}
