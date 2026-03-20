<?php
/**
 * Deterministic procurement workflow smoke test (direct app-level, no HTTP login/cURL).
 *
 * Run:
 *   php tmp/test_procurement_workflow.php
 * Optional env override:
 *   set PROCUREMENT_SMOKE_EMAIL=procurement.2@solespace.com
 */

declare(strict_types=1);

use App\Http\Controllers\ERP\SupplierController;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$results = [];
$sectionOpen = '';
$runTag = date('YmdHis');
$createdPurchaseRequestIds = [];
$createdPurchaseOrderIds = [];
$createdStockRequestIds = [];
$createdSupplierIds = [];
$createdInventoryItemIds = [];

function section(string $name): void
{
    global $sectionOpen;
    $sectionOpen = $name;
    echo "\n╔══════════════════════════════════════════════════════════════\n";
    echo "║  {$name}\n";
    echo "╚══════════════════════════════════════════════════════════════\n";
}

function check(bool $condition, string $name, string $detail = ''): void
{
    global $results, $sectionOpen;
    $label = $condition ? 'PASS' : 'FAIL';
    echo '  [' . $label . "] {$name}";
    if ($detail) {
        echo " → {$detail}";
    }
    echo "\n";

    $results[] = [
        'section' => $sectionOpen,
        'name' => $name,
        'pass' => $condition,
        'detail' => $detail,
    ];
}

function note(string $message): void
{
    echo "  [INFO] {$message}\n";
}

function purchaseRequestNumber(int $shopOwnerId): string
{
    $year = date('Y');
    $lastPR = PurchaseRequest::where('shop_owner_id', $shopOwnerId)
        ->where('pr_number', 'LIKE', "PR-{$year}-%")
        ->orderBy('pr_number', 'desc')
        ->first();

    if ($lastPR) {
        $lastNumber = intval(substr((string) $lastPR->pr_number, -3));
        $newNumber = str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '001';
    }

    return "PR-{$year}-{$newNumber}";
}

function purchaseOrderNumber(int $shopOwnerId): string
{
    $year = date('Y');
    $lastPO = PurchaseOrder::where('shop_owner_id', $shopOwnerId)
        ->where('po_number', 'LIKE', "PO-{$year}-%")
        ->orderBy('po_number', 'desc')
        ->first();

    if ($lastPO) {
        $lastNumber = intval(substr((string) $lastPO->po_number, -3));
        $newNumber = str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '001';
    }

    return "PO-{$year}-{$newNumber}";
}

function stockRequestNumber(): string
{
    $year = date('Y');
    $lastSR = StockRequestApproval::where('request_number', 'LIKE', "SR-{$year}-%")
        ->orderBy('request_number', 'desc')
        ->first();

    if ($lastSR) {
        $lastNumber = intval(substr((string) $lastSR->request_number, -3));
        $newNumber = str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '001';
    }

    return "SR-{$year}-{$newNumber}";
}

function userRequest(string $method, string $uri, User $user, array $payload = []): Request
{
    $request = Request::create($uri, $method, $payload);
    $request->setUserResolver(fn () => $user);
    return $request;
}

