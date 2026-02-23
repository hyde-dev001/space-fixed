<?php
/**
 * Test the Order Update API endpoint
 */

// Get CSRF token first
$csrfUrl = 'http://127.0.0.1:8000/api/csrf-token';
$ch = curl_init($csrfUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
curl_setopt($ch, CURLOPT_COOKIESESSION, true);
$csrfResponse = curl_exec($ch);
curl_close($ch);

$csrfData = json_decode($csrfResponse, true);
$csrfToken = $csrfData['csrf_token'] ?? null;

echo "CSRF Token: " . ($csrfToken ? "✓ Retrieved" : "✗ Failed") . "\n\n";

if (!$csrfToken) {
    die("Failed to get CSRF token\n");
}

// Now test the order update endpoint
echo "Testing Order Status Update API\n";
echo "================================\n\n";

$orderId = 1;
$updateUrl = "http://127.0.0.1:8000/api/shop-owner/orders/{$orderId}/status";

$data = [
    'status' => 'shipped',
    'tracking_number' => 'TEST-API-123',
    'carrier_company' => 'LBC Express',
    'carrier_name' => 'Juan Dela Cruz',
    'carrier_phone' => '09987654321',
    'tracking_link' => 'https://example.com/track/TEST-API-123',
    'eta' => date('Y-m-d', strtotime('+5 days')),
];

$ch = curl_init($updateUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-CSRF-TOKEN: ' . $csrfToken,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: {$httpCode}\n";

if ($error) {
    echo "cURL Error: {$error}\n";
} else {
    echo "Response:\n";
    $responseData = json_decode($response, true);
    if ($responseData) {
        echo json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo $response . "\n";
    }
}

// Clean up cookie file
if (file_exists('cookies.txt')) {
    unlink('cookies.txt');
}

echo "\nNote: This test assumes you are logged in as shop owner in your browser.\n";
echo "If you get a 401 error, please log in to the shop owner account first.\n";
