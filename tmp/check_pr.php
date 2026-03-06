<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$prs = App\Models\PurchaseRequest::select('id','pr_number','requested_size','inventory_item_id')->get();
foreach ($prs as $pr) {
    echo $pr->pr_number . ' | requested_size=' . ($pr->requested_size ?? 'NULL') . ' | inv_item_id=' . ($pr->inventory_item_id ?? 'NULL') . PHP_EOL;
}
