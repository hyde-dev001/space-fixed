<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequestApproval;
use App\Models\ReplenishmentRequest;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockRequestApprovalService
{
    private const OPEN_STOCK_REQUEST_STATUSES = ['pending', 'accepted', 'needs_details'];

    private const OPEN_REPLENISHMENT_STATUSES = ['pending', 'accepted', 'needs_details'];

    private const OPEN_PURCHASE_REQUEST_STATUSES = [
        'draft',
        'pending_finance',
        'pending_shop_owner',
        'pending_finance_final',
        'approved',
    ];

    private const OPEN_PURCHASE_ORDER_STATUSES = [
        'draft',
        'sent',
        'confirmed',
        'in_transit',
        'partially_received',
    ];

    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Persist a manually submitted request through the same path used by
     * automatic requests.
     */
    public function createStockRequest(array $data): StockRequestApproval
    {
        $stockRequest = DB::transaction(function () use ($data): StockRequestApproval {
            $inventoryItem = InventoryItem::query()
                ->where('shop_owner_id', (int) $data['shop_owner_id'])
                ->lockForUpdate()
                ->findOrFail((int) $data['inventory_item_id']);

            return $this->persistStockRequest($inventoryItem, $data);
        }, 3);

        $stockRequest = $stockRequest->fresh(['inventoryItem', 'requester']);
        $this->notifyStockRequestSubmitted($stockRequest);

        return $stockRequest;
    }

    /**
     * Create the uncovered portion of an item's automatic replenishment need.
     */
    public function createAutomaticStockRequest(InventoryItem $item, string $priority): ?StockRequestApproval
    {
        $stockRequest = DB::transaction(function () use ($item, $priority): ?StockRequestApproval {
            $lockedItem = InventoryItem::query()
                ->where('shop_owner_id', $item->shop_owner_id)
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedItem->auto_stock_request_enabled
                || $lockedItem->available_quantity > $lockedItem->reorder_level) {
                return null;
            }

            $reorderQuantity = max(0, (int) $lockedItem->reorder_quantity);
            if ($reorderQuantity === 0) {
                return null;
            }

            $coveredQuantity = $this->openStockRequestQuantity($lockedItem)
                + $this->openReplenishmentQuantity($lockedItem)
                + $this->openPurchaseRequestQuantity($lockedItem)
                + $this->openPurchaseOrderQuantity($lockedItem);
            $quantityNeeded = max(0, $reorderQuantity - $coveredQuantity);

            if ($quantityNeeded === 0) {
                return null;
            }

            return $this->persistStockRequest($lockedItem, [
                'shop_owner_id' => $lockedItem->shop_owner_id,
                'quantity_needed' => $quantityNeeded,
                'priority' => $priority,
                'request_source' => 'manual',
                'status' => 'pending',
                'requested_by' => null,
                'requested_date' => now(),
                'notes' => 'Automatically created by the low-stock check.',
                'is_auto_generated' => true,
            ]);
        }, 3);

        if (! $stockRequest) {
            return null;
        }

        $stockRequest = $stockRequest->fresh(['inventoryItem', 'requester']);
        $this->notifyStockRequestSubmitted($stockRequest);

        Log::info('Automatic stock request created.', [
            'request_id' => $stockRequest->id,
            'inventory_item_id' => $stockRequest->inventory_item_id,
            'quantity_needed' => $stockRequest->quantity_needed,
        ]);

        return $stockRequest;
    }

    private function persistStockRequest(InventoryItem $inventoryItem, array $data): StockRequestApproval
    {
        $year = now()->year;
        $last = StockRequestApproval::query()
            ->where('request_number', 'LIKE', 'SR-' . $year . '-%')
            ->lockForUpdate()
            ->orderByDesc('request_number')
            ->first();
        $nextNumber = $last ? ((int) substr($last->request_number, -3)) + 1 : 1;

        return StockRequestApproval::create([
            'request_number' => 'SR-' . $year . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT),
            'shop_owner_id' => $inventoryItem->shop_owner_id,
            'inventory_item_id' => $inventoryItem->id,
            'repair_request_id' => $data['repair_request_id'] ?? null,
            'product_name' => $inventoryItem->name,
            'sku_code' => $inventoryItem->sku ?? '',
            'quantity_needed' => (int) $data['quantity_needed'],
            'requested_size' => $data['requested_size'] ?? null,
            'requested_color' => $data['requested_color'] ?? null,
            'priority' => $data['priority'],
            'request_source' => $data['request_source'] ?? 'manual',
            'status' => $data['status'] ?? 'pending',
            'requested_by' => $data['requested_by'] ?? null,
            'requested_date' => $data['requested_date'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'is_auto_generated' => (bool) ($data['is_auto_generated'] ?? false),
        ]);
    }

    private function openStockRequestQuantity(InventoryItem $item): int
    {
        return (int) StockRequestApproval::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_STOCK_REQUEST_STATUSES)
            ->whereDoesntHave('purchaseRequest', function ($query): void {
                $query->whereIn('status', self::OPEN_PURCHASE_REQUEST_STATUSES);
            })
            ->sum('quantity_needed');
    }

    private function openReplenishmentQuantity(InventoryItem $item): int
    {
        return (int) ReplenishmentRequest::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_REPLENISHMENT_STATUSES)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('stock_request_approvals')
                    ->whereColumn(
                        'stock_request_approvals.request_number',
                        'replenishment_requests.request_number',
                    );
            })
            ->sum('quantity_needed');
    }

    private function openPurchaseRequestQuantity(InventoryItem $item): int
    {
        return (int) PurchaseRequest::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_PURCHASE_REQUEST_STATUSES)
            ->whereDoesntHave('purchaseOrders', function ($query): void {
                $query->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES);
            })
            ->whereDoesntHave('purchaseOrderItems', function ($query): void {
                $query->whereHas('purchaseOrder', function ($purchaseOrderQuery): void {
                    $purchaseOrderQuery->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES);
                });
            })
            ->sum('quantity');
    }

    private function openPurchaseOrderQuantity(InventoryItem $item): int
    {
        $itemQuantity = PurchaseOrderItem::query()
            ->with('receiptItems.receipt')
            ->where('inventory_item_id', $item->id)
            ->whereHas('purchaseOrder', function ($query) use ($item): void {
                $query->where('shop_owner_id', $item->shop_owner_id)
                    ->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES);
            })
            ->get()
            ->sum(function (PurchaseOrderItem $purchaseOrderItem): int {
                $acceptedQuantity = $purchaseOrderItem->receiptItems
                    ->filter(fn ($receiptItem) => $receiptItem->receipt?->status === 'posted')
                    ->sum('accepted_quantity');

                return max(0, (int) $purchaseOrderItem->ordered_quantity - (int) $acceptedQuantity);
            });

        $legacyQuantity = PurchaseOrder::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES)
            ->whereDoesntHave('items')
            ->get(['quantity', 'received_quantity', 'defective_quantity'])
            ->sum(function (PurchaseOrder $purchaseOrder): int {
                $receivedQuantity = (int) ($purchaseOrder->received_quantity ?? 0);
                $defectiveQuantity = (int) ($purchaseOrder->defective_quantity ?? 0);
                $acceptedQuantity = max(0, $receivedQuantity - $defectiveQuantity);

                return max(0, (int) $purchaseOrder->quantity - $acceptedQuantity);
            });

        return (int) $itemQuantity + (int) $legacyQuantity;
    }

    /**
     * Approve a stock request.
     */
    public function approveStockRequest(int $requestId, int $userId, ?string $notes = null): StockRequestApproval
    {
        DB::beginTransaction();

        try {
            $stockRequest = StockRequestApproval::findOrFail($requestId);

            if (!$stockRequest->canBeApproved()) {
                throw new \Exception('Stock request cannot be approved in its current state.');
            }

            $stockRequest->approve($userId, $notes);

            DB::commit();

            Log::info('Stock request approved', [
                'request_id' => $requestId,
                'request_number' => $stockRequest->request_number,
                'approved_by' => $userId
            ]);

            $freshStockRequest = $stockRequest->fresh();
            $this->dispatchStockRequestApprovedNotifications($freshStockRequest, $userId, $notes);

            return $freshStockRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve stock request', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reject a stock request.
     */
    public function rejectStockRequest(int $requestId, int $userId, string $reason): StockRequestApproval
    {
        DB::beginTransaction();

        try {
            $stockRequest = StockRequestApproval::findOrFail($requestId);

            if (!$stockRequest->canBeRejected()) {
                throw new \Exception('Stock request cannot be rejected in its current state.');
            }

            $stockRequest->reject($userId, $reason);

            DB::commit();

            Log::info('Stock request rejected', [
                'request_id' => $requestId,
                'request_number' => $stockRequest->request_number,
                'rejected_by' => $userId,
                'reason' => $reason
            ]);

            $freshStockRequest = $stockRequest->fresh();
            $this->dispatchStockRequestRejectedNotifications($freshStockRequest, $userId, $reason);

            return $freshStockRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject stock request', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Request additional details for a stock request.
     */
    public function requestDetails(int $requestId, int $userId, string $notes): StockRequestApproval
    {
        DB::beginTransaction();

        try {
            $stockRequest = StockRequestApproval::findOrFail($requestId);

            $stockRequest->requestDetails($userId, $notes);

            DB::commit();

            Log::info('Additional details requested for stock request', [
                'request_id' => $requestId,
                'request_number' => $stockRequest->request_number,
                'requested_by' => $userId
            ]);

            $freshStockRequest = $stockRequest->fresh();
            $this->dispatchStockRequestNeedsDetailsNotifications($freshStockRequest, $userId, $notes);

            return $freshStockRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to request additional details', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get metrics for stock request approvals.
     */
    public function getMetrics(int $shopOwnerId): array
    {
        return [
            'total_stock_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->pending()->count(),
            'accepted_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->accepted()->count(),
            'rejected_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->rejected()->count(),
            'needs_details' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->needsDetails()->count(),
            'high_priority_pending' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)
                ->pending()
                ->where('priority', 'high')
                ->count(),
        ];
    }

    /**
     * Auto-create purchase request from approved stock request.
     */
    public function autoCreatePR(int $requestId, int $supplierId, float $unitCost, string $justification): ?PurchaseRequest
    {
        DB::beginTransaction();

        try {
            $stockRequest = StockRequestApproval::findOrFail($requestId);

            if ($stockRequest->status !== 'accepted') {
                throw new \Exception('Only accepted stock requests can be converted to purchase requests.');
            }
            if ($stockRequest->request_source === 'repair' && !$stockRequest->inventory_approved_date) {
                throw new \Exception('Repair material request must be approved by Inventory first before procurement processing.');
            }


            // Create PR data from stock request
            $prData = [
                'shop_owner_id' => $stockRequest->shop_owner_id,
                'supplier_id' => $supplierId,
                'product_name' => $stockRequest->product_name,
                'inventory_item_id' => $stockRequest->inventory_item_id,
                'requested_size' => $stockRequest->requested_size,
                'requested_color' => $stockRequest->requested_color,
                'quantity' => $stockRequest->quantity_needed,
                'unit_cost' => $unitCost,
                'priority' => $stockRequest->priority,
                'justification' => $justification,
                'requested_by' => $stockRequest->approved_by ?? $stockRequest->requested_by,
                'requested_date' => now(),
                'notes' => "Auto-created from Stock Request: {$stockRequest->request_number}",
            ];

            $purchaseRequestService = new PurchaseRequestService();
            $purchaseRequest = $purchaseRequestService->createPurchaseRequest($prData);

            DB::commit();

            Log::info('Purchase request auto-created from stock request', [
                'stock_request_id' => $requestId,
                'stock_request_number' => $stockRequest->request_number,
                'pr_id' => $purchaseRequest->id,
                'pr_number' => $purchaseRequest->pr_number
            ]);

            return $purchaseRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to auto-create PR from stock request', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get urgent stock requests requiring approval.
     */
    public function getUrgentRequests(int $shopOwnerId): \Illuminate\Database\Eloquent\Collection
    {
        return StockRequestApproval::where('shop_owner_id', $shopOwnerId)
            ->where('priority', 'high')
            ->whereIn('status', ['pending', 'needs_details'])
            ->orderBy('requested_date', 'asc')
            ->get();
    }

    /**
     * Bulk approve multiple stock requests.
     */
    public function bulkApprove(array $requestIds, int $userId, ?string $notes = null): array
    {
        $results = [
            'approved' => [],
            'failed' => [],
        ];

        foreach ($requestIds as $requestId) {
            try {
                $stockRequest = $this->approveStockRequest($requestId, $userId, $notes);
                $results['approved'][] = $stockRequest;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'request_id' => $requestId,
                    'error' => $e->getMessage()
                ];
            }
        }

        Log::info('Bulk stock request approval completed', [
            'approved_count' => count($results['approved']),
            'failed_count' => count($results['failed']),
            'approved_by' => $userId
        ]);

        return $results;
    }

    public function notifyStockRequestSubmitted(StockRequestApproval $stockRequest): void
    {
        $payload = $this->buildStockRequestNotificationData($stockRequest, null, null);

        $this->sendToProcurementOrFinance(
            shopOwnerId: (int) $stockRequest->shop_owner_id,
            type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
            title: 'New Stock Request Submitted',
            message: "Stock request {$payload['request_number']} for {$payload['product_name']} (Qty: {$payload['quantity_needed']}) needs review.",
            data: $payload,
            actionUrl: "/erp/inventory/request-material-approval?stock_request={$stockRequest->id}",
            priority: $stockRequest->priority === 'high' ? 'high' : 'medium'
        );
    }

    public function notifyStockRequestForwardedToProcurement(StockRequestApproval $stockRequest, int $inventoryApproverId): void
    {
        $payload = $this->buildStockRequestNotificationData($stockRequest, $inventoryApproverId, null);

        $this->sendToProcurementOrFinance(
            shopOwnerId: (int) $stockRequest->shop_owner_id,
            type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
            title: 'Repair Material Request Forwarded',
            message: "Repair material request {$payload['request_number']} for {$payload['product_name']} is now ready for procurement approval.",
            data: $payload,
            actionUrl: "/erp/inventory/request-material-approval?stock_request={$stockRequest->id}",
            priority: $stockRequest->priority === 'high' ? 'high' : 'medium'
        );
    }

    private function dispatchStockRequestApprovedNotifications(StockRequestApproval $stockRequest, int $approverId, ?string $notes): void
    {
        $requesterId = (int) ($stockRequest->requested_by ?? 0);
        if ($requesterId <= 0) {
            return;
        }

        $payload = $this->buildStockRequestNotificationData($stockRequest, $approverId, $notes);

        $this->notificationService->sendToUser(
            userId: $requesterId,
            type: NotificationType::LOW_STOCK_ALERT,
            title: 'Stock Request Approved',
            message: "Your stock request {$payload['request_number']} for {$payload['product_name']} was approved.",
            data: $payload,
            actionUrl: "/erp/inventory/stock-request?stock_request={$stockRequest->id}",
            shopId: (int) $stockRequest->shop_owner_id,
            priority: 'medium'
        );
    }

    private function dispatchStockRequestRejectedNotifications(StockRequestApproval $stockRequest, int $rejectorId, string $reason): void
    {
        $requesterId = (int) ($stockRequest->requested_by ?? 0);
        if ($requesterId <= 0) {
            return;
        }

        $payload = $this->buildStockRequestNotificationData($stockRequest, $rejectorId, $reason);

        $this->notificationService->sendToUser(
            userId: $requesterId,
            type: NotificationType::LOW_STOCK_ALERT,
            title: 'Stock Request Rejected',
            message: "Your stock request {$payload['request_number']} was rejected. Reason: {$reason}",
            data: $payload,
            actionUrl: "/erp/inventory/stock-request?stock_request={$stockRequest->id}",
            shopId: (int) $stockRequest->shop_owner_id,
            priority: 'medium'
        );
    }

    private function dispatchStockRequestNeedsDetailsNotifications(StockRequestApproval $stockRequest, int $reviewerId, string $notes): void
    {
        $requesterId = (int) ($stockRequest->requested_by ?? 0);
        if ($requesterId <= 0) {
            return;
        }

        $payload = $this->buildStockRequestNotificationData($stockRequest, $reviewerId, $notes);

        $this->notificationService->sendToUser(
            userId: $requesterId,
            type: NotificationType::LOW_STOCK_ALERT,
            title: 'Stock Request Needs Details',
            message: "More details were requested for {$payload['request_number']}.",
            data: $payload,
            actionUrl: "/erp/inventory/stock-request?stock_request={$stockRequest->id}",
            shopId: (int) $stockRequest->shop_owner_id,
            priority: 'medium'
        );
    }

    private function sendToProcurementOrFinance(
        int $shopOwnerId,
        NotificationType $type,
        string $title,
        string $message,
        array $data,
        string $actionUrl,
        string $priority,
        bool $requiresAction = true
    ): void {
        if (($data['is_auto_generated'] ?? false) && isset($data['request_id'])) {
            $actionUrl = '/erp/procurement/stock-request-approval?stock_request=' . (int) $data['request_id'];
        }

        $recipients = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['procurement manager']))
            ->get();

        if ($recipients->isEmpty()) {
            $recipients = User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['finance']))
                ->get();
        }

        foreach ($recipients as $recipient) {
            $this->notificationService->sendToUser(
                userId: (int) $recipient->id,
                type: $type,
                title: $title,
                message: $message,
                data: $data,
                actionUrl: $actionUrl,
                shopId: $shopOwnerId,
                priority: $priority,
                requiresAction: $requiresAction,
            );
        }
    }

    private function buildStockRequestNotificationData(StockRequestApproval $stockRequest, ?int $actorId, ?string $note): array
    {
        return [
            'request_id' => $stockRequest->id,
            'request_number' => $stockRequest->request_number,
            'product_name' => $stockRequest->product_name,
            'quantity_needed' => $stockRequest->quantity_needed,
            'priority' => $stockRequest->priority,
            'requested_size' => $stockRequest->requested_size,
            'requested_color' => $stockRequest->requested_color,
            'status' => $stockRequest->status,
            'request_source' => $stockRequest->request_source,
            'is_auto_generated' => (bool) $stockRequest->is_auto_generated,
            'source_label' => $stockRequest->source_label,
            'notes' => $note,
            'acted_by' => $actorId,
        ];
    }
}
