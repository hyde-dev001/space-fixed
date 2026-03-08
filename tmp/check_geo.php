<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$shops = App\Models\ShopOwner::select('id','business_name','attendance_geofence_enabled','shop_latitude','shop_longitude','shop_geofence_radius','shop_address')->get();
foreach ($shops as $shop) {
    echo "ID: {$shop->id} | {$shop->business_name}" . PHP_EOL;
    echo "  geofence_enabled : " . var_export((bool)$shop->attendance_geofence_enabled, true) . PHP_EOL;
    echo "  shop_latitude    : " . var_export($shop->shop_latitude, true) . PHP_EOL;
    echo "  shop_longitude   : " . var_export($shop->shop_longitude, true) . PHP_EOL;
    echo "  geofence_radius  : " . var_export($shop->shop_geofence_radius, true) . PHP_EOL;
    echo "  shop_address     : " . var_export($shop->shop_address, true) . PHP_EOL;
    echo PHP_EOL;
}
