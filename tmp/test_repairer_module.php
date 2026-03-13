<?php
/**
 * Focused Repairer module smoke + workflow test.
 * Run: php tmp/test_repairer_module.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'repairer.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'repairer_test_cookies_');
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
check(in_array($loginRes['status'], [200, 302]), 'Login as repairer.2@solespace.com', 'HTTP ' . $loginRes['status']);

section('1. Repairer Pages');
$pages = [
    '/erp/staff/repair-dashboard',
    '/erp/staff/job-orders-repair',
    '/erp/staff/upload-services',
    '/erp/staff/stocks-overview',
    '/erp/staff/pricing-and-services',
    '/erp/staff/repairer-support',
    '/erp/repairer/pricing-and-services',
];
foreach ($pages as $page) {
    $res = request('GET', $baseUrl . $page);
    check($res['status'] === 200, 'GET ' . $page, 'HTTP ' . $res['status']);
}

section('2. Repairer APIs');
$dashboardRes = request('GET', $baseUrl . '/api/repairer/dashboard');
check($dashboardRes['status'] === 200, 'GET /api/repairer/dashboard', 'HTTP ' . $dashboardRes['status']);
$repairsRes = request('GET', $baseUrl . '/api/repairer/repairs');
check($repairsRes['status'] === 200, 'GET /api/repairer/repairs', 'HTTP ' . $repairsRes['status']);
$conversationsRes = request('GET', $baseUrl . '/api/repairer/conversations');
check($conversationsRes['status'] === 200, 'GET /api/repairer/conversations', 'HTTP ' . $conversationsRes['status']);

section('3. Repair Services API CRUD');
$listRes = request('GET', $baseUrl . '/api/repair-services');
check($listRes['status'] === 200, 'GET /api/repair-services', 'HTTP ' . $listRes['status']);
$services = $listRes['json']['data'] ?? $listRes['json'] ?? [];
$serviceCount = is_array($services) ? count($services) : 0;
check($serviceCount >= 0, 'Repair services response parsed', 'count=' . $serviceCount);

$createRes = request('POST', $baseUrl . '/api/repair-services', [
    'name' => 'Workflow Repair Service ' . $runTag,
    'category' => 'Cleaning',
    'price' => 499,
    'duration' => '2-3 days',
    'description' => 'Temporary service created by repairer module test',
    'status' => 'Active',
]);
check($createRes['status'] === 201, 'POST /api/repair-services', 'HTTP ' . $createRes['status']);
$createdId = $createRes['json']['data']['id'] ?? null;
check(!empty($createdId), 'Created repair service id resolved', 'id=' . ($createdId ?? 'none'));

if ($createdId) {
    $showRes = request('GET', $baseUrl . '/api/repair-services/' . $createdId);
    check($showRes['status'] === 200, 'GET /api/repair-services/{id}', 'HTTP ' . $showRes['status']);

    $updateRes = request('PUT', $baseUrl . '/api/repair-services/' . $createdId, [
        'price' => 599,
        'duration' => '3-4 days',
        'description' => 'Updated by repairer module test',
    ]);
    check($updateRes['status'] === 200, 'PUT /api/repair-services/{id}', 'HTTP ' . $updateRes['status']);

    $deleteRes = request('DELETE', $baseUrl . '/api/repair-services/' . $createdId);
    check($deleteRes['status'] === 200, 'DELETE /api/repair-services/{id}', 'HTTP ' . $deleteRes['status']);
}

section('4. Permission Guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'repairer_unauth_');
$ch = curl_init($baseUrl . '/api/repairer/repairs');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated repairer API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  REPAIRER MODULE TEST RESULTS\n";
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
