<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RepairRequest;

echo "Checking repair requests with 'paid' status...\n\n";

$repairs = RepairRequest::where('payment_status', 'paid')->get();

echo "Found {$repairs->count()} repair(s) with status 'paid'\n\n";

foreach ($repairs as $repair) {
    echo "Updating {$repair->request_id}:\n";
    echo "  Old status: {$repair->payment_status}\n";
    
    $repair->update(['payment_status' => 'completed']);
    
    echo "  New status: completed\n";
    echo "  Payment completed at: {$repair->payment_completed_at}\n\n";
}

echo "Done! Updated {$repairs->count()} repair request(s).\n";
