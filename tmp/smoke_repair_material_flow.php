<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RepairWorkflowController;
use App\Http\Controllers\ERP\StockRequestApprovalController;
use App\Models\InventoryItem;
use App\Models\RepairRequest;
use App\Models\StockMovement;
use App\Models\StockRequestApproval;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$transactionStarted = false;

try {
    DB::beginTransaction();
    $transactionStarted = true;

    $material = InventoryItem::query()
        ->where('is_active', true)
        ->where('category', 'repair_materials')
        ->where('available_quantity', '>', 0)
        ->orderByDesc('available_quantity')
        ->first();

    if (!$material) {
        $material = InventoryItem::query()
            ->where('is_active', true)
            ->where('available_quantity', '>', 0)
            ->orderByDesc('available_quantity')
            ->first();
    }

    if (!$material) {
        throw new RuntimeException('No eligible inventory_item with available_quantity > 0 exists.');
    }

    $repairer = User::query()
        ->where('shop_owner_id', $material->shop_owner_id)
        ->where('status', 'active')
        ->orderBy('id')
        ->first();

    if (!$repairer) {
        throw new RuntimeException('No active user found for the material\'s shop_owner.');
    }

    $repair = RepairRequest::query()
        ->where('shop_owner_id', $material->shop_owner_id)
        ->where('assigned_repairer_id', $repairer->id)
        ->whereIn('status', ['in_progress', 'awaiting_parts'])
        ->orderByDesc('id')
        ->first();

    if (!$repair) {
        $repair = RepairRequest::query()
            ->where('shop_owner_id', $material->shop_owner_id)
            ->where('assigned_repairer_id', $repairer->id)
            ->orderByDesc('id')
            ->first();
    }

    if ($repair && !in_array((string) $repair->status, ['in_progress', 'awaiting_parts'], true)) {
        $repair->status = 'in_progress';
        $repair->save();
    }

    if (!$repair) {
        $repair = RepairRequest::query()->create([
            'request_id' => 'SMOKE-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999),
            'customer_name' => 'Smoke Test Customer',
            'email' => 'smoke.test@example.com',
            'phone' => '09170000000',
            'shoe_type' => 'Sneakers',
            'brand' => 'Smoke Brand',
            'description' => 'Temporary smoke test repair request',
            'shop_owner_id' => $material->shop_owner_id,
            'user_id' => $repairer->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'in_progress',
            'delivery_method' => 'walk_in',
            'total' => 500,
        ]);
    }

    if ($material->category !== 'repair_materials') {
        $material->category = 'repair_materials';
        $material->save();
    }

    $material = InventoryItem::query()
        ->where('id', $material->id)
        ->where('shop_owner_id', $repair->shop_owner_id)
        ->where('is_active', true)
        ->where('available_quantity', '>', 0)
        ->first();

    if (!$material) {
        throw new RuntimeException('No eligible inventory_item with available_quantity > 0 for the same shop_owner.');
    }

    $quantityUsed = 1;
    $beforeQty = (int) $material->available_quantity;

    if ($beforeQty < $quantityUsed) {
        throw new RuntimeException('Material found but insufficient stock for smoke test quantity.');
    }

    Auth::shouldUse('user');
    Auth::guard('user')->setUser($repairer);

    /** @var RepairWorkflowController $repairWorkflowController */
    $repairWorkflowController = $app->make(RepairWorkflowController::class);

    $logRequest = Request::create(
        "/api/repairer/repairs/{$repair->id}/materials",
        'POST',
        [
            'inventory_item_id' => $material->id,
            'quantity_used' => $quantityUsed,
            'notes' => 'Smoke test: repair material usage logging',
        ]
    );

    $logResponse = $repairWorkflowController->logRepairMaterialUsage($logRequest, $repair->id);
    $logPayload = $logResponse->getData(true);

    $material->refresh();
    $afterQty = (int) $material->available_quantity;

    $usageId = $logPayload['data']['id'] ?? null;
    $movementId = $logPayload['data']['stock_movement_id'] ?? null;

    $movement = $movementId ? StockMovement::query()->find($movementId) : null;

    $createRequest = Request::create(
        '/api/repairer/material-requests',
        'POST',
        [
            'inventory_item_id' => $material->id,
            'quantity_needed' => 2,
            'priority' => 'medium',
            'notes' => 'Smoke test: repair-linked material request',
            'repair_request_id' => $repair->id,
        ]
    );

    $createResponse = $repairWorkflowController->createMaterialRequest($createRequest);
    $createPayload = $createResponse->getData(true);

    $stockRequestId = $createPayload['data']['id'] ?? null;
    $createdStockRequest = $stockRequestId
        ? StockRequestApproval::query()->with('requester')->find($stockRequestId)
        : null;

    $myRequestsResponse = $repairWorkflowController->myMaterialRequests(Request::create('/api/repairer/material-requests', 'GET'));
    $myRequestsPayload = $myRequestsResponse->getData(true);
    $myRequestIds = array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        $myRequestsPayload['data'] ?? []
    );

    /** @var StockRequestApprovalController $stockRequestController */
    $stockRequestController = $app->make(StockRequestApprovalController::class);
    $procurementListResponse = $stockRequestController->index(
        Request::create('/api/erp/procurement/stock-requests', 'GET', [
            'search' => $createdStockRequest?->request_number,
            'per_page' => 15,
        ])
    );

    $procurementListPayload = $procurementListResponse->getData(true);
    $procurementRows = $procurementListPayload['data'] ?? [];

    $procurementHit = null;
    foreach ($procurementRows as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $stockRequestId) {
            $procurementHit = $row;
            break;
        }
    }

    $checks = [
        'usage_logged_success' => ($logPayload['success'] ?? false) === true && !empty($usageId),
        'stock_deducted' => $afterQty === ($beforeQty - $quantityUsed),
        'stock_movement_created' => $movement !== null,
        'stock_movement_reference_matches_repair' => $movement !== null
            && (int) ($movement->reference_id ?? 0) === (int) $repair->id,
        'repair_request_created_success' => ($createPayload['success'] ?? false) === true && !empty($stockRequestId),
        'repair_context_saved' => $createdStockRequest !== null
            && ($createdStockRequest->request_source === 'repair')
            && ((int) $createdStockRequest->repair_request_id === (int) $repair->id),
        'visible_in_my_material_requests' => in_array((int) $stockRequestId, $myRequestIds, true),
        'visible_in_procurement_list' => $procurementHit !== null,
        'procurement_has_source_and_requester' => $procurementHit !== null
            && (($procurementHit['request_source'] ?? null) === 'repair')
            && !empty($procurementHit['requester']['name'] ?? null),
    ];

    $derivedRequesterRole = (($procurementHit['request_source'] ?? 'manual') === 'repair') ? 'Repairer' : 'Staff';

    $result = [
        'repair_id' => (int) $repair->id,
        'repair_request_number' => (string) ($repair->request_id ?? ''),
        'repairer_id' => (int) $repairer->id,
        'material_id' => (int) $material->id,
        'material_category' => (string) ($material->category ?? ''),
        'before_qty' => $beforeQty,
        'after_qty' => $afterQty,
        'usage_id' => $usageId,
        'stock_movement_id' => $movementId,
        'stock_request_id' => $stockRequestId,
        'stock_request_number' => (string) ($createdStockRequest->request_number ?? ''),
        'procurement_row_request_source' => $procurementHit['request_source'] ?? null,
        'procurement_row_requester_name' => $procurementHit['requester']['name'] ?? null,
        'derived_requester_role' => $derivedRequesterRole,
        'checks' => $checks,
        'all_checks_passed' => !in_array(false, $checks, true),
        'note' => 'All DB writes from this script are rolled back intentionally.',
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    DB::rollBack();
} catch (Throwable $e) {
    if ($transactionStarted) {
        DB::rollBack();
    }

    $error = [
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
