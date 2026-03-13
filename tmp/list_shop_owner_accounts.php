<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = DB::table('shop_owners')
    ->select(['id','email','business_name','registration_type','business_type','status'])
    ->orderBy('id')
    ->get();

foreach ($rows as $r) {
    echo "id={$r->id} email={$r->email} business_name={$r->business_name} registration_type={$r->registration_type} business_type={$r->business_type} status={$r->status}\n";
}
