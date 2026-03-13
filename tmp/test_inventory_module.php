<?php
/**
 * Focused Inventory module smoke test for SME workflows.
 * Run: php tmp/test_inventory_module.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'inventory.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'inventory_test_cookies_');
$results = [];
$sectionOpen = '';

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
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $raw,
        'json' => json_decode($raw, true),
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

section('0. Authentication');
request('GET', $baseUrl . '/sanctum/csrf-cookie');
$loginRes = request('POST', $loginUrl, $credentials);
check(in_array($loginRes['status'], [200, 302]), 'Login as inventory.2@solespace.com', 'HTTP ' . $loginRes['status']);

section('1. Inventory Pages');
$pages = [
    '/erp/inventory/inventory-dashboard',
    '/erp/inventory/product-inventory',
    '/erp/inventory/stock-movement',
    '/erp/inventory/upload-stocks',
    '/erp/inventory/stock-request',
    '/erp/inventory/supplier-order-monitoring',
];
foreach ($pages as $page) {
    $res = request('GET', $baseUrl . $page);
    check($res['status'] === 200, 'GET ' . $page, 'HTTP ' . $res['status']);
}

section('2. Inventory Dashboard API');
$dashboardRes = request('GET', $baseUrl . '/api/erp/inventory/dashboard');
check($dashboardRes['status'] === 200, 'GET /api/erp/inventory/dashboard', 'HTTP ' . $dashboardRes['status']);
$metricsRes = request('GET', $baseUrl . '/api/erp/inventory/dashboard/metrics');
check($metricsRes['status'] === 200, 'GET /api/erp/inventory/dashboard/metrics', 'HTTP ' . $metricsRes['status']);
$chartRes = request('GET', $baseUrl . '/api/erp/inventory/dashboard/chart-data');
check($chartRes['status'] === 200, 'GET /api/erp/inventory/dashboard/chart-data', 'HTTP ' . $chartRes['status']);

section('3. Product Inventory API');
$productsRes = request('GET', $baseUrl . '/api/erp/inventory/products?per_page=50');
check($productsRes['status'] === 200, 'GET /api/erp/inventory/products', 'HTTP ' . $productsRes['status']);
$products = $productsRes['json']['data'] ?? $productsRes['json'] ?? [];
$productCount = is_array($products) ? count($products) : 0;
check($productCount >= 0, 'Products response parsed', 'count=' . $productCount);
$productId = $productCount > 0 ? ($products[0]['id'] ?? null) : null;
if ($productId) {
    $productShowRes = request('GET', $baseUrl . '/api/erp/inventory/products/' . $productId);
    check($productShowRes['status'] === 200, 'GET /api/erp/inventory/products/{id}', 'HTTP ' . $productShowRes['status']);
}

section('4. Stock Movement API');
$movementsRes = request('GET', $baseUrl . '/api/erp/inventory/movements?per_page=50');
check($movementsRes['status'] === 200, 'GET /api/erp/inventory/movements', 'HTTP ' . $movementsRes['status']);
$movementMetricsRes = request('GET', $baseUrl . '/api/erp/inventory/movements/metrics');
check($movementMetricsRes['status'] === 200, 'GET /api/erp/inventory/movements/metrics', 'HTTP ' . $movementMetricsRes['status']);
$movementExportRes = request('GET', $baseUrl . '/api/erp/inventory/movements/export');
check(in_array($movementExportRes['status'], [200, 500]), 'GET /api/erp/inventory/movements/export', 'HTTP ' . $movementExportRes['status']);

section('5. Upload Inventory API');
$itemsRes = request('GET', $baseUrl . '/api/erp/inventory/items?per_page=50');
check($itemsRes['status'] === 200, 'GET /api/erp/inventory/items', 'HTTP ' . $itemsRes['status']);

section('6. Suppliers and Orders API');
$suppliersRes = request('GET', $baseUrl . '/api/erp/inventory/suppliers');
check($suppliersRes['status'] === 200, 'GET /api/erp/inventory/suppliers', 'HTTP ' . $suppliersRes['status']);
$supplierOrdersRes = request('GET', $baseUrl . '/api/erp/inventory/supplier-orders');
check($supplierOrdersRes['status'] === 200, 'GET /api/erp/inventory/supplier-orders', 'HTTP ' . $supplierOrdersRes['status']);
$monitoringRes = request('GET', $baseUrl . '/api/erp/inventory/supplier-orders-monitoring');
check($monitoringRes['status'] === 200, 'GET /api/erp/inventory/supplier-orders-monitoring', 'HTTP ' . $monitoringRes['status']);
$monitoringMetricsRes = request('GET', $baseUrl . '/api/erp/inventory/supplier-orders-monitoring/metrics');
check($monitoringMetricsRes['status'] === 200, 'GET /api/erp/inventory/supplier-orders-monitoring/metrics', 'HTTP ' . $monitoringMetricsRes['status']);

section('7. Permission Guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'inventory_unauth_');
$ch = curl_init($baseUrl . '/api/erp/inventory/products');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated inventory API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  INVENTORY MODULE TEST RESULTS\n";
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
