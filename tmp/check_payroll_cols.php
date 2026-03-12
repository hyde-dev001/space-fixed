<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cols = DB::select("SHOW COLUMNS FROM payrolls WHERE Field IN ('payment_date', 'generated_at', 'pay_period_start')");
foreach ($cols as $col) {
    echo $col->Field . ': ' . $col->Type . PHP_EOL;
}