try {
    section('0. Local Authentication Context');

    $preferredEmail = getenv('PROCUREMENT_SMOKE_EMAIL') ?: 'procurement.2@solespace.com';

    $user = User::query()
        ->where('email', $preferredEmail)
        ->first();

    if (!$user) {
        $user = User::query()
            ->whereNotNull('shop_owner_id')
            ->orderBy('id')
            ->first();
    }

    check($user instanceof User, 'Resolved local seeded user', $user ? "user_id={$user->id}, email={$user->email}" : 'none');

    if (!$user || !$user->shop_owner_id) {
        throw new RuntimeException('No suitable user with shop_owner_id was found for procurement smoke test.');
    }

    Auth::shouldUse('user');
    Auth::guard('user')->setUser($user);

    check(Auth::check(), 'Auth guard established', 'guard=user');

    section('1. Reference Data');

    $supplierController = app(SupplierController::class);
    $supplierId = null;

    $supplierIndexResponse = $supplierController->index(userRequest('GET', '/api/erp/procurement/suppliers', $user));
    $supplierIndexData = $supplierIndexResponse->getData(true);
    $supplierRows = $supplierIndexData['data'] ?? [];

    if (is_array($supplierRows) && count($supplierRows) > 0) {
        $supplierId = (int) ($supplierRows[0]['id'] ?? 0);
    }

    if (!$supplierId) {
        $setupSupplierResponse = $supplierController->store(userRequest('POST', '/api/erp/procurement/suppliers', $user, [
            'name' => 'Workflow Setup Supplier ' . $runTag,
            'contact_person' => 'Workflow Setup',
            'email' => 'workflow.setup.' . $runTag . '@example.com',
            'phone' => '09170000000',
            'address' => 'Metro Manila, Philippines',
            'notes' => 'Auto-created setup supplier for workflow test prerequisites',
        ]));

        if ($setupSupplierResponse->getStatusCode() === 201) {
            $setupSupplierData = $setupSupplierResponse->getData(true);
            $supplierId = (int) (($setupSupplierData['supplier']['id'] ?? 0));
            if ($supplierId > 0) {
                $createdSupplierIds[] = $supplierId;
                note('Created setup supplier for references: id=' . $supplierId);
            }
        }
    }

    check($supplierId > 0, 'Found supplier reference', 'supplier_id=' . ($supplierId ?: 'none'));

    $inventoryItemId = (int) (InventoryItem::query()
        ->where('shop_owner_id', $user->shop_owner_id)
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->value('id') ?? 0);

    if (!$inventoryItemId) {
        $inventoryItem = InventoryItem::create([
            'shop_owner_id' => $user->shop_owner_id,
            'name' => 'Workflow Procurement Item ' . $runTag,
            'sku' => 'WF-PROC-' . $runTag,
            'category' => 'repair_materials',
            'unit' => 'pcs',
            'available_quantity' => 100,
            'reserved_quantity' => 0,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
            'cost_price' => 25.00,
            'price' => 40.00,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $inventoryItemId = (int) $inventoryItem->id;
        $createdInventoryItemIds[] = $inventoryItemId;
        note('Created workflow inventory item id=' . $inventoryItemId);
    }

    check($inventoryItemId > 0, 'Found inventory item reference for stock request create', 'inventory_item_id=' . ($inventoryItemId ?: 'none'));

    section('2. Purchase Request Workflow (create → approve → approve → PO create)');

    if (!$supplierId) {
        check(false, 'Create PR for approval path', 'No supplier available');
    } else {
        $purchaseRequest = PurchaseRequest::create([
            'pr_number' => purchaseRequestNumber((int) $user->shop_owner_id),
            'shop_owner_id' => $user->shop_owner_id,
            'supplier_id' => $supplierId,
            'product_name' => 'Workflow PR Approve ' . $runTag,
            'inventory_item_id' => $inventoryItemId,
            'requested_size' => '42',
            'quantity' => 2,
            'unit_cost' => 450,
            'total_cost' => 900,
            'priority' => 'medium',
            'justification' => 'Workflow automation validation for procurement approval path.',
            'status' => 'pending_finance',
            'requested_by' => $user->id,
            'requested_date' => now(),
        ]);

        $createdPurchaseRequestIds[] = (int) $purchaseRequest->id;

        check((int) $purchaseRequest->id > 0, 'Create purchase request (approval path)', 'id=' . $purchaseRequest->id);
        check($purchaseRequest->status === 'pending_finance', 'PR initial status is pending_finance', 'status=' . $purchaseRequest->status);

        $financeApproveOk = $purchaseRequest->approve((int) $user->id, 'Finance-stage approval in workflow test');
        $purchaseRequest->refresh();
        check($financeApproveOk, 'Approve PR (finance stage)', 'status=' . $purchaseRequest->status);
        check($purchaseRequest->status === 'pending_shop_owner', 'PR moved to pending_shop_owner', 'status=' . $purchaseRequest->status);

        $finalApproveOk = $purchaseRequest->approve((int) $user->id, 'Final approval in workflow test');
        $purchaseRequest->refresh();
        check($finalApproveOk, 'Approve PR (shop owner/final stage)', 'status=' . $purchaseRequest->status);
        check($purchaseRequest->status === 'approved', 'PR final status is approved', 'status=' . $purchaseRequest->status);

        $purchaseOrder = PurchaseOrder::create([
            'po_number' => purchaseOrderNumber((int) $user->shop_owner_id),
            'pr_id' => $purchaseRequest->id,
            'shop_owner_id' => $user->shop_owner_id,
            'supplier_id' => $purchaseRequest->supplier_id,
            'product_name' => $purchaseRequest->product_name,
            'inventory_item_id' => $purchaseRequest->inventory_item_id,
            'requested_size' => $purchaseRequest->requested_size,
            'quantity' => $purchaseRequest->quantity,
            'unit_cost' => $purchaseRequest->unit_cost,
            'total_cost' => $purchaseRequest->total_cost,
            'expected_delivery_date' => now()->addDays(3)->toDateString(),
            'payment_terms' => 'Net 30',
            'status' => 'draft',
            'ordered_by' => $user->id,
            'ordered_date' => now(),
            'notes' => 'PO created by workflow test',
        ]);

        $createdPurchaseOrderIds[] = (int) $purchaseOrder->id;

        check((int) $purchaseOrder->id > 0, 'Create PO from approved PR', 'id=' . $purchaseOrder->id);
        check($purchaseOrder->status === 'draft', 'PO initial status is draft', 'status=' . $purchaseOrder->status);

        $cancelOk = $purchaseOrder->cancel((int) $user->id, 'Workflow cleanup cancellation to avoid dangling active PO');
        $purchaseOrder->refresh();
        check($cancelOk, 'Cancel created PO (cleanup)', 'status=' . $purchaseOrder->status);
        check($purchaseOrder->status === 'cancelled', 'PO status after cancel is cancelled', 'status=' . $purchaseOrder->status);
    }

    section('3. Purchase Request Reject Workflow (create → reject)');

    if (!$supplierId) {
        check(false, 'Create PR for rejection path', 'No supplier available');
    } else {
        $rejectRequest = PurchaseRequest::create([
            'pr_number' => purchaseRequestNumber((int) $user->shop_owner_id),
            'shop_owner_id' => $user->shop_owner_id,
            'supplier_id' => $supplierId,
            'product_name' => 'Workflow PR Reject ' . $runTag,
            'inventory_item_id' => $inventoryItemId,
            'requested_size' => '41',
            'quantity' => 1,
            'unit_cost' => 299,
            'total_cost' => 299,
            'priority' => 'high',
            'justification' => 'Workflow automation validation for procurement rejection path.',
            'status' => 'pending_finance',
            'requested_by' => $user->id,
            'requested_date' => now(),
        ]);

        $createdPurchaseRequestIds[] = (int) $rejectRequest->id;

        check((int) $rejectRequest->id > 0, 'Create purchase request (rejection path)', 'id=' . $rejectRequest->id);

        $rejectOk = $rejectRequest->reject((int) $user->id, 'Workflow test rejection because this request is intentionally invalid for approval.');
        $rejectRequest->refresh();

        check($rejectOk, 'Reject purchase request', 'status=' . $rejectRequest->status);
        check($rejectRequest->status === 'rejected', 'PR final status is rejected', 'status=' . $rejectRequest->status);
    }

    section('4. Stock Request Workflow (create → approve, create → reject)');

    if (!$inventoryItemId) {
        check(false, 'Create/approve stock request', 'No inventory item reference available');
        check(false, 'Create/reject stock request', 'No inventory item reference available');
    } else {
        $stockApprove = StockRequestApproval::create([
            'request_number' => stockRequestNumber(),
            'shop_owner_id' => $user->shop_owner_id,
            'inventory_item_id' => $inventoryItemId,
            'product_name' => (string) (InventoryItem::find($inventoryItemId)?->name ?? ('Workflow Item ' . $runTag)),
            'sku_code' => (string) (InventoryItem::find($inventoryItemId)?->sku ?? ('WF-SKU-' . $runTag)),
            'quantity_needed' => 2,
            'requested_size' => '42',
            'priority' => 'medium',
            'request_source' => 'manual',
            'status' => 'pending',
            'requested_by' => $user->id,
            'requested_date' => now(),
            'notes' => 'Workflow stock request approval path',
        ]);

        $createdStockRequestIds[] = (int) $stockApprove->id;

        check((int) $stockApprove->id > 0, 'Create stock request (approval path)', 'id=' . $stockApprove->id);

        $stockApproveOk = $stockApprove->approve((int) $user->id, 'Approved by workflow test');
        $stockApprove->refresh();

        check($stockApproveOk, 'Approve stock request', 'status=' . $stockApprove->status);
        check($stockApprove->status === 'accepted', 'Stock request status is accepted', 'status=' . $stockApprove->status);

        $stockReject = StockRequestApproval::create([
            'request_number' => stockRequestNumber(),
            'shop_owner_id' => $user->shop_owner_id,
            'inventory_item_id' => $inventoryItemId,
            'product_name' => (string) (InventoryItem::find($inventoryItemId)?->name ?? ('Workflow Item ' . $runTag)),
            'sku_code' => (string) (InventoryItem::find($inventoryItemId)?->sku ?? ('WF-SKU-' . $runTag)),
            'quantity_needed' => 1,
            'requested_size' => '41',
            'priority' => 'low',
            'request_source' => 'manual',
            'status' => 'pending',
            'requested_by' => $user->id,
            'requested_date' => now(),
            'notes' => 'Workflow stock request rejection path',
        ]);

        $createdStockRequestIds[] = (int) $stockReject->id;

        check((int) $stockReject->id > 0, 'Create stock request (rejection path)', 'id=' . $stockReject->id);

        $stockRejectOk = $stockReject->reject((int) $user->id, 'Workflow test rejection due to non-urgent demand and excess on-hand quantity.');
        $stockReject->refresh();

        check($stockRejectOk, 'Reject stock request', 'status=' . $stockReject->status);
        check($stockReject->status === 'rejected', 'Stock request status is rejected', 'status=' . $stockReject->status);
    }

    section('5. Supplier CRUD Workflow (create → update → delete)');

    $tmpSupplierId = 0;

    $supplierCreateResponse = $supplierController->store(userRequest('POST', '/api/erp/procurement/suppliers', $user, [
        'name' => 'Workflow Supplier ' . $runTag,
        'contact_person' => 'Workflow Tester',
        'email' => 'workflow.supplier.' . $runTag . '@example.com',
        'phone' => '09171234567',
        'address' => 'Manila, Philippines',
        'notes' => 'Temporary supplier created by procurement workflow test',
    ]));

    check($supplierCreateResponse->getStatusCode() === 201, 'Create supplier', 'HTTP ' . $supplierCreateResponse->getStatusCode());

    $supplierCreatePayload = $supplierCreateResponse->getData(true);
    $tmpSupplierId = (int) ($supplierCreatePayload['supplier']['id'] ?? 0);
    check($tmpSupplierId > 0, 'Created supplier id resolved', 'id=' . ($tmpSupplierId ?: 'none'));

    if ($tmpSupplierId > 0) {
        $createdSupplierIds[] = $tmpSupplierId;

        $supplierUpdateResponse = $supplierController->update(
            userRequest('PUT', '/api/erp/procurement/suppliers/' . $tmpSupplierId, $user, [
                'name' => 'Workflow Supplier Updated ' . $runTag,
                'contact_person' => 'Workflow Tester Updated',
                'email' => 'workflow.supplier.updated.' . $runTag . '@example.com',
                'phone' => '09998887777',
                'address' => 'Quezon City, Philippines',
                'notes' => 'Updated by workflow test',
                'is_active' => true,
            ]),
            $tmpSupplierId
        );

        check($supplierUpdateResponse->getStatusCode() === 200, 'Update supplier', 'HTTP ' . $supplierUpdateResponse->getStatusCode());

        $supplierDeleteRequest = userRequest('DELETE', '/api/erp/procurement/suppliers/' . $tmpSupplierId, $user);
        app()->instance('request', $supplierDeleteRequest);
        $supplierDeleteResponse = $supplierController->destroy($tmpSupplierId);
        check($supplierDeleteResponse->getStatusCode() === 200, 'Delete supplier (cleanup)', 'HTTP ' . $supplierDeleteResponse->getStatusCode());
    }

    section('6. Permission Guard (no user resolver)');

    $guardBlocked = false;
    try {
        $supplierController->index(Request::create('/api/erp/procurement/suppliers', 'GET'));
    } catch (Throwable $t) {
        $guardBlocked = true;
    }

    check($guardBlocked, 'Unauthenticated direct call blocked', $guardBlocked ? 'exception thrown as expected' : 'unexpectedly allowed');
} catch (Throwable $e) {
    check(false, 'Fatal test execution error', $e->getMessage());
} finally {
    // Cleanup test artifacts in reverse dependency order.
    if (!empty($createdPurchaseOrderIds)) {
        PurchaseOrder::withTrashed()->whereIn('id', $createdPurchaseOrderIds)->get()->each(function (PurchaseOrder $po): void {
            $po->forceDelete();
        });
    }

    if (!empty($createdPurchaseRequestIds)) {
        PurchaseRequest::withTrashed()->whereIn('id', $createdPurchaseRequestIds)->get()->each(function (PurchaseRequest $pr): void {
            $pr->forceDelete();
        });
    }

    if (!empty($createdStockRequestIds)) {
        StockRequestApproval::withTrashed()->whereIn('id', $createdStockRequestIds)->get()->each(function (StockRequestApproval $sr): void {
            $sr->forceDelete();
        });
    }

    if (!empty($createdSupplierIds)) {
        Supplier::withTrashed()->whereIn('id', $createdSupplierIds)->get()->each(function (Supplier $supplier): void {
            $supplier->forceDelete();
        });
    }

    if (!empty($createdInventoryItemIds)) {
        InventoryItem::withTrashed()->whereIn('id', $createdInventoryItemIds)->get()->each(function (InventoryItem $item): void {
            $item->forceDelete();
        });
    }
}

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) {
        $pass++;
    } else {
        $fail++;
    }
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
        if (!$result['pass']) {
            echo '- [' . $result['section'] . '] ' . $result['name'];
            if ($result['detail']) {
                echo ' → ' . $result['detail'];
            }
            echo "\n";
        }
    }
}

exit($fail > 0 ? 1 : 0);
