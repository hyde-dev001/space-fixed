<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RepairReview;
use App\Models\ProductReview;

echo 'product_reviews=' . ProductReview::count() . PHP_EOL;
echo 'repair_reviews=' . RepairReview::count() . PHP_EOL;

$lastRepair = RepairReview::query()->latest('id')->first();
if ($lastRepair) {
  echo 'last_repair_review_id=' . $lastRepair->id . PHP_EOL;
  echo 'last_repair_review_shop_owner_id=' . $lastRepair->shop_owner_id . PHP_EOL;
  echo 'last_repair_review_is_visible=' . (int) $lastRepair->is_visible . PHP_EOL;
  echo 'last_repair_review_created_at=' . $lastRepair->created_at . PHP_EOL;
}
