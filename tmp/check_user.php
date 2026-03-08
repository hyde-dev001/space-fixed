<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Find Robert Martinez
$user = App\Models\User::where('name', 'like', '%Robert%')->orWhere('email', 'like', '%robert%')->first();
if ($user) {
    echo "User: {$user->name} | email: {$user->email} | shop_owner_id: {$user->shop_owner_id}" . PHP_EOL;
    $shop = App\Models\ShopOwner::find($user->shop_owner_id);
    if ($shop) {
        echo "Shop: {$shop->business_name} | geofence_enabled: " . var_export((bool)$shop->attendance_geofence_enabled, true) . PHP_EOL;
        echo "  lat: {$shop->shop_latitude} | lng: {$shop->shop_longitude}" . PHP_EOL;
    }
} else {
    echo "User not found by name. Listing all users with shop_owner_id:" . PHP_EOL;
    $users = App\Models\User::whereNotNull('shop_owner_id')->get(['id','name','email','shop_owner_id']);
    foreach ($users as $u) {
        echo "  ID:{$u->id} | {$u->name} | {$u->email} | shop_owner_id:{$u->shop_owner_id}" . PHP_EOL;
    }
}
