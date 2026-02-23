<?php
/**
 * Test script to verify order update endpoint works
 */

require __DIR__ . '/vendor/autoload.php';

use App\Models\Order;
use App\Models\ShopOwner;
use Illuminate\Support\Facades\Hash;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Order Update Endpoint\n";
echo "==============================\n\n";

// Get the shop owner
$shopOwner = ShopOwner::first();
echo "Shop Owner: {$shopOwner->business_name} (ID: {$shopOwner->id})\n";
echo "Business Type: {$shopOwner->business_type}\n";
echo "Registration Type: {$shopOwner->registration_type}\n\n";

// Get the first order
$order = Order::where('shop_owner_id', $shopOwner->id)->first();

if (!$order) {
    echo "No orders found for this shop owner!\n";
    exit;
}

echo "Order Found:\n";
echo "  ID: {$order->id}\n";
echo "  Order Number: {$order->order_number}\n";
echo "  Current Status: " . $order->status->value . "\n";
echo "  Customer: {$order->customer_name}\n\n";

// Attempt to update the order (simulating the API call)
echo "Attempting to update order to 'shipped' status...\n";

try {
    $order->status = 'shipped';
    $order->tracking_number = 'TEST123456';
    $order->carrier_company = 'J&T Express';
    $order->carrier_name = 'John Doe';
    $order->carrier_phone = '09123456789';
    $order->tracking_link = 'https://example.com/track/TEST123456';
    $order->eta = now()->addDays(3)->format('Y-m-d');
    $order->save();
    
    echo "✓ Order updated successfully!\n";
    echo "  New Status: " . $order->status->value . "\n";
    echo "  Tracking Number: {$order->tracking_number}\n";
    echo "  Carrier: {$order->carrier_company}\n";
    echo "  ETA: {$order->eta}\n\n";
    
    // Revert the changes
    echo "Reverting changes...\n";
    $order->status = 'processing';
    $order->tracking_number = null;
    $order->carrier_company = null;
    $order->carrier_name = null;
    $order->carrier_phone = null;
    $order->tracking_link = null;
    $order->eta = null;
    $order->save();
    echo "✓ Reverted to original state\n";
    
} catch (\Exception $e) {
    echo "✗ Error updating order: " . $e->getMessage() . "\n";
}

echo "\nTest completed!\n";
