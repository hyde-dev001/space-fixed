<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\StockRequestApproval;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockRequestApprovalService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

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
            actionUrl: '/erp/procurement/stock-request-approval',
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
            actionUrl: '/erp/procurement/stock-request-approval',
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
            actionUrl: '/erp/procurement/stock-request-approval',
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
            actionUrl: '/erp/procurement/stock-request-approval',
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
            actionUrl: '/erp/procurement/stock-request-approval',
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
        string $priority
    ): void {
        $recipients = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Procurement Manager'))
            ->get();

        if ($recipients->isEmpty()) {
            $recipients = User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereHas('roles', fn ($q) => $q->where('name', 'Finance'))
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
                priority: $priority
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
            'notes' => $note,
            'acted_by' => $actorId,
        ];
    }
}
