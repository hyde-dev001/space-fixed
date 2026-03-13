<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = DB::table('users as u')
    ->leftJoin('shop_owners as s', 's.id', '=', 'u.shop_owner_id')
    ->where('u.role', 'FINANCE')
    ->select([
        'u.id as user_id',
        'u.email as user_email',
        'u.shop_owner_id',
        's.email as shop_owner_email',
        's.registration_type',
        's.business_type',
    ])
    ->orderBy('u.id')
    ->get();

foreach ($rows as $r) {
    echo "user_id={$r->user_id} user_email={$r->user_email} shop_owner_id={$r->shop_owner_id} shop_owner_email={$r->shop_owner_email} registration_type={$r->registration_type} business_type={$r->business_type}\n";
}
