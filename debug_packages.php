<?php
require __DIR__ . '/bootstrap/app.php';

$app = new Illuminate\Container\Container;
$app->bind('config', function() {
    return new \Illuminate\Config\Repository;
});

$packages = \Illuminate\Support\Facades\DB::table('repair_packages')
    ->where('approval_status', '!=', 'none')
    ->get(['id', 'name', 'approval_status', 'shop_owner_id', 'package_price', 'old_package_price']);

echo "Packages with non-'none' approval_status:\n";
echo json_encode($packages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$totalCount = \Illuminate\Support\Facades\DB::table('repair_packages')->count();
echo "\nTotal packages in database: " . $totalCount . "\n";

$pendingFinanceCount = \Illuminate\Support\Facades\DB::table('repair_packages')
    ->where('approval_status', 'pending_finance')
    ->count();
echo "Packages with approval_status='pending_finance': " . $pendingFinanceCount . "\n";
