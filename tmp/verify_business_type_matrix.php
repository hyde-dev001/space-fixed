<?php

declare(strict_types=1);

use App\Http\Middleware\CheckUserBusinessType;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Http\Request;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$router = $app->make('router');
$middleware = $app->make(CheckUserBusinessType::class);

$routesToTest = [
    ['method' => 'GET', 'uri' => '/erp/staff/job-orders', 'label' => 'Retail Job Orders'],
    ['method' => 'GET', 'uri' => '/erp/staff/products', 'label' => 'Retail Products'],
    ['method' => 'GET', 'uri' => '/erp/staff/shoe-pricing', 'label' => 'Retail Shoe Pricing'],

    ['method' => 'GET', 'uri' => '/erp/staff/repair-dashboard', 'label' => 'Repair Dashboard'],
    ['method' => 'GET', 'uri' => '/erp/staff/job-orders-repair', 'label' => 'Repair Job Orders'],
    ['method' => 'GET', 'uri' => '/erp/staff/upload-services', 'label' => 'Repair Upload Services'],
    ['method' => 'GET', 'uri' => '/erp/staff/stocks-overview', 'label' => 'Repair Stocks Overview'],
    ['method' => 'GET', 'uri' => '/erp/staff/request-material', 'label' => 'Repair Request Material'],
    ['method' => 'GET', 'uri' => '/erp/staff/repair-status', 'label' => 'Repair Status'],
    ['method' => 'GET', 'uri' => '/erp/staff/repairer-support', 'label' => 'Repair Support'],
    ['method' => 'GET', 'uri' => '/erp/repairer/pricing-and-services', 'label' => 'Repair Pricing and Services'],

    ['method' => 'GET', 'uri' => '/api/repairer/materials', 'label' => 'API Repair Materials'],
    ['method' => 'GET', 'uri' => '/api/repairer/material-requests', 'label' => 'API Repair Material Requests'],
    ['method' => 'POST', 'uri' => '/api/repairer/material-requests', 'label' => 'API Create Repair Material Request'],
    ['method' => 'GET', 'uri' => '/api/repairer/repairs/1/materials', 'label' => 'API Repair Usage List'],
    ['method' => 'POST', 'uri' => '/api/repairer/repairs/1/materials', 'label' => 'API Repair Usage Log'],
    ['method' => 'DELETE', 'uri' => '/api/repairer/repairs/1/materials/1', 'label' => 'API Repair Usage Delete'],
    ['method' => 'GET', 'uri' => '/api/workflow/repairs/1/status', 'label' => 'API Workflow Repair Status'],
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
        if (str_starts_with($mw, 'check.user.business.type:')) {
            $businessTypeMiddleware = $mw;
            break;
        }
    }

    if ($businessTypeMiddleware === null) {
        $summary['route_middleware_missing'][] = $routeDef['label'] . ' (' . $routeDef['method'] . ' ' . $routeDef['uri'] . ') - missing check.user.business.type';
        continue;
    }

    $summary['routes_with_business_middleware']++;

    $allowedTypes = explode(',', substr($businessTypeMiddleware, strlen('check.user.business.type:')));
    $allowedTypes = array_values(array_filter(array_map(fn(string $v) => trim(strtolower($v)), $allowedTypes)));

    foreach ($businessTypes as $key => $storedBusinessType) {
        $summary['matrix_cases']++;

        $user = new User();
        $user->id = 9999;
        $user->shop_owner_id = 9999;

        $shopOwner = new ShopOwner();
        $shopOwner->id = 9999;
        $shopOwner->business_type = $storedBusinessType;
        $user->setRelation('shopOwner', $shopOwner);

        $request = Request::create($routeDef['uri'], $routeDef['method'], [], [], [], $headers);
        $request->setUserResolver(fn (?string $guard = null) => $guard === null || $guard === 'user' ? $user : null);

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

$sep = str_repeat('-', 160);
echo "\nTARGETED BUSINESS-TYPE MATRIX\n";
echo $sep . "\n";
printf("%-36s %-7s %-10s %-18s %-8s %-8s %-6s\n", 'Route', 'Method', 'Type', 'Allowed', 'Expect', 'Actual', 'Res');
echo $sep . "\n";

foreach ($rows as $row) {
    printf(
        "%-36s %-7s %-10s %-18s %-8s %-8s %-6s\n",
        substr($row['route'], 0, 36),
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
