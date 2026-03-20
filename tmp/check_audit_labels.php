<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityLogController;
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
    echo "No manager user found\n";
    exit(1);
}

Auth::shouldUse('user');
Auth::guard('user')->setUser($manager);

$controller = $app->make(ActivityLogController::class);
$request = Request::create('/api/activity-logs', 'GET', ['per_page' => 10]);
$request->setUserResolver(fn () => $manager);

$response = $controller->index($request);
$data = $response->getData(true);

$rows = $data['logs']['data'] ?? [];
$out = [];
foreach ($rows as $row) {
    $out[] = [
        'event' => $row['event'] ?? null,
        'subject_type' => $row['subject_type'] ?? null,
        'subject_id' => $row['subject_id'] ?? null,
        'subject_label' => $row['subject_label'] ?? null,
        'description' => $row['description'] ?? null,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
