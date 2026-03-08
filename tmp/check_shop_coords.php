<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$shops = App\Models\ShopOwner::get(['id','business_name','shop_latitude','shop_longitude']);
foreach ($shops as $s) {
    echo $s->id . ' | ' . $s->business_name . ' | lat=' . $s->shop_latitude . ' | lng=' . $s->shop_longitude . PHP_EOL;
}
echo 'Total: ' . count($shops) . PHP_EOL;
$withCoords = $shops->filter(fn($s) => $s->shop_latitude && $s->shop_longitude);
echo 'With coords: ' . count($withCoords) . PHP_EOL;
