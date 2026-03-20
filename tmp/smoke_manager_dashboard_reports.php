<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ManagerController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manager = User::query()
    ->whereIn('role', ['Manager', 'MANAGER'])
    ->whereNotNull('shop_owner_id')
    ->orderBy('id')
    ->first();

if (!$manager) {
    echo json_encode([
        'success' => false,
        'message' => 'No manager user with shop_owner_id found.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

Auth::shouldUse('user');
Auth::guard('user')->setUser($manager);

/** @var ManagerController $controller */
$controller = $app->make(ManagerController::class);

$calls = [
    'dashboard_stats' => [
        'request' => Request::create('/api/manager/dashboard/stats', 'GET'),
        'handler' => fn (Request $request) => $controller->getDashboardStats($request),
    ],
    'inventory_overview' => [
        'request' => Request::create('/api/manager/inventory-overview?per_page=1', 'GET', ['per_page' => 1]),
        'handler' => fn (Request $request) => $controller->getInventoryOverview($request),
    ],
    'reports' => [
        'request' => Request::create('/api/manager/reports', 'GET'),
        'handler' => fn (Request $request) => $controller->getReports($request),
    ],
];

$results = [];

foreach ($calls as $key => $entry) {
    /** @var Request $request */
    $request = $entry['request'];
    $request->setUserResolver(fn () => $manager);

    try {
        $response = $entry['handler']($request);
        $content = $response->getContent();
        $decoded = json_decode($content, true);

        $results[$key] = [
            'http_status' => $response->getStatusCode(),
            'body' => $decoded ?? $content,
        ];
    } catch (Throwable $exception) {
        $results[$key] = [
            'http_status' => 500,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }
}

echo json_encode([
    'success' => true,
    'manager_context' => [
        'id' => $manager->id,
        'email' => $manager->email,
        'role' => $manager->role,
        'shop_owner_id' => $manager->shop_owner_id,
    ],
    'responses' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
