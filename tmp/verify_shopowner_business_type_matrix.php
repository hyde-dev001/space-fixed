<?php

declare(strict_types=1);

use App\Http\Middleware\CheckBusinessType;
use App\Models\ShopOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$router = $app->make('router');
$middleware = $app->make(CheckBusinessType::class);

$routesToTest = [
    ['method' => 'GET', 'uri' => '/shop-owner/products', 'label' => 'Web Products'],
    ['method' => 'GET', 'uri' => '/shop-owner/product-uploder', 'label' => 'Web Product Uploader'],
    ['method' => 'GET', 'uri' => '/shop-owner/inventory-overview', 'label' => 'Web Inventory Overview'],
    ['method' => 'GET', 'uri' => '/shop-owner/job-orders-retail', 'label' => 'Web Retail Job Orders'],
    ['method' => 'GET', 'uri' => '/shop-owner/customer-support', 'label' => 'Web Customer Support'],

    ['method' => 'GET', 'uri' => '/shop-owner/job-orders-repair', 'label' => 'Web Repair Job Orders'],
    ['method' => 'GET', 'uri' => '/shop-owner/upload-services', 'label' => 'Web Upload Services'],
    ['method' => 'GET', 'uri' => '/shop-owner/repair-support', 'label' => 'Web Repair Support'],

    ['method' => 'GET', 'uri' => '/api/shop-owner/orders', 'label' => 'API Orders'],
    ['method' => 'PATCH', 'uri' => '/api/shop-owner/orders/1/status', 'label' => 'API Order Status Update'],
    ['method' => 'GET', 'uri' => '/api/shop-owner/products', 'label' => 'API Products'],
    ['method' => 'GET', 'uri' => '/api/shop-owner/price-changes/all', 'label' => 'API Price Changes'],

    ['method' => 'GET', 'uri' => '/api/shop-owner/repairs', 'label' => 'API Repairs'],
    ['method' => 'POST', 'uri' => '/api/shop-owner/repairs/1/ship', 'label' => 'API Ship Repair'],
    ['method' => 'GET', 'uri' => '/api/shop-owner/repair-services', 'label' => 'API Repair Services'],
    ['method' => 'GET', 'uri' => '/api/shop-owner/repair-price-changes/all', 'label' => 'API Repair Price Changes'],
];

$businessTypes = [
    'retail' => 'retail',
    'repair' => 'repair',
    'both' => 'both (retail & repair)',
];

$summary = [
    'routes_checked' => 0,
    'routes_with_business_middleware' => 0,
    'matrix_cases' => 0,
    'passed' => 0,
    'failed' => 0,
    'route_middleware_missing' => [],
    'case_failures' => [],
];

$rows = [];

foreach ($routesToTest as $routeDef) {
    $summary['routes_checked']++;

    $isApi = str_starts_with($routeDef['uri'], '/api/');
    $headers = $isApi ? ['HTTP_ACCEPT' => 'application/json'] : ['HTTP_ACCEPT' => 'text/html'];
    $matchRequest = Request::create($routeDef['uri'], $routeDef['method'], [], [], [], $headers);

    try {
        $route = $router->getRoutes()->match($matchRequest);
    } catch (Throwable $e) {
        $summary['route_middleware_missing'][] = $routeDef['label'] . ' (' . $routeDef['method'] . ' ' . $routeDef['uri'] . ') - route not matched: ' . $e->getMessage();
        continue;
    }

    $routeMiddleware = $route->gatherMiddleware();
    $businessTypeMiddleware = null;

    foreach ($routeMiddleware as $mw) {
        if (str_starts_with($mw, 'check.business.type:')) {
            $businessTypeMiddleware = $mw;
            break;
        }
    }

    if ($businessTypeMiddleware === null) {
        $summary['route_middleware_missing'][] = $routeDef['label'] . ' (' . $routeDef['method'] . ' ' . $routeDef['uri'] . ') - missing check.business.type';
        continue;
    }

    $summary['routes_with_business_middleware']++;

    $allowedTypes = explode(',', substr($businessTypeMiddleware, strlen('check.business.type:')));
    $allowedTypes = array_values(array_filter(array_map(fn(string $v) => trim(strtolower($v)), $allowedTypes)));

    foreach ($businessTypes as $key => $storedBusinessType) {
        $summary['matrix_cases']++;

        $shopOwner = new ShopOwner();
        $shopOwner->id = 9999;
        $shopOwner->business_type = $storedBusinessType;

        Auth::guard('shop_owner')->setUser($shopOwner);

        $request = Request::create($routeDef['uri'], $routeDef['method'], [], [], [], $headers);

        $response = $middleware->handle(
            $request,
            fn () => response('OK', 200),
            ...$allowedTypes
        );

        $allowedForThisType = in_array($key, $allowedTypes, true);
        $expectedStatus = $allowedForThisType ? 200 : ($isApi ? 403 : 302);
        $actualStatus = $response->getStatusCode();

        $pass = $expectedStatus === $actualStatus;
        if ($pass) {
            $summary['passed']++;
        } else {
            $summary['failed']++;
            $summary['case_failures'][] = [
                'route' => $routeDef['label'],
                'method' => $routeDef['method'],
                'uri' => $routeDef['uri'],
                'business_type' => $key,
                'allowed_types' => implode(',', $allowedTypes),
                'expected' => $expectedStatus,
                'actual' => $actualStatus,
            ];
        }

        $rows[] = [
            'route' => $routeDef['label'],
            'method' => $routeDef['method'],
            'business_type' => $key,
            'allowed_types' => implode(',', $allowedTypes),
            'expected' => (string) $expectedStatus,
            'actual' => (string) $actualStatus,
            'result' => $pass ? 'PASS' : 'FAIL',
        ];
    }
}

$sep = str_repeat('-', 170);
echo "\nSHOP OWNER BUSINESS-TYPE MATRIX\n";
echo $sep . "\n";
printf("%-38s %-7s %-10s %-18s %-8s %-8s %-6s\n", 'Route', 'Method', 'Type', 'Allowed', 'Expect', 'Actual', 'Res');
echo $sep . "\n";

foreach ($rows as $row) {
    printf(
        "%-38s %-7s %-10s %-18s %-8s %-8s %-6s\n",
        substr($row['route'], 0, 38),
        $row['method'],
        $row['business_type'],
        $row['allowed_types'],
        $row['expected'],
        $row['actual'],
        $row['result']
    );
}

if (!empty($summary['route_middleware_missing'])) {
    echo "\nROUTES MISSING BUSINESS-TYPE MIDDLEWARE OR NOT MATCHED:\n";
    foreach ($summary['route_middleware_missing'] as $missing) {
        echo " - {$missing}\n";
    }
}

if (!empty($summary['case_failures'])) {
    echo "\nFAILED MATRIX CASES:\n";
    foreach ($summary['case_failures'] as $failure) {
        echo sprintf(
            " - %s [%s %s] type=%s allowed=%s expected=%s actual=%s\n",
            $failure['route'],
            $failure['method'],
            $failure['uri'],
            $failure['business_type'],
            $failure['allowed_types'],
            $failure['expected'],
            $failure['actual']
        );
    }
}

echo "\nSUMMARY:\n";
echo " - Routes checked: {$summary['routes_checked']}\n";
echo " - Routes with business middleware: {$summary['routes_with_business_middleware']}\n";
echo " - Matrix cases: {$summary['matrix_cases']}\n";
echo " - Passed: {$summary['passed']}\n";
echo " - Failed: {$summary['failed']}\n";

exit(($summary['failed'] > 0 || !empty($summary['route_middleware_missing'])) ? 1 : 0);
