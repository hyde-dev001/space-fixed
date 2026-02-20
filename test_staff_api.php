<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;

echo "Testing /api/staff/notifications endpoints\n";
echo str_repeat("=", 80) . "\n\n";

$user = User::find(122);
if (!$user) {
    die("User 122 not found!\n");
}

echo "User: {$user->name} ({$user->email})\n";
echo "Shop Owner ID: {$user->shop_owner_id}\n\n";

Auth::guard('user')->setUser($user);

// Test recent endpoint
echo "1. Testing /recent endpoint:\n";
echo str_repeat("-", 80) . "\n";

$request = Illuminate\Http\Request::create('/api/staff/notifications/recent', 'GET', ['limit' => 5]);
$request->setUserResolver(function() use ($user) {
    return $user;
});

$controller = new App\Http\Controllers\ErpNotificationController(app(App\Services\NotificationService::class));
$response = $controller->recent($request);
$data = json_decode($response->getContent());

echo "Status: " . $response->getStatusCode() . "\n";
echo "Count: " . count($data) . "\n";
if (count($data) > 0) {
    echo "\nFirst notification:\n";
    echo "  ID: {$data[0]->id}\n";
    echo "  Title: {$data[0]->title}\n";
    echo "  Type: {$data[0]->type}\n";
}

// Test unread count
echo "\n\n2. Testing /unread-count endpoint:\n";
echo str_repeat("-", 80) . "\n";

$request = Illuminate\Http\Request::create('/api/staff/notifications/unread-count', 'GET');
$request->setUserResolver(function() use ($user) {
    return $user;
});

$response = $controller->unreadCount($request);
$data = json_decode($response->getContent());

echo "Status: " . $response->getStatusCode() . "\n";
echo "Response: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";

echo "\n" . str_repeat("=", 80) . "\n";
echo "✓ Test complete!\n";
