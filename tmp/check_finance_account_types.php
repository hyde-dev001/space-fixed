<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$row = DB::table('users as u')
    ->leftJoin('shop_owners as s', 's.id', '=', 'u.shop_owner_id')
    ->where('u.email', 'finance.1@solespace.com')
    ->select([
        'u.id as user_id',
        'u.email as user_email',
        'u.role as user_role',
        'u.shop_owner_id',
        's.email as shop_owner_email',
        's.business_type',
        's.registration_type',
    ])
    ->first();

if (! $row) {
    echo "NOT_FOUND\n";
    exit(1);
}

echo "user_id={$row->user_id}\n";
echo "user_email={$row->user_email}\n";
echo "user_role={$row->user_role}\n";
echo "shop_owner_id={$row->shop_owner_id}\n";
echo "shop_owner_email={$row->shop_owner_email}\n";
echo "business_type={$row->business_type}\n";
echo "registration_type={$row->registration_type}\n";
