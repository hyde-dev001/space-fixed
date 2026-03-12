<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Check tables
$tables = DB::select("SHOW TABLES LIKE 'hr_%'");
echo "HR tables:\n";
foreach ($tables as $t) {
    $vals = (array)$t;
    echo '  ' . array_values($vals)[0] . "\n";
}

// Check tax brackets
$brackets = DB::table('hr_tax_brackets')->where('shop_owner_id', 1)->count();
echo "\nTax brackets for shop 1: $brackets\n";
