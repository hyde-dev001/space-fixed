<?php
/**
 * Focused Procurement module smoke test for SME workflows.
 * Run: php tmp/test_procurement_module.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'procurement.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'procurement_test_cookies_');
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
check(in_array($loginRes['status'], [200, 302]), 'Login as procurement.2@solespace.com', 'HTTP ' . $loginRes['status']);

section('1. Procurement Pages');
$pages = [
    '/erp/procurement/purchase-request',
    '/erp/procurement/purchase-orders',
    '/erp/procurement/stock-request-approval',
    '/erp/procurement/suppliers-management',
    '/erp/procurement/supplier-order-monitoring',
];
foreach ($pages as $page) {
    $res = request('GET', $baseUrl . $page);
    check($res['status'] === 200, 'GET ' . $page, 'HTTP ' . $res['status']);
}

section('2. Purchase Requests API');
$prRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-requests?per_page=50');
check($prRes['status'] === 200, 'GET /api/erp/procurement/purchase-requests', 'HTTP ' . $prRes['status']);
$prMetricsRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-requests/metrics');
check($prMetricsRes['status'] === 200, 'GET /api/erp/procurement/purchase-requests/metrics', 'HTTP ' . $prMetricsRes['status']);
$approvedPrsRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-requests/approved');
check($approvedPrsRes['status'] === 200, 'GET /api/erp/procurement/purchase-requests/approved', 'HTTP ' . $approvedPrsRes['status']);
$purchaseRequests = $prRes['json']['data'] ?? $prRes['json'] ?? [];
$purchaseRequestCount = is_array($purchaseRequests) ? count($purchaseRequests) : 0;
check($purchaseRequestCount >= 0, 'Purchase requests response parsed', 'count=' . $purchaseRequestCount);
$purchaseRequestId = $purchaseRequestCount > 0 ? ($purchaseRequests[0]['id'] ?? null) : null;
if ($purchaseRequestId) {
    $prShowRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-requests/' . $purchaseRequestId);
    check($prShowRes['status'] === 200, 'GET /api/erp/procurement/purchase-requests/{id}', 'HTTP ' . $prShowRes['status']);
}

section('3. Purchase Orders API');
$poRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-orders?per_page=50');
check($poRes['status'] === 200, 'GET /api/erp/procurement/purchase-orders', 'HTTP ' . $poRes['status']);
$poMetricsRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-orders/metrics');
check($poMetricsRes['status'] === 200, 'GET /api/erp/procurement/purchase-orders/metrics', 'HTTP ' . $poMetricsRes['status']);
$purchaseOrders = $poRes['json']['data'] ?? $poRes['json'] ?? [];
$purchaseOrderCount = is_array($purchaseOrders) ? count($purchaseOrders) : 0;
check($purchaseOrderCount >= 0, 'Purchase orders response parsed', 'count=' . $purchaseOrderCount);
$purchaseOrderId = $purchaseOrderCount > 0 ? ($purchaseOrders[0]['id'] ?? null) : null;
if ($purchaseOrderId) {
    $poShowRes = request('GET', $baseUrl . '/api/erp/procurement/purchase-orders/' . $purchaseOrderId);
    check($poShowRes['status'] === 200, 'GET /api/erp/procurement/purchase-orders/{id}', 'HTTP ' . $poShowRes['status']);
}

section('4. Stock Requests API');
$stockReqRes = request('GET', $baseUrl . '/api/erp/procurement/stock-requests?per_page=50');
check($stockReqRes['status'] === 200, 'GET /api/erp/procurement/stock-requests', 'HTTP ' . $stockReqRes['status']);
$stockMetricsRes = request('GET', $baseUrl . '/api/erp/procurement/stock-requests/metrics');
check($stockMetricsRes['status'] === 200, 'GET /api/erp/procurement/stock-requests/metrics', 'HTTP ' . $stockMetricsRes['status']);
$stockRequests = $stockReqRes['json']['data'] ?? $stockReqRes['json'] ?? [];
$stockRequestCount = is_array($stockRequests) ? count($stockRequests) : 0;
check($stockRequestCount >= 0, 'Stock requests response parsed', 'count=' . $stockRequestCount);
$stockRequestId = $stockRequestCount > 0 ? ($stockRequests[0]['id'] ?? null) : null;
if ($stockRequestId) {
    $stockShowRes = request('GET', $baseUrl . '/api/erp/procurement/stock-requests/' . $stockRequestId);
    check($stockShowRes['status'] === 200, 'GET /api/erp/procurement/stock-requests/{id}', 'HTTP ' . $stockShowRes['status']);
}

section('5. Suppliers API');
$suppliersRes = request('GET', $baseUrl . '/api/erp/procurement/suppliers');
check($suppliersRes['status'] === 200, 'GET /api/erp/procurement/suppliers', 'HTTP ' . $suppliersRes['status']);
$suppliers = $suppliersRes['json']['data'] ?? $suppliersRes['json'] ?? [];
$supplierCount = is_array($suppliers) ? count($suppliers) : 0;
check($supplierCount >= 0, 'Suppliers response parsed', 'count=' . $supplierCount);
$supplierId = $supplierCount > 0 ? ($suppliers[0]['id'] ?? null) : null;
if ($supplierId) {
    $supplierShowRes = request('GET', $baseUrl . '/api/erp/procurement/suppliers/' . $supplierId);
    check($supplierShowRes['status'] === 200, 'GET /api/erp/procurement/suppliers/{id}', 'HTTP ' . $supplierShowRes['status']);
}

section('6. Permission Guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'procurement_unauth_');
$ch = curl_init($baseUrl . '/api/erp/procurement/purchase-requests');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated procurement API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  PROCUREMENT MODULE TEST RESULTS\n";
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
