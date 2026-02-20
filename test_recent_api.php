<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;

echo "Testing /api/hr/notifications/recent endpoint\n";
echo str_repeat("=", 80) . "\n\n";

// Simulate authenticated user
$user = User::find(122);
Auth::guard('user')->setUser($user);

// Create request
$request = Illuminate\Http\Request::create('/api/hr/notifications/recent', 'GET', ['limit' => 5]);
$request->setUserResolver(function() use ($user) {
    return $user;
});

// Call controller
$controller = new App\Http\Controllers\Erp\HR\NotificationController();
$response = $controller->recent($request);

echo "Response Status: " . $response->getStatusCode() . "\n";
echo "Response Data:\n";
echo json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT) . "\n";
