<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Checking shop_owners table...\n\n";

// Check if table exists
if (!Schema::hasTable('shop_owners')) {
    echo "❌ Table 'shop_owners' does not exist!\n";
    exit(1);
}

echo "✅ Table 'shop_owners' exists\n\n";

// Get column names
$columns = Schema::getColumnListing('shop_owners');

echo "Columns in shop_owners table:\n";
foreach ($columns as $column) {
    $hasColumn = Schema::hasColumn('shop_owners', $column);
    echo ($hasColumn ? "  ✓ " : "  ✗ ") . $column . "\n";
}

// Check specific columns
echo "\n\nChecking key columns:\n";
$keyColumns = ['business_type', 'registration_type', 'status', 'email', 'business_name'];

foreach ($keyColumns as $column) {
    $exists = Schema::hasColumn('shop_owners', $column);
    echo "  " . ($exists ? "✅" : "❌") . " {$column}: " . ($exists ? "EXISTS" : "MISSING") . "\n";
}

// Count records
$count = DB::table('shop_owners')->count();
echo "\n\nTotal shop owners: {$count}\n";

if ($count > 0) {
    echo "\nSample record:\n";
    $sample = DB::table('shop_owners')->first();
    echo "  - Business Name: {$sample->business_name}\n";
    echo "  - Business Type: {$sample->business_type}\n";
    echo "  - Registration Type: {$sample->registration_type}\n";
    echo "  - Status: {$sample->status}\n";
}

echo "\n✅ Check complete!\n";
