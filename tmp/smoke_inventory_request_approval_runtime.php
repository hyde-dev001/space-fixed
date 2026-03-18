<?php
/**
 * Runtime smoke check for Inventory Request Material Approval flow.
 *
 * Verifies:
 * 1) Queue endpoint loads repair-source requests
 * 2) Approve endpoint works for one pending request
 * 3) Reject endpoint works for one pending request
 *
 * It creates two temporary SMOKE rows and deletes them at the end.
 *
 * Run: php tmp/smoke_inventory_request_approval_runtime.php
 */

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\StockRequestApproval;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$baseUrl = 'http://127.0.0.1:8000';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'inventory.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'inv_req_approval_smoke_');
$results = [];
$createdIds = [];
$runTag = 'SMOKE-' . date('YmdHis') . '-' . random_int(100, 999);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function check(bool $condition, string $name, string $detail = ''): void
{
    global $results;
    $label = $condition ? 'PASS' : 'FAIL';
    echo '[' . $label . "] {$name}";
    if ($detail !== '') {
        echo " -> {$detail}";
    }
    echo PHP_EOL;

    $results[] = [
        'pass' => $condition,
        'name' => $name,
        'detail' => $detail,
    ];
}

function xsrfTokenFromJar(string $cookieJar): ?string
{
    if (!file_exists($cookieJar)) {
        return null;
    }

    $lines = file($cookieJar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }

    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, 'XSRF-TOKEN')) {
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || count($parts) === 0) {
                return null;
            }
            $token = end($parts);
            return is_string($token) ? $token : null;
        }
    }

    return null;
}

function request(string $method, string $url, string $cookieJar, mixed $body = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = ['Accept: application/json'];
    $xsrf = xsrfTokenFromJar($cookieJar);
    if ($xsrf !== null) {
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
        'json' => is_string($raw) ? json_decode($raw, true) : null,
    ];
}

