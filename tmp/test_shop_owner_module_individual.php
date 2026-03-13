<?php
/**
 * Focused Shop Owner module test (INDIVIDUAL type).
 * Run: php tmp/test_shop_owner_module_individual.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/shop-owner/login';
$credentials = [
    'email' => 'test@example.com', // individual + both
    'password' => 'password',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'shop_owner_indiv_test_cookies_');
$results = [];
$sectionOpen = '';
$runTag = date('YmdHis');

function section(string $name): void {
    global $sectionOpen;
    $sectionOpen = $name;
    echo "\n╔══════════════════════════════════════════════════════════════\n";
    echo "║  {$name}\n";
    echo "╚══════════════════════════════════════════════════════════════\n";
}

function xsrf_token_from_jar(): ?string {
    global $cookieJar;
    if (!file_exists($cookieJar)) return null;
    $lines = file($cookieJar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, 'XSRF-TOKEN')) {
            $parts = preg_split('/\s+/', $line);
            return end($parts);
        }
    }
    return null;
}

function request(string $method, string $url, mixed $body = null): array {
    global $cookieJar;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = ['Accept: application/json'];
    $xsrf = xsrf_token_from_jar();
    if ($xsrf) {
        $headers[] = 'X-XSRF-TOKEN: ' . urldecode($xsrf);
    }

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $location = null;
    foreach (explode("\r\n", $rawHeaders) as $headerLine) {
        if (stripos($headerLine, 'Location:') === 0) {
            $location = trim(substr($headerLine, 9));
            break;
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'json' => json_decode($body, true),
        'location' => $location,
    ];
}

function check(bool $condition, string $name, string $detail = ''): void {
    global $results, $sectionOpen;
    $label = $condition ? 'PASS' : 'FAIL';
    echo '  [' . $label . "] {$name}";
    if ($detail) echo " → {$detail}";
    echo "\n";
    $results[] = [
        'section' => $sectionOpen,
        'name' => $name,
        'pass' => $condition,
        'detail' => $detail,
    ];
}

section('0. Authentication (individual shop owner)');
request('GET', $baseUrl . '/sanctum/csrf-cookie');
$loginRes = request('POST', $loginUrl, $credentials);
$authProbe = request('GET', $baseUrl . '/api/shop-owner/me');
$isAuthenticated = in_array($loginRes['status'], [200, 302]) || $authProbe['status'] === 200;
check($isAuthenticated, 'Login as test@example.com', 'HTTP ' . $loginRes['status'] . ' (probe /api/shop-owner/me=' . $authProbe['status'] . ')');

section('1. Core Shop Owner pages (should be accessible)');
$corePages = [
    '/shop-owner/dashboard',
    '/shop-owner/products',
    '/shop-owner/inventory-overview',
    '/shop-owner/job-orders-repair',
    '/shop-owner/upload-services',
    '/shop-owner/job-orders-retail',
    '/shop-owner/customers',
    '/shop-owner/customer-support',
    '/shop-owner/repair-support',
    '/shop-owner/customer-reviews',
    '/shop-owner/shop-profile',
    '/shop-owner/settings',
    '/shop-owner/refund-approvals',
    '/shop-owner/dss-insights',
    '/shop-owner/premium-benefits',
];
foreach ($corePages as $page) {
    $res = request('GET', $baseUrl . $page);
    check($res['status'] === 200, 'GET ' . $page, 'HTTP ' . $res['status']);
}

section('2. Company-only pages (individual should be blocked)');
$companyOnlyPages = [
    '/shop-owner/audit-logs',
    '/shop-owner/price-approvals',
    '/shop-owner/purchase-request-approval',
    '/shop-owner/repair-reject-approval',
    '/shop-owner/history-rejection',
    '/shopOwner/user-access-control',
];
foreach ($companyOnlyPages as $page) {
    $res = request('GET', $baseUrl . $page);
    check(in_array($res['status'], [302, 401, 403]), 'BLOCKED ' . $page, 'HTTP ' . $res['status']);
}

section('3. Shop Owner APIs (read)');
$meRes = request('GET', $baseUrl . '/api/shop-owner/me');
check($meRes['status'] === 200, 'GET /api/shop-owner/me', 'HTTP ' . $meRes['status']);

$dashboardStatsRes = request('GET', $baseUrl . '/api/shop-owner/dashboard/stats');
check($dashboardStatsRes['status'] === 200, 'GET /api/shop-owner/dashboard/stats', 'HTTP ' . $dashboardStatsRes['status']);
$dssRes = request('GET', $baseUrl . '/api/shop-owner/dashboard/dss-insights');
check($dssRes['status'] === 200, 'GET /api/shop-owner/dashboard/dss-insights', 'HTTP ' . $dssRes['status']);
$lowStockRes = request('GET', $baseUrl . '/api/shop-owner/dashboard/low-stock');
check($lowStockRes['status'] === 200, 'GET /api/shop-owner/dashboard/low-stock', 'HTTP ' . $lowStockRes['status']);

$ordersRes = request('GET', $baseUrl . '/api/shop-owner/orders');
check($ordersRes['status'] === 200, 'GET /api/shop-owner/orders', 'HTTP ' . $ordersRes['status']);
$customersRes = request('GET', $baseUrl . '/api/shop-owner/customers');
check($customersRes['status'] === 200, 'GET /api/shop-owner/customers', 'HTTP ' . $customersRes['status']);
$conversationsRes = request('GET', $baseUrl . '/api/shop-owner/conversations');
check($conversationsRes['status'] === 200, 'GET /api/shop-owner/conversations', 'HTTP ' . $conversationsRes['status']);
$reviewsRes = request('GET', $baseUrl . '/api/shop-owner/reviews');
check($reviewsRes['status'] === 200, 'GET /api/shop-owner/reviews', 'HTTP ' . $reviewsRes['status']);
$productsRes = request('GET', $baseUrl . '/api/shop-owner/products');
check($productsRes['status'] === 200, 'GET /api/shop-owner/products', 'HTTP ' . $productsRes['status']);
$repairsRes = request('GET', $baseUrl . '/api/shop-owner/repairs');
check($repairsRes['status'] === 200, 'GET /api/shop-owner/repairs', 'HTTP ' . $repairsRes['status']);
$repairServicesRes = request('GET', $baseUrl . '/api/shop-owner/repair-services');
check($repairServicesRes['status'] === 200, 'GET /api/shop-owner/repair-services', 'HTTP ' . $repairServicesRes['status']);
$notificationsRes = request('GET', $baseUrl . '/api/shop-owner/notifications');
check($notificationsRes['status'] === 200, 'GET /api/shop-owner/notifications', 'HTTP ' . $notificationsRes['status']);

section('4. Shop Owner APIs (light write workflow)');
$createServiceRes = request('POST', $baseUrl . '/api/shop-owner/repair-services', [
    'name' => 'SO Individual Workflow Service ' . $runTag,
    'category' => 'Cleaning',
    'price' => 399,
    'duration' => '1-2 days',
    'description' => 'Temporary repair service for shop owner individual workflow test',
    'status' => 'Active',
]);
check($createServiceRes['status'] === 201, 'POST /api/shop-owner/repair-services', 'HTTP ' . $createServiceRes['status']);
$serviceId = $createServiceRes['json']['data']['id'] ?? null;
check(!empty($serviceId), 'Created repair service id resolved', 'id=' . ($serviceId ?? 'none'));

if ($serviceId) {
    $updateServiceRes = request('PUT', $baseUrl . '/api/shop-owner/repair-services/' . $serviceId, [
        'price' => 449,
        'duration' => '2-3 days',
        'description' => 'Updated by shop owner individual workflow test',
    ]);
    check($updateServiceRes['status'] === 200, 'PUT /api/shop-owner/repair-services/{id}', 'HTTP ' . $updateServiceRes['status']);

    $deleteServiceRes = request('DELETE', $baseUrl . '/api/shop-owner/repair-services/' . $serviceId);
    check($deleteServiceRes['status'] === 200, 'DELETE /api/shop-owner/repair-services/{id}', 'HTTP ' . $deleteServiceRes['status']);
}

section('5. Permission guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'shop_owner_unauth_');
$ch = curl_init($baseUrl . '/api/shop-owner/dashboard/stats');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated shop owner API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  SHOP OWNER MODULE TEST RESULTS (INDIVIDUAL)\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
echo "Pass rate: " . (($pass + $fail) ? round(($pass / ($pass + $fail)) * 100) : 0) . "%\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($results as $result) {
        if (! $result['pass']) {
            echo '- [' . $result['section'] . '] ' . $result['name'];
            if ($result['detail']) echo ' → ' . $result['detail'];
            echo "\n";
        }
    }
}

@unlink($cookieJar);
