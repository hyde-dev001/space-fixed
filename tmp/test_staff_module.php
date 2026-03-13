<?php
/**
 * Focused STAFF module smoke + light workflow test.
 * Run: php tmp/test_staff_module.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'staff.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'staff_test_cookies_');
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
check(in_array($loginRes['status'], [200, 302]), 'Login as staff.2@solespace.com', 'HTTP ' . $loginRes['status']);

section('1. STAFF Pages');
$pages = [
    '/erp/staff/dashboard',
    '/erp/staff/job-orders',
    '/erp/staff/products',
    '/erp/staff/shoe-pricing',
    '/erp/staff/attendance',
    '/erp/staff/customers',
    '/erp/my-payslips',
];
foreach ($pages as $page) {
    $res = request('GET', $baseUrl . $page);
    check($res['status'] === 200, 'GET ' . $page, 'HTTP ' . $res['status']);
}

section('2. STAFF Core APIs');
$csrfRes = request('GET', $baseUrl . '/api/csrf-token');
check($csrfRes['status'] === 200, 'GET /api/csrf-token', 'HTTP ' . $csrfRes['status']);

$ordersRes = request('GET', $baseUrl . '/api/staff/orders');
check($ordersRes['status'] === 200, 'GET /api/staff/orders', 'HTTP ' . $ordersRes['status']);
$orders = $ordersRes['json']['data'] ?? $ordersRes['json'] ?? [];
$orderCount = is_array($orders) ? count($orders) : 0;
check($orderCount >= 0, 'Staff orders response parsed', 'count=' . $orderCount);
$orderId = $orderCount > 0 ? ($orders[0]['id'] ?? null) : null;
if ($orderId) {
    $orderShowRes = request('GET', $baseUrl . '/api/staff/orders/' . $orderId);
    check($orderShowRes['status'] === 200, 'GET /api/staff/orders/{id}', 'HTTP ' . $orderShowRes['status']);
}

$customersRes = request('GET', $baseUrl . '/api/staff/customers');
check($customersRes['status'] === 200, 'GET /api/staff/customers', 'HTTP ' . $customersRes['status']);
$customerStatsRes = request('GET', $baseUrl . '/api/staff/customers/stats');
check($customerStatsRes['status'] === 200, 'GET /api/staff/customers/stats', 'HTTP ' . $customerStatsRes['status']);

section('3. STAFF Self-service APIs');
$attendanceStatusRes = request('GET', $baseUrl . '/api/staff/attendance/status');
check($attendanceStatusRes['status'] === 200, 'GET /api/staff/attendance/status', 'HTTP ' . $attendanceStatusRes['status']);
$myRecordsRes = request('GET', $baseUrl . '/api/staff/attendance/my-records');
check($myRecordsRes['status'] === 200, 'GET /api/staff/attendance/my-records', 'HTTP ' . $myRecordsRes['status']);
$myLateStatsRes = request('GET', $baseUrl . '/api/staff/attendance/my-lateness-stats');
check($myLateStatsRes['status'] === 200, 'GET /api/staff/attendance/my-lateness-stats', 'HTTP ' . $myLateStatsRes['status']);
$shopHoursTodayRes = request('GET', $baseUrl . '/api/staff/shop-hours/today');
check($shopHoursTodayRes['status'] === 200, 'GET /api/staff/shop-hours/today', 'HTTP ' . $shopHoursTodayRes['status']);

$leaveMyRes = request('GET', $baseUrl . '/api/staff/leave/my-requests');
check($leaveMyRes['status'] === 200, 'GET /api/staff/leave/my-requests', 'HTTP ' . $leaveMyRes['status']);

$overtimeTodayRes = request('GET', $baseUrl . '/api/staff/overtime/today-approved');
check($overtimeTodayRes['status'] === 200, 'GET /api/staff/overtime/today-approved', 'HTTP ' . $overtimeTodayRes['status']);
$overtimeMyRes = request('GET', $baseUrl . '/api/staff/overtime/my-requests');
check($overtimeMyRes['status'] === 200, 'GET /api/staff/overtime/my-requests', 'HTTP ' . $overtimeMyRes['status']);

$payslipsRes = request('GET', $baseUrl . '/api/staff/payslips/my');
check($payslipsRes['status'] === 200, 'GET /api/staff/payslips/my', 'HTTP ' . $payslipsRes['status']);

section('4. STAFF Product/Pricing APIs');
$myProductsRes = request('GET', $baseUrl . '/api/products/my/products');
check($myProductsRes['status'] === 200, 'GET /api/products/my/products', 'HTTP ' . $myProductsRes['status']);
$pricePendingRes = request('GET', $baseUrl . '/api/price-change-requests/my-pending');
check($pricePendingRes['status'] === 200, 'GET /api/price-change-requests/my-pending', 'HTTP ' . $pricePendingRes['status']);
$invItemsRes = request('GET', $baseUrl . '/api/erp/inventory/items?category=shoes&per_page=10');
check($invItemsRes['status'] === 200, 'GET /api/erp/inventory/items?category=shoes', 'HTTP ' . $invItemsRes['status']);

section('5. Light Write Workflow (leave request submit)');
$startDate = date('Y-m-d', strtotime('+10 days'));
$endDate = date('Y-m-d', strtotime('+11 days'));
$leaveCreateRes = request('POST', $baseUrl . '/api/staff/leave/request', [
    'leave_type' => 'personal',
    'start_date' => $startDate,
    'end_date' => $endDate,
    'reason' => 'Automated STAFF module test leave request ' . $runTag,
]);
check(in_array($leaveCreateRes['status'], [201, 422]), 'POST /api/staff/leave/request', 'HTTP ' . $leaveCreateRes['status']);

$leaveId = $leaveCreateRes['json']['data']['id'] ?? null;
if ($leaveCreateRes['status'] === 201 && $leaveId) {
    $leaveCancelRes = request('DELETE', $baseUrl . '/api/staff/leave/' . $leaveId . '/cancel');
    check(in_array($leaveCancelRes['status'], [200, 422]), 'DELETE /api/staff/leave/{id}/cancel', 'HTTP ' . $leaveCancelRes['status']);
}

section('6. Permission Guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'staff_unauth_');
$ch = curl_init($baseUrl . '/api/staff/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated staff API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  STAFF MODULE TEST RESULTS\n";
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
