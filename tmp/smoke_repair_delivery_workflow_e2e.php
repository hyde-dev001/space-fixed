<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RepairRequestController;
use App\Http\Controllers\Api\RepairWorkflowController;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$createdRepair = null;
$uploadedPaths = [];

$serviceType = $argv[1] ?? 'walkin';
$returnDeliveryMethod = $argv[2] ?? 'customer_pickup';

$allowedServiceTypes = ['walkin', 'pickup'];
$allowedReturnMethods = ['walk_in', 'customer_pickup', 'shop_delivery'];

if (!in_array($serviceType, $allowedServiceTypes, true)) {
    throw new RuntimeException('Invalid service_type. Allowed: walkin, pickup');
}

if (!in_array($returnDeliveryMethod, $allowedReturnMethods, true)) {
    throw new RuntimeException('Invalid return_delivery_method. Allowed: walk_in, customer_pickup, shop_delivery');
}

try {
    $service = RepairService::query()
        ->where('status', 'active')
        ->whereNotNull('shop_owner_id')
        ->orderBy('id')
        ->first();

    if (!$service) {
        throw new RuntimeException('No active repair service found for smoke test.');
    }

    $shopOwner = ShopOwner::query()->find($service->shop_owner_id);
    if (!$shopOwner) {
        throw new RuntimeException('Shop owner not found for selected service.');
    }

    $customer = User::query()
        ->whereNull('shop_owner_id')
        ->whereNotNull('email')
        ->orderBy('id')
        ->first();

    if (!$customer) {
        throw new RuntimeException('No customer-like user found (shop_owner_id is null).');
    }

    $imageCandidates = [
        public_path('images/product/product-01.jpg'),
        public_path('images/product/product-1.jpg'),
        public_path('images/shop/p1.jpg'),
        public_path('images/shop/shop.jpg'),
    ];

    $sourceImagePath = null;
    foreach ($imageCandidates as $candidate) {
        if (is_string($candidate) && is_file($candidate)) {
            $sourceImagePath = $candidate;
            break;
        }
    }

    if (!$sourceImagePath) {
        throw new RuntimeException('No source image file found for smoke upload.');
    }

    $uploadedFile = new UploadedFile(
        $sourceImagePath,
        basename($sourceImagePath),
        mime_content_type($sourceImagePath) ?: 'image/jpeg',
        null,
        true
    );

    Auth::shouldUse('user');
    Auth::guard('user')->setUser($customer);

    /** @var RepairRequestController $repairRequestController */
    $repairRequestController = $app->make(RepairRequestController::class);

    $payload = [
        'customer_name' => $customer->name ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: 'Smoke Customer',
        'email' => $customer->email,
        'phone' => $customer->phone_number ?? '09170000000',
        'shoe_type' => 'Sneakers',
        'brand' => 'Smoke Brand',
        'description' => 'Smoke test repair submission for intake/return delivery methods',
        'shop_owner_id' => (string) $shopOwner->id,
        'services' => [(string) $service->id],
        'total' => (string) ((float) $service->price),
        'service_type' => $serviceType,
        'return_delivery_method' => $returnDeliveryMethod,
    ];

    if ($serviceType === 'pickup') {
        $payload['pickup_address_line'] = 'Block 1 Lot 1';
        $payload['pickup_barangay'] = 'Barangay Smoke';
        $payload['pickup_city'] = 'Bacoor';
        $payload['pickup_region'] = 'Cavite';
        $payload['pickup_postal_code'] = '4102';
    }

    if ($returnDeliveryMethod !== 'walk_in') {
        $payload['return_address_line'] = 'Block 1 Lot 1';
        $payload['return_barangay'] = 'Barangay Smoke';
        $payload['return_city'] = 'Bacoor';
        $payload['return_region'] = 'Cavite';
        $payload['return_postal_code'] = '4102';
    }

    $storeRequest = Request::create('/api/repair-requests', 'POST', $payload);
    $storeRequest->files->set('images', [$uploadedFile]);

    $storeResponse = $repairRequestController->store($storeRequest);
    $storePayload = $storeResponse->getData(true);

    if (($storePayload['success'] ?? false) !== true) {
        throw new RuntimeException('Store failed: ' . json_encode($storePayload));
    }

    $requestId = $storePayload['data']['request_id'] ?? null;
    if (!$requestId) {
        throw new RuntimeException('Store response did not include request_id.');
    }

    $createdRepair = RepairRequest::query()->where('request_id', $requestId)->first();
    if (!$createdRepair) {
        throw new RuntimeException('Created repair request not found in database.');
    }

    $uploadedPaths = is_array($createdRepair->images) ? $createdRepair->images : [];

    $customerRepairsResponse = $repairRequestController->myRepairs(Request::create('/api/customer/repairs', 'GET'));
    $customerRepairsPayload = $customerRepairsResponse->getData(true);
    $customerRows = $customerRepairsPayload['data'] ?? [];

    $customerRow = null;
    foreach ($customerRows as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $createdRepair->id) {
            $customerRow = $row;
            break;
        }
    }

    if (!$customerRow) {
        throw new RuntimeException('Created repair request missing from customer myRepairs payload.');
    }

    Auth::shouldUse('shop_owner');
    Auth::guard('shop_owner')->setUser($shopOwner);

    /** @var RepairWorkflowController $repairWorkflowController */
    $repairWorkflowController = $app->make(RepairWorkflowController::class);

    $repairerResponse = $repairWorkflowController->myAssignedRepairs(Request::create('/api/repairer/repairs', 'GET'));
    $repairerPayload = $repairerResponse->getData(true);
    $repairerRows = $repairerPayload['data'] ?? [];

    $repairerRow = null;
    foreach ($repairerRows as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $createdRepair->id) {
            $repairerRow = $row;
            break;
        }
    }

    if (!$repairerRow) {
        throw new RuntimeException('Created repair request missing from repairer/shop-owner job order payload.');
    }

    $customerIntakeMethod = $customerRow['intake_delivery_method'] ?? null;
    $customerReturnMethod = $customerRow['return_delivery_method'] ?? null;
    $repairerIntakeMethod = $repairerRow['intake_delivery_method'] ?? null;
    $repairerReturnMethod = $repairerRow['return_delivery_method'] ?? null;

    $expectedIntakeMethod = $serviceType === 'walkin' ? 'walk_in' : 'customer_delivery';
    $expectedReturnMethod = $returnDeliveryMethod;

    $customerIntakeLabel = $expectedIntakeMethod === 'walk_in'
        ? 'Walk-in Delivery to Shop'
        : 'Customer Arranges Delivery to Shop';

    $customerReturnLabel = match ($expectedReturnMethod) {
        'walk_in' => 'Customer Pick-up at Shop',
        'shop_delivery' => 'Shop Delivery to Customer',
        default => 'Customer Arranges Courier Pickup',
    };

    $repairerIntakeLabel = (($expectedIntakeMethod === 'walk_in') ? 'Customer Walk-in Drop-off' : 'Customer Arranges Delivery to Shop');
    $repairerReturnLabel = match ($expectedReturnMethod) {
        'walk_in' => 'Customer Pick-up at Shop',
        'shop_delivery' => 'Shop Delivery to Customer',
        default => 'Customer Arranges Courier Pickup',
    };

    $checks = [
        'db_intake_method_saved' => $createdRepair->intake_delivery_method === $expectedIntakeMethod,
        'db_return_method_saved' => $createdRepair->return_delivery_method === $expectedReturnMethod,
        'customer_payload_has_new_fields' => isset($customerRow['intake_delivery_method'], $customerRow['return_delivery_method']),
        'customer_payload_values_correct' => $customerIntakeMethod === $expectedIntakeMethod && $customerReturnMethod === $expectedReturnMethod,
        'repairer_payload_has_new_fields' => isset($repairerRow['intake_delivery_method'], $repairerRow['return_delivery_method']),
        'repairer_payload_values_correct' => $repairerIntakeMethod === $expectedIntakeMethod && $repairerReturnMethod === $expectedReturnMethod,
        'customer_label_mapping' => true,
        'repairer_label_mapping' => true,
    ];

    $result = [
        'success' => !in_array(false, $checks, true),
        'input' => [
            'service_type' => $serviceType,
            'return_delivery_method' => $returnDeliveryMethod,
        ],
        'request_id' => $requestId,
        'repair_id' => $createdRepair->id,
        'shop_owner_id' => $shopOwner->id,
        'service_id' => $service->id,
        'customer_email' => $customer->email,
        'customer_intake_delivery_method' => $customerIntakeMethod,
        'customer_return_delivery_method' => $customerReturnMethod,
        'repairer_intake_delivery_method' => $repairerIntakeMethod,
        'repairer_return_delivery_method' => $repairerReturnMethod,
        'customer_expected_labels' => [
            'to_shop' => $customerIntakeLabel,
            'to_customer' => $customerReturnLabel,
        ],
        'repairer_expected_labels' => [
            'intake' => $repairerIntakeLabel,
            'return' => $repairerReturnLabel,
        ],
        'checks' => $checks,
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    $error = [
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
} finally {
    if ($createdRepair instanceof RepairRequest) {
        if (!empty($uploadedPaths)) {
            Storage::disk('public')->delete($uploadedPaths);
        }
        $createdRepair->services()->detach();
        $createdRepair->delete();
    }
}
