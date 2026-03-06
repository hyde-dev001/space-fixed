<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Show POs
$pos = App\Models\PurchaseOrder::select('id','po_number','requested_size','pr_id')->get();
foreach ($pos as $po) {
    echo $po->po_number . ' | requested_size=' . ($po->requested_size ?? 'NULL') . ' | pr_id=' . ($po->pr_id ?? 'NULL') . PHP_EOL;
}

echo PHP_EOL;

// Backfill POs from their linked PRs
$updated = DB::update("
    UPDATE purchase_orders po
    INNER JOIN purchase_requests pr ON pr.id = po.pr_id
    SET po.requested_size = pr.requested_size
    WHERE po.requested_size IS NULL
      AND pr.requested_size IS NOT NULL
      AND po.deleted_at IS NULL
");

echo "Backfilled requested_size on {$updated} purchase order(s)." . PHP_EOL;

// Show updated POs
$pos = App\Models\PurchaseOrder::select('id','po_number','requested_size','pr_id')->get();
foreach ($pos as $po) {
    echo $po->po_number . ' | requested_size=' . ($po->requested_size ?? 'NULL') . PHP_EOL;
}
