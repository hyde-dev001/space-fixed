<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Backfill requested_size on purchase_requests from their linked stock_request_approvals
// Match on shop_owner_id + inventory_item_id
$updated = DB::update("
    UPDATE purchase_requests pr
    INNER JOIN stock_request_approvals sra
        ON sra.inventory_item_id = pr.inventory_item_id
        AND sra.shop_owner_id    = pr.shop_owner_id
    SET pr.requested_size = sra.requested_size
    WHERE pr.requested_size IS NULL
      AND sra.requested_size IS NOT NULL
      AND pr.deleted_at IS NULL
");

echo "Backfilled requested_size on {$updated} purchase request(s)." . PHP_EOL;

// Show result
$prs = App\Models\PurchaseRequest::select('id','pr_number','requested_size','inventory_item_id')->get();
foreach ($prs as $pr) {
    echo $pr->pr_number . ' | requested_size=' . ($pr->requested_size ?? 'NULL') . PHP_EOL;
}