try {
    /** @var User|null $inventoryUser */
    $inventoryUser = User::query()->where('email', $credentials['email'])->first();
    if (!$inventoryUser) {
        throw new RuntimeException('Smoke user not found: ' . $credentials['email']);
    }

    $shopOwnerId = (int) $inventoryUser->shop_owner_id;

    /** @var InventoryItem|null $material */
    $material = InventoryItem::query()
        ->where('shop_owner_id', $shopOwnerId)
        ->where('is_active', true)
        ->where('category', 'repair_materials')
        ->orderBy('id')
        ->first();

    if (!$material) {
        $material = InventoryItem::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    if (!$material) {
        throw new RuntimeException('No active inventory item found for shop_owner_id=' . $shopOwnerId);
    }

    // Create two temporary pending repair-source requests for deterministic approve/reject checks.
    for ($i = 1; $i <= 2; $i++) {
        $requestNumber = sprintf('SR-%s-%02d', date('Y'), random_int(10000, 99999));

        $created = StockRequestApproval::query()->create([
            'request_number' => $requestNumber,
            'shop_owner_id' => $shopOwnerId,
            'inventory_item_id' => $material->id,
            'product_name' => (string) $material->name,
            'sku_code' => (string) ($material->sku ?? ''),
            'quantity_needed' => 1 + $i,
            'priority' => 'medium',
            'request_source' => 'repair',
            'status' => 'pending',
            'requested_by' => $inventoryUser->id,
            'requested_date' => now(),
            'notes' => 'Runtime smoke request ' . $runTag . ' #' . $i,
        ]);

        $createdIds[] = (int) $created->id;
    }

    check(count($createdIds) === 2, 'Seeded 2 temporary pending repair requests', 'ids=' . implode(',', $createdIds));

    // Auth/session for API calls.
    request('GET', $baseUrl . '/sanctum/csrf-cookie', $cookieJar);
    $loginRes = request('POST', $loginUrl, $cookieJar, $credentials);
    check(in_array($loginRes['status'], [200, 302], true), 'Login inventory user', 'HTTP ' . $loginRes['status']);

    $pageRes = request('GET', $baseUrl . '/erp/inventory/request-material-approval', $cookieJar);
    check($pageRes['status'] === 200, 'Load request-approval page (web)', 'HTTP ' . $pageRes['status']);

    // Queue load.
    $queueRes = request(
        'GET',
        $baseUrl . '/api/erp/inventory/request-material-approvals?per_page=200&request_source=repair',
        $cookieJar
    );
    check($queueRes['status'] === 200, 'Load repair queue', 'HTTP ' . $queueRes['status']);

    $queueRows = $queueRes['json']['data'] ?? [];
    $queueIds = [];
    if (is_array($queueRows)) {
        foreach ($queueRows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $queueIds[] = (int) $row['id'];
            }
        }
    }

    $inQueue = in_array($createdIds[0], $queueIds, true) && in_array($createdIds[1], $queueIds, true);
    check($inQueue, 'Seeded requests visible in queue', 'queue_count=' . count($queueIds));

    $approveId = $createdIds[0];
    $rejectId = $createdIds[1];

    // Approve one.
    $approveRes = request(
        'POST',
        $baseUrl . '/api/erp/inventory/request-material-approvals/' . $approveId . '/approve',
        $cookieJar,
        ['approval_notes' => 'Runtime smoke approval ' . $runTag]
    );
    check($approveRes['status'] === 200, 'Approve one request', 'HTTP ' . $approveRes['status']);

    $approvedShowRes = request(
        'GET',
        $baseUrl . '/api/erp/inventory/request-material-approvals/' . $approveId,
        $cookieJar
    );
    $approvedStatus = $approvedShowRes['json']['status'] ?? null;
    check($approvedShowRes['status'] === 200 && $approvedStatus === 'accepted', 'Approved request status persisted', 'status=' . (string) $approvedStatus);

    // Reject one.
    $rejectRes = request(
        'POST',
        $baseUrl . '/api/erp/inventory/request-material-approvals/' . $rejectId . '/reject',
        $cookieJar,
        ['rejection_reason' => 'Runtime smoke rejection reason for workflow verification.']
    );
    check($rejectRes['status'] === 200, 'Reject one request', 'HTTP ' . $rejectRes['status']);

    $rejectedShowRes = request(
        'GET',
        $baseUrl . '/api/erp/inventory/request-material-approvals/' . $rejectId,
        $cookieJar
    );
    $rejectedStatus = $rejectedShowRes['json']['status'] ?? null;
    check($rejectedShowRes['status'] === 200 && $rejectedStatus === 'rejected', 'Rejected request status persisted', 'status=' . (string) $rejectedStatus);

    $pass = 0;
    $fail = 0;
    foreach ($results as $result) {
        if ($result['pass']) {
            $pass++;
        } else {
            $fail++;
        }
    }

    echo PHP_EOL;
    echo 'Summary: pass=' . $pass . ', fail=' . $fail . PHP_EOL;

    if ($fail > 0) {
        foreach ($results as $result) {
            if (!$result['pass']) {
                echo '- FAIL: ' . $result['name'];
                if ($result['detail'] !== '') {
                    echo ' -> ' . $result['detail'];
                }
                echo PHP_EOL;
            }
        }
    }

    // Cleanup temporary records.
    if (count($createdIds) > 0) {
        StockRequestApproval::query()->whereIn('id', $createdIds)->delete();
        echo 'Cleanup: deleted temporary IDs [' . implode(',', $createdIds) . ']' . PHP_EOL;
    }

    @unlink($cookieJar);

    if ($fail > 0) {
        exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    if (count($createdIds) > 0) {
        StockRequestApproval::query()->whereIn('id', $createdIds)->delete();
        echo 'Cleanup after exception: deleted temporary IDs [' . implode(',', $createdIds) . ']' . PHP_EOL;
    }

    @unlink($cookieJar);

    echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
