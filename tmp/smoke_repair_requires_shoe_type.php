<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RepairRequestController;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $service = RepairService::query()
        ->where('status', 'active')
        ->whereNotNull('shop_owner_id')
        ->orderBy('id')
        ->first();

    if (!$service) {
        throw new RuntimeException('No active repair service found.');
    }

    $shopOwner = ShopOwner::query()->find($service->shop_owner_id);
    if (!$shopOwner) {
        throw new RuntimeException('Shop owner not found.');
    }

    $customer = User::query()
        ->whereNull('shop_owner_id')
        ->whereNotNull('email')
        ->orderBy('id')
        ->first();

    if (!$customer) {
        throw new RuntimeException('No customer-like user found.');
    }

    $imagePath = public_path('images/product/product-01.jpg');
    if (!is_file($imagePath)) {
        $imagePath = public_path('images/shop/shop.jpg');
    }
    if (!is_file($imagePath)) {
        throw new RuntimeException('No image file available for upload validation.');
    }

    $uploadedFile = new UploadedFile(
        $imagePath,
        basename($imagePath),
        mime_content_type($imagePath) ?: 'image/jpeg',
        null,
        true
    );

    Auth::shouldUse('user');
    Auth::guard('user')->setUser($customer);

    /** @var RepairRequestController $controller */
    $controller = $app->make(RepairRequestController::class);

    $payload = [
        'customer_name' => $customer->name ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: 'Smoke Customer',
        'email' => $customer->email,
        'phone' => $customer->phone_number ?? '09170000000',
        // intentionally missing shoe_type
        'brand' => 'Smoke Brand',
        'description' => 'Negative smoke test: missing shoe type',
        'shop_owner_id' => (string) $shopOwner->id,
        'services' => [(string) $service->id],
        'total' => (string) ((float) $service->price),
        'service_type' => 'walkin',
        'return_delivery_method' => 'walk_in',
    ];

    $request = Request::create('/api/repair-requests', 'POST', $payload);
    $request->files->set('images', [$uploadedFile]);

    $response = $controller->store($request);
    $status = $response->getStatusCode();
    $data = $response->getData(true);

    $result = [
        'success' => $status === 422 && isset($data['errors']['shoe_type']),
        'http_status' => $status,
        'has_shoe_type_error' => isset($data['errors']['shoe_type']),
        'shoe_type_error' => $data['errors']['shoe_type'][0] ?? null,
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if (!$result['success']) {
        exit(1);
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
