<?php
/**
 * Deeper Procurement workflow test (create/approve/reject records)
 * Run: php tmp/test_procurement_workflow.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'procurement.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'procurement_workflow_cookies_');
$results = [];
$sectionOpen = '';
$runTag = date('YmdHis');
$setupSupplierId = null;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function section(string $name): void {
    global $sectionOpen;
    $sectionOpen = $name;
    echo "\n╔══════════════════════════════════════════════════════════════\n";
    echo "║  {$name}\n";
    echo "╚══════════════════════════════════════════════════════════════\n";
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

function note(string $message): void {
    echo "  [INFO] {$message}\n";
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

function dig(array $data, array $paths, mixed $default = null): mixed {
    foreach ($paths as $path) {
        $node = $data;
        $ok = true;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $ok = false;
                break;
            }
            $node = $node[$segment];
        }
        if ($ok) return $node;
    }
    return $default;
}

function firstSupplierIdFromDb(int $shopOwnerId): ?int {
    $id = \Illuminate\Support\Facades\DB::table('suppliers')
        ->where('shop_owner_id', $shopOwnerId)
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->value('id');

    return $id ? (int) $id : null;
}

function firstInventoryItemIdFromDb(int $shopOwnerId): ?int {
    $id = \Illuminate\Support\Facades\DB::table('inventory_items')
        ->where('shop_owner_id', $shopOwnerId)
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->value('id');

    if ($id) {
        return (int) $id;
    }

    // Fallback: any item if shop-scoped data is empty
    $anyId = \Illuminate\Support\Facades\DB::table('inventory_items')
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->value('id');

    return $anyId ? (int) $anyId : null;
}

section('0. Authentication');
request('GET', $baseUrl . '/sanctum/csrf-cookie');
$loginRes = request('POST', $loginUrl, $credentials);
check(in_array($loginRes['status'], [200, 302]), 'Login as procurement.2@solespace.com', 'HTTP ' . $loginRes['status']);

section('1. Reference Data');
$suppliersRes = request('GET', $baseUrl . '/api/erp/procurement/suppliers?per_page=50');
check($suppliersRes['status'] === 200, 'GET suppliers for workflow references', 'HTTP ' . $suppliersRes['status']);
$suppliers = $suppliersRes['json']['data'] ?? $suppliersRes['json'] ?? [];
$supplierId = (is_array($suppliers) && count($suppliers) > 0) ? ($suppliers[0]['id'] ?? null) : null;

if (!$supplierId) {
    $supplierId = firstSupplierIdFromDb(2);
}

if (!$supplierId) {
    $setupSupplierRes = request('POST', $baseUrl . '/api/erp/procurement/suppliers', [
        'name' => 'Workflow Setup Supplier ' . $runTag,
        'contact_person' => 'Workflow Setup',
        'email' => 'workflow.setup.' . $runTag . '@example.com',
        'phone' => '09170000000',
        'address' => 'Metro Manila, Philippines',
        'notes' => 'Auto-created setup supplier for workflow test prerequisites',
    ]);

    if ($setupSupplierRes['status'] === 201) {
        $setupSupplierId = dig($setupSupplierRes['json'], ['supplier.id', 'data.id', 'id']);
        $supplierId = $setupSupplierId;
        note('Created setup supplier for references: id=' . $setupSupplierId);
    }
}

check(!empty($supplierId), 'Found supplier reference', 'supplier_id=' . ($supplierId ?? 'none'));

$stockListRes = request('GET', $baseUrl . '/api/erp/procurement/stock-requests?per_page=100');
check($stockListRes['status'] === 200, 'GET stock requests for inventory reference', 'HTTP ' . $stockListRes['status']);
$stockRows = $stockListRes['json']['data'] ?? $stockListRes['json'] ?? [];
$inventoryItemId = null;
if (is_array($stockRows)) {
    foreach ($stockRows as $row) {
        if (!empty($row['inventory_item_id'])) {
            $inventoryItemId = (int) $row['inventory_item_id'];
            break;
        }
    }
}
if (!$inventoryItemId) {
    $itemsRes = request('GET', $baseUrl . '/api/erp/inventory/items?per_page=10');
    if ($itemsRes['status'] === 200) {
        $items = $itemsRes['json']['data'] ?? $itemsRes['json'] ?? [];
        if (is_array($items) && count($items) > 0) {
            $inventoryItemId = (int) ($items[0]['id'] ?? 0);
        }
    }
}

if (!$inventoryItemId) {
    $inventoryItemId = firstInventoryItemIdFromDb(2);
}

if (!$inventoryItemId) {
    $existingWorkflowItemId = \Illuminate\Support\Facades\DB::table('inventory_items')
        ->where('shop_owner_id', 2)
        ->where('sku', 'WF-PROC-ITEM-2')
        ->whereNull('deleted_at')
        ->value('id');

    if ($existingWorkflowItemId) {
        $inventoryItemId = (int) $existingWorkflowItemId;
        note('Reusing workflow inventory item id=' . $inventoryItemId);
    } else {
        $procurementUserId = \Illuminate\Support\Facades\DB::table('users')
            ->where('email', 'procurement.2@solespace.com')
            ->value('id');

        $inventoryItemId = (int) \Illuminate\Support\Facades\DB::table('inventory_items')->insertGetId([
            'shop_owner_id' => 2,
            'name' => 'Workflow Procurement Item',
            'sku' => 'WF-PROC-ITEM-2',
            'category' => 'repair_materials',
            'unit' => 'pcs',
            'available_quantity' => 100,
            'reserved_quantity' => 0,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
            'cost_price' => 25.00,
            'price' => 40.00,
            'is_active' => 1,
            'created_by' => $procurementUserId,
            'updated_by' => $procurementUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        note('Created workflow inventory item id=' . $inventoryItemId);
    }
}

check(!empty($inventoryItemId), 'Found inventory item reference for stock request create', 'inventory_item_id=' . ($inventoryItemId ?? 'none'));

section('2. Purchase Request Workflow (create → approve → approve → PO create)');
$prApproveId = null;
$poId = null;
if (!$supplierId) {
    check(false, 'Create PR for approval path', 'No supplier available');
} else {
    $prCreateRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-requests', [
        'product_name' => 'Workflow PR Approve ' . $runTag,
        'supplier_id' => $supplierId,
        'quantity' => 2,
        'unit_cost' => 450,
        'priority' => 'medium',
        'justification' => 'Workflow automation validation for procurement approval path.',
        'requested_size' => '42',
        'submit_to_finance' => true,
    ]);
    check($prCreateRes['status'] === 201, 'Create purchase request (approval path)', 'HTTP ' . $prCreateRes['status']);

    $prApproveId = dig($prCreateRes['json'], ['purchase_request.id', 'data.id', 'id']);
    $prApproveStatus = dig($prCreateRes['json'], ['purchase_request.status', 'data.status', 'status']);
    check(!empty($prApproveId), 'PR created with id', 'id=' . ($prApproveId ?? 'none'));
    check($prApproveStatus === 'pending_finance', 'PR initial status is pending_finance', 'status=' . ($prApproveStatus ?? 'unknown'));

    if ($prApproveId) {
        $prFinanceApproveRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-requests/' . $prApproveId . '/approve', [
            'approval_notes' => 'Finance-stage approval in workflow test',
        ]);
        check($prFinanceApproveRes['status'] === 200, 'Approve PR (finance stage)', 'HTTP ' . $prFinanceApproveRes['status']);
        $stage1Status = dig($prFinanceApproveRes['json'], ['purchase_request.status', 'data.status', 'status']);
        check($stage1Status === 'pending_shop_owner', 'PR moved to pending_shop_owner', 'status=' . ($stage1Status ?? 'unknown'));

        $prFinalApproveRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-requests/' . $prApproveId . '/approve', [
            'approval_notes' => 'Final approval in workflow test',
        ]);
        check($prFinalApproveRes['status'] === 200, 'Approve PR (shop owner/final stage)', 'HTTP ' . $prFinalApproveRes['status']);
        $stage2Status = dig($prFinalApproveRes['json'], ['purchase_request.status', 'data.status', 'status']);
        check($stage2Status === 'approved', 'PR final status is approved', 'status=' . ($stage2Status ?? 'unknown'));

        $poCreateRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-orders', [
            'pr_id' => (int) $prApproveId,
            'expected_delivery_date' => date('Y-m-d', strtotime('+3 days')),
            'payment_terms' => 'Net 30',
            'notes' => 'PO created by workflow test',
        ]);
        check($poCreateRes['status'] === 201, 'Create PO from approved PR', 'HTTP ' . $poCreateRes['status']);
        $poId = dig($poCreateRes['json'], ['purchase_order.id', 'data.id', 'id']);
        $poStatus = dig($poCreateRes['json'], ['purchase_order.status', 'data.status', 'status']);
        check(!empty($poId), 'PO created with id', 'id=' . ($poId ?? 'none'));
        check($poStatus === 'draft', 'PO initial status is draft', 'status=' . ($poStatus ?? 'unknown'));

        if ($poId) {
            $poCancelRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-orders/' . $poId . '/cancel', [
                'cancellation_reason' => 'Workflow cleanup cancellation to avoid dangling active PO',
            ]);
            check($poCancelRes['status'] === 200, 'Cancel created PO (cleanup)', 'HTTP ' . $poCancelRes['status']);
            $poCancelStatus = dig($poCancelRes['json'], ['purchase_order.status', 'data.status', 'status']);
            check($poCancelStatus === 'cancelled', 'PO status after cancel is cancelled', 'status=' . ($poCancelStatus ?? 'unknown'));
        }
    }
}

section('3. Purchase Request Reject Workflow (create → reject)');
if (!$supplierId) {
    check(false, 'Create PR for rejection path', 'No supplier available');
} else {
    $prRejectCreateRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-requests', [
        'product_name' => 'Workflow PR Reject ' . $runTag,
        'supplier_id' => $supplierId,
        'quantity' => 1,
        'unit_cost' => 299,
        'priority' => 'high',
        'justification' => 'Workflow automation validation for procurement rejection path.',
        'requested_size' => '41',
        'submit_to_finance' => true,
    ]);
    check($prRejectCreateRes['status'] === 201, 'Create purchase request (rejection path)', 'HTTP ' . $prRejectCreateRes['status']);

    $prRejectId = dig($prRejectCreateRes['json'], ['purchase_request.id', 'data.id', 'id']);
    check(!empty($prRejectId), 'PR (rejection path) created with id', 'id=' . ($prRejectId ?? 'none'));

    if ($prRejectId) {
        $prRejectRes = request('POST', $baseUrl . '/api/erp/procurement/purchase-requests/' . $prRejectId . '/reject', [
            'rejection_reason' => 'Workflow test rejection because this request is intentionally invalid for approval.',
        ]);
        check($prRejectRes['status'] === 200, 'Reject purchase request', 'HTTP ' . $prRejectRes['status']);
        $prRejectedStatus = dig($prRejectRes['json'], ['purchase_request.status', 'data.status', 'status']);
        check($prRejectedStatus === 'rejected', 'PR final status is rejected', 'status=' . ($prRejectedStatus ?? 'unknown'));
    }
}

section('4. Stock Request Workflow (create → approve, create → reject)');
if (!$inventoryItemId) {
    check(false, 'Create/approve stock request', 'No inventory item reference available');
    check(false, 'Create/reject stock request', 'No inventory item reference available');
} else {
    $srApproveCreateRes = request('POST', $baseUrl . '/api/erp/procurement/stock-requests', [
        'inventory_item_id' => (int) $inventoryItemId,
        'quantity_needed' => 2,
        'priority' => 'medium',
        'requested_size' => '42',
        'notes' => 'Workflow stock request approval path',
    ]);
    check($srApproveCreateRes['status'] === 201, 'Create stock request (approval path)', 'HTTP ' . $srApproveCreateRes['status']);
    $srApproveId = dig($srApproveCreateRes['json'], ['stock_request.id', 'data.id', 'id']);
    check(!empty($srApproveId), 'Stock request (approval path) created with id', 'id=' . ($srApproveId ?? 'none'));

    if ($srApproveId) {
        $srApproveRes = request('POST', $baseUrl . '/api/erp/procurement/stock-requests/' . $srApproveId . '/approve', [
            'approval_notes' => 'Approved by workflow test',
        ]);
        check($srApproveRes['status'] === 200, 'Approve stock request', 'HTTP ' . $srApproveRes['status']);
        $srApprovedStatus = dig($srApproveRes['json'], ['stock_request.status', 'data.status', 'status']);
        check($srApprovedStatus === 'accepted', 'Stock request status is accepted', 'status=' . ($srApprovedStatus ?? 'unknown'));
    }

    $srRejectCreateRes = request('POST', $baseUrl . '/api/erp/procurement/stock-requests', [
        'inventory_item_id' => (int) $inventoryItemId,
        'quantity_needed' => 1,
        'priority' => 'low',
        'requested_size' => '41',
        'notes' => 'Workflow stock request rejection path',
    ]);
    check($srRejectCreateRes['status'] === 201, 'Create stock request (rejection path)', 'HTTP ' . $srRejectCreateRes['status']);
    $srRejectId = dig($srRejectCreateRes['json'], ['stock_request.id', 'data.id', 'id']);
    check(!empty($srRejectId), 'Stock request (rejection path) created with id', 'id=' . ($srRejectId ?? 'none'));

    if ($srRejectId) {
        $srRejectRes = request('POST', $baseUrl . '/api/erp/procurement/stock-requests/' . $srRejectId . '/reject', [
            'rejection_reason' => 'Workflow test rejection due to non-urgent demand and excess on-hand quantity.',
        ]);
        check($srRejectRes['status'] === 200, 'Reject stock request', 'HTTP ' . $srRejectRes['status']);
        $srRejectedStatus = dig($srRejectRes['json'], ['stock_request.status', 'data.status', 'status']);
        check($srRejectedStatus === 'rejected', 'Stock request status is rejected', 'status=' . ($srRejectedStatus ?? 'unknown'));
    }
}

section('5. Supplier CRUD Workflow (create → update → delete)');
$supplierCreateRes = request('POST', $baseUrl . '/api/erp/procurement/suppliers', [
    'name' => 'Workflow Supplier ' . $runTag,
    'contact_person' => 'Workflow Tester',
    'email' => 'workflow.supplier.' . $runTag . '@example.com',
    'phone' => '09171234567',
    'address' => 'Manila, Philippines',
    'notes' => 'Temporary supplier created by procurement workflow test',
]);
check($supplierCreateRes['status'] === 201, 'Create supplier', 'HTTP ' . $supplierCreateRes['status']);
$tmpSupplierId = dig($supplierCreateRes['json'], ['supplier.id', 'data.id', 'id']);
check(!empty($tmpSupplierId), 'Created supplier id resolved', 'id=' . ($tmpSupplierId ?? 'none'));

if ($tmpSupplierId) {
    $supplierUpdateRes = request('PUT', $baseUrl . '/api/erp/procurement/suppliers/' . $tmpSupplierId, [
        'name' => 'Workflow Supplier Updated ' . $runTag,
        'contact_person' => 'Workflow Tester Updated',
        'email' => 'workflow.supplier.updated.' . $runTag . '@example.com',
        'phone' => '09998887777',
        'address' => 'Quezon City, Philippines',
        'notes' => 'Updated by workflow test',
        'is_active' => true,
    ]);
    check($supplierUpdateRes['status'] === 200, 'Update supplier', 'HTTP ' . $supplierUpdateRes['status']);

    $supplierDeleteRes = request('DELETE', $baseUrl . '/api/erp/procurement/suppliers/' . $tmpSupplierId);
    check($supplierDeleteRes['status'] === 200, 'Delete supplier (cleanup)', 'HTTP ' . $supplierDeleteRes['status']);
}

section('6. Permission Guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'procurement_workflow_unauth_');
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
echo "  PROCUREMENT WORKFLOW TEST RESULTS\n";
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
