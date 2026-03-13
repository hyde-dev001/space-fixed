<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Get customer
$c = DB::table('users')->where('email', 'miguel.rosa@example.com')->first();
echo "Customer: " . $c->name . " (id=" . $c->id . ")\n";

// Get existing order
$oid = DB::table('orders')->where('customer_id', $c->id)->value('id');
echo "Order ID: " . ($oid ?? 'NULL') . "\n";

// Check existing repair
$existingRepair = DB::table('repair_requests')->where('request_id', 'CRM-TEST-REP-001')->first();
if (!$existingRepair) {
    // Check valid status enum values
    $cols = DB::select("SHOW COLUMNS FROM repair_requests WHERE Field = 'status'");
    echo "Repair status enum: " . $cols[0]->Type . "\n";

    DB::table('repair_requests')->insert([
        'request_id'        => 'CRM-TEST-REP-001',
        'customer_name'     => $c->name,
        'email'             => $c->email,
        'phone'             => $c->phone ?? '09000000001',
        'shoe_type'         => 'Nike Air Max 270',
        'brand'             => 'Nike',
        'description'       => 'Loose sole needs regluing',
        'shop_owner_id'     => 1,
        'user_id'           => $c->id,
        'assignment_method' => 'auto',
        'delivery_method'   => 'walk_in',
        'total'             => 1200.00,
        'status'            => 'received',
        'created_at'        => Carbon::now()->subDay(),
        'updated_at'        => Carbon::now()->subDay(),
    ]);
    echo "Repair inserted.\n";
} else {
    echo "Repair already exists.\n";
}

// Check existing review
$existingReview = DB::table('customer_reviews')
    ->where('customer_id', $c->id)
    ->where('shop_owner_id', 1)
    ->first();

if (!$existingReview) {
    // Check column names
    $cols = DB::select("SHOW COLUMNS FROM customer_reviews");
    $colNames = array_map(fn($col) => $col->Field, $cols);
    echo "customer_reviews columns: " . implode(', ', $colNames) . "\n";

    DB::table('customer_reviews')->insert([
        'customer_id'     => $c->id,
        'shop_owner_id'   => 1,
        'order_id'        => $oid,
        'order_type'      => 'product',
        'service_type'    => 'Order Support',
        'rating'          => 4,
        'comment'         => 'Good service, delivery needed follow-up.',
        'response_status' => 'pending',
        'staff_response'  => null,
        'responded_at'    => null,
        'created_at'      => Carbon::now()->subHours(12),
        'updated_at'      => Carbon::now()->subHours(12),
    ]);
    echo "Review inserted.\n";
} else {
    echo "Review already exists. ID=" . $existingReview->id . "\n";
}

// Final counts
$repairs = DB::table('repair_requests')->where('shop_owner_id', 1)->count();
$reviews = DB::table('customer_reviews')->where('shop_owner_id', 1)->count();
$repairId = DB::table('repair_requests')->where('request_id', 'CRM-TEST-REP-001')->value('id');
$reviewId = DB::table('customer_reviews')->where('customer_id', $c->id)->value('id');
$convId   = DB::table('conversations')->where('shop_owner_id', 1)->value('id');
$custId   = $c->id;

echo "\n=== CRM TEST DATA SUMMARY ===\n";
echo "Customer ID:     $custId\n";
echo "Order ID:        " . ($oid ?? 'NULL') . "\n";
echo "Repair ID:       " . ($repairId ?? 'NULL') . "\n";
echo "Review ID:       " . ($reviewId ?? 'NULL') . "\n";
echo "Conversation ID: " . ($convId ?? 'NULL') . "\n";
echo "Total repairs (shop 1): $repairs\n";
echo "Total reviews (shop 1): $reviews\n";
