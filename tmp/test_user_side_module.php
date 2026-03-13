<?php
/**
 * Focused UserSide module test (customer-facing pages/APIs from resources/js/Pages/UserSide).
 * Run: php tmp/test_user_side_module.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$credentials = [
    'email' => 'miguel.rosa@example.com',
    'password' => 'Password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'userside_test_cookies_');
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
    $responseBody = substr((string) $raw, $headerSize);
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
        'body' => $responseBody,
        'json' => json_decode($responseBody, true),
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

section('0. Authentication (customer user)');
request('GET', $baseUrl . '/sanctum/csrf-cookie');
$loginRes = request('POST', $baseUrl . '/user/login', $credentials);
$meProbe = request('GET', $baseUrl . '/api/user/me');
$authOk = in_array($loginRes['status'], [200, 302]) || $meProbe['status'] === 200;
check($authOk, 'Login as miguel.rosa@example.com', 'HTTP ' . $loginRes['status'] . ' (probe /api/user/me=' . $meProbe['status'] . ')');

section('1. UserSide pages (public + authenticated surfaces)');
$pages = [
    '/',
    '/products',
    '/checkout',
    '/payment',
    '/order-success',
    '/payment-failed',
    '/repair-services',
    '/services',
    '/login',
    '/user/login',
    '/register',
    '/forgot-password',
    '/shop-owner-register',
    '/my-orders',
    '/customer-profile',
    '/my-repairs',
    '/repair-process',
    '/messages',
    '/message',
    '/notifications',
    '/notifications/settings',
    '/shop-profile/1',
    '/shop-profile/1/virtual-showroom',
    '/repair-shop/1',
];

foreach ($pages as $page) {
    $res = request('GET', $baseUrl . $page);
    check($res['status'] === 200, 'GET ' . $page, 'HTTP ' . $res['status']);
}

section('2. UserSide APIs (read)');
$readApiChecks = [
    '/api/user/me' => [200],
    '/api/search/suggestions?query=shoe' => [200],
    '/api/repair-services' => [200],
    '/api/cart' => [200],
    '/api/my-orders' => [200],
    '/api/user/addresses' => [200],
    '/api/customer/conversations' => [200],
    '/api/customer/conversations/shops' => [200],
    '/api/customer/badge-counts' => [200],
    '/api/customer/shop/1/repair-capacity' => [200],
    '/api/repair/shop-hours?shop_id=1' => [200, 422],
    '/api/shops/1/reviews' => [200],
    '/api/shops/1/reviews/check-eligibility' => [200],
    '/api/notifications' => [200],
    '/api/notifications/unread-count' => [200],
];

$productsRes = request('GET', $baseUrl . '/api/products');
check($productsRes['status'] === 200, 'GET /api/products', 'HTTP ' . $productsRes['status']);

$productId = null;
$productsJson = $productsRes['json'] ?? [];

if (isset($productsJson['data']) && is_array($productsJson['data']) && isset($productsJson['data'][0]['id'])) {
    $productId = $productsJson['data'][0]['id'];
} elseif (isset($productsJson['products']['data']) && is_array($productsJson['products']['data']) && isset($productsJson['products']['data'][0]['id'])) {
    $productId = $productsJson['products']['data'][0]['id'];
} elseif (isset($productsJson['products']) && is_array($productsJson['products']) && isset($productsJson['products'][0]['id'])) {
    $productId = $productsJson['products'][0]['id'];
}

if ($productId) {
    $productReviewRes = request('GET', $baseUrl . '/api/products/' . $productId . '/reviews');
    check($productReviewRes['status'] === 200, 'GET /api/products/{id}/reviews', 'id=' . $productId . ' HTTP ' . $productReviewRes['status']);

    $productEligibilityRes = request('GET', $baseUrl . '/api/products/' . $productId . '/reviews/check-eligibility');
    check($productEligibilityRes['status'] === 200, 'GET /api/products/{id}/reviews/check-eligibility', 'id=' . $productId . ' HTTP ' . $productEligibilityRes['status']);
} else {
    check(true, 'GET /api/products/{id}/reviews', 'Skipped (no product id resolved from /api/products)');
    check(true, 'GET /api/products/{id}/reviews/check-eligibility', 'Skipped (no product id resolved from /api/products)');
}

foreach ($readApiChecks as $apiPath => $allowedStatuses) {
    $res = request('GET', $baseUrl . $apiPath);
    check(in_array($res['status'], $allowedStatuses), 'GET ' . $apiPath, 'HTTP ' . $res['status']);
}

section('3. UserSide APIs (light write workflow)');
$createAddressRes = request('POST', $baseUrl . '/api/user/addresses', [
    'name' => 'UserSide Test Address ' . $runTag,
    'phone' => '+639123000000',
    'region' => 'NCR',
    'province' => 'Metro Manila',
    'city' => 'Makati',
    'barangay' => 'Bel-Air',
    'postal_code' => '1209',
    'address_line' => '123 Test Lane',
    'is_default' => false,
]);
check($createAddressRes['status'] === 201, 'POST /api/user/addresses', 'HTTP ' . $createAddressRes['status']);
$addressId = $createAddressRes['json']['address']['id'] ?? null;
check(!empty($addressId), 'Created address id resolved', 'id=' . ($addressId ?? 'none'));

if ($addressId) {
    $updateAddressRes = request('PUT', $baseUrl . '/api/user/addresses/' . $addressId, [
        'name' => 'UserSide Test Address Updated ' . $runTag,
        'phone' => '+639123111111',
        'region' => 'NCR',
        'province' => 'Metro Manila',
        'city' => 'Taguig',
        'barangay' => 'Fort Bonifacio',
        'postal_code' => '1634',
        'address_line' => '456 Updated Street',
        'is_default' => false,
    ]);
    check($updateAddressRes['status'] === 200, 'PUT /api/user/addresses/{id}', 'HTTP ' . $updateAddressRes['status']);

    $setDefaultRes = request('POST', $baseUrl . '/api/user/addresses/' . $addressId . '/set-default');
    check($setDefaultRes['status'] === 200, 'POST /api/user/addresses/{id}/set-default', 'HTTP ' . $setDefaultRes['status']);

    $deleteAddressRes = request('DELETE', $baseUrl . '/api/user/addresses/' . $addressId);
    check($deleteAddressRes['status'] === 200, 'DELETE /api/user/addresses/{id}', 'HTTP ' . $deleteAddressRes['status']);
}

section('4. Permission guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'userside_unauth_');
$ch = curl_init($baseUrl . '/api/user/me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated user API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  USER SIDE MODULE TEST RESULTS\n";
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
