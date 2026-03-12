<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$items = App\Models\StockRequestApproval::select('id','request_number','product_name','requested_size','status')->get();
foreach($items as $r){
    echo $r->request_number . ' | size=' . ($r->requested_size ?? 'NULL') . ' | ' . $r->status . PHP_EOL;
}
