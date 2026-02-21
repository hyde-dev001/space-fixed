<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== Table Column Check ===\n\n";

$columns = Schema::getColumnListing('notification_preferences');

echo "Columns in notification_preferences table:\n";
foreach ($columns as $column) {
    echo "  - {$column}\n";
}

echo "\n=== Checking for specific columns ===\n";
$checkColumns = ['shop_owner_id', 'browser_new_orders', 'browser_approvals', 'browser_alerts'];
foreach ($checkColumns as $col) {
    $exists = in_array($col, $columns) ? 'EXISTS' : 'MISSING';
    echo "  {$col}: {$exists}\n";
}
