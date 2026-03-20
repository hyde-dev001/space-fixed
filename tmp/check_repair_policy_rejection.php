<?php

declare(strict_types=1);

use App\Http\Controllers\ShopOwner\ShopSettingsController;
use App\Models\ShopOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$shopOwner = ShopOwner::query()->first();

if (!$shopOwner) {
    echo json_encode([
        'success' => false,
        'message' => 'NO_SHOP_OWNER',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

Auth::shouldUse('shop_owner');
Auth::guard('shop_owner')->setUser($shopOwner);

$request = Request::create('/shop-owner/settings', 'PUT', [
    'repair_payment_policy' => 'pay_after',
]);

/** @var ShopSettingsController $controller */
$controller = $app->make(ShopSettingsController::class);

try {
    $controller->update($request);

    echo json_encode([
        'success' => false,
        'message' => 'UNEXPECTED_ACCEPTED',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
} catch (ValidationException $e) {
    $errors = $e->errors();

    echo json_encode([
        'success' => isset($errors['repair_payment_policy']),
        'message' => 'REJECTED',
        'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(isset($errors['repair_payment_policy']) ? 0 : 1);
}
