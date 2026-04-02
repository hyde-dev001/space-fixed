<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\ProcurementSettings;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseRequestService
{
    private ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService;
    private NotificationService $notificationService;

    public function __construct(
        ?ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService = null,
        ?NotificationService $notificationService = null
    ) {
        $this->shopOwnerApprovalPolicyService = $shopOwnerApprovalPolicyService ?? app(ShopOwnerApprovalPolicyService::class);
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }

    /**
     * Create a new purchase request.
     */
    public function createPurchaseRequest(array $data): PurchaseRequest
    {
        DB::beginTransaction();
        
        try {
            // Generate PR number if not provided
            if (!isset($data['pr_number'])) {
                $data['pr_number'] = $this->generatePRNumber($data['shop_owner_id']);
            }

            // Calculate total cost
            $data['total_cost'] = $data['quantity'] * $data['unit_cost'];

            // Set default status
            $data['status'] = $data['status'] ?? 'draft';

            $purchaseRequest = PurchaseRequest::create($data);

            // Check if auto-approval is enabled for low-value PRs
            $settings = ProcurementSettings::getForShopOwner($data['shop_owner_id']);
            if ($settings && $this->shouldAutoApprove($purchaseRequest, $settings)) {
                $this->autoApprovePurchaseRequest($purchaseRequest);
            }

            DB::commit();
            
            return $purchaseRequest->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create purchase request', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Generate unique PR number for shop owner.
     */
    public function generatePRNumber(int $shopOwnerId): string
    {
        $year = date('Y');
        
        $lastPR = PurchaseRequest::where('shop_owner_id', $shopOwnerId)
            ->where('pr_number', 'LIKE', "PR-{$year}-%")
            ->orderBy('pr_number', 'desc')
            ->first();

        if ($lastPR) {
            $lastNumber = intval(substr($lastPR->pr_number, -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "PR-{$year}-{$newNumber}";
    }

    /**
     * Submit purchase request to finance for approval.
     */
    public function submitToFinance(int $prId): PurchaseRequest
    {
        $purchaseRequest = PurchaseRequest::findOrFail($prId);

        if ($purchaseRequest->status !== 'draft') {
            throw new \Exception('Only draft purchase requests can be submitted to finance.');
        }

        $purchaseRequest->submitToFinance();

        Log::info('Purchase request submitted to finance', [
            'pr_id' => $prId,
            'pr_number' => $purchaseRequest->pr_number
        ]);

        $this->notifyPurchaseRequestSubmitted($purchaseRequest->fresh());

        return $purchaseRequest->fresh();
    }

    /**
     * Approve a purchase request.
     */
    public function approvePurchaseRequest(int $prId, int $userId, ?string $notes = null): PurchaseRequest
    {
        DB::beginTransaction();

        try {
            $purchaseRequest = PurchaseRequest::findOrFail($prId);
            $previousStatus = (string) $purchaseRequest->status;

            if (!$purchaseRequest->canBeApproved()) {
                throw new \Exception('Purchase request cannot be approved in its current state.');
            }

            $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPurchaseRequest(
                (int) $purchaseRequest->shop_owner_id,
                (float) $purchaseRequest->total_cost
            );

            $purchaseRequest->approve($userId, $notes, null, $requiresOwnerApproval);

            // Check if auto PO generation is enabled
            $settings = ProcurementSettings::getForShopOwner($purchaseRequest->shop_owner_id);
            if ($settings && $settings->canAutoGeneratePO()) {
                // This will be handled by an event listener in Phase 4
                Log::info('Purchase request approved - auto PO generation will be triggered', [
                    'pr_id' => $prId
                ]);
            }

            DB::commit();

            Log::info('Purchase request approved', [
                'pr_id' => $prId,
                'pr_number' => $purchaseRequest->pr_number,
                'approved_by' => $userId
            ]);

            $freshPurchaseRequest = $purchaseRequest->fresh();
            $this->dispatchPurchaseRequestApprovalNotifications($freshPurchaseRequest, $previousStatus, $notes);

            return $freshPurchaseRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve purchase request', [
                'pr_id' => $prId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reject a purchase request.
     */
    public function rejectPurchaseRequest(int $prId, int $userId, string $reason): PurchaseRequest
    {
        DB::beginTransaction();

        try {
            $purchaseRequest = PurchaseRequest::findOrFail($prId);
            $previousStatus = (string) $purchaseRequest->status;

            if (!$purchaseRequest->canBeRejected()) {
                throw new \Exception('Purchase request cannot be rejected in its current state.');
            }

            $purchaseRequest->reject($userId, $reason);

            DB::commit();

            Log::info('Purchase request rejected', [
                'pr_id' => $prId,
                'pr_number' => $purchaseRequest->pr_number,
                'rejected_by' => $userId,
                'reason' => $reason
            ]);

            $freshPurchaseRequest = $purchaseRequest->fresh();
            $this->dispatchPurchaseRequestRejectionNotifications($freshPurchaseRequest, $previousStatus, $reason);

            return $freshPurchaseRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject purchase request', [
                'pr_id' => $prId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get procurement metrics for a shop owner.
     */
    public function getMetrics(int $shopOwnerId): array
    {
        return [
            'total_purchase_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_finance' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->pendingFinance()->count(),
            'approved_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->approved()->count(),
            'rejected_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->rejected()->count(),
            'draft_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->draft()->count(),
            'total_value' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->sum('total_cost'),
            'approved_value' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->approved()->sum('total_cost'),
            'pending_value' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->pendingFinance()->sum('total_cost'),
            'high_priority_pending' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)
                ->pendingFinance()
                ->where('priority', 'high')
                ->count(),
        ];
    }

    /**
     * Get approved purchase requests available for PO creation.
     */
    public function getApprovedPRs(int $shopOwnerId): \Illuminate\Database\Eloquent\Collection
    {
        return PurchaseRequest::with(['supplier', 'inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)
            ->approved()
            ->whereDoesntHave('purchaseOrders', function ($query) {
                $query->whereNotIn('status', ['cancelled']);
            })
            ->orderBy('approved_date', 'desc')
            ->get();
    }

    /**
     * Check if purchase request can be approved.
     */
    public function canBeApproved(int $prId): bool
    {
        $purchaseRequest = PurchaseRequest::findOrFail($prId);
        return $purchaseRequest->canBeApproved();
    }

    /**
     * Determine if PR should be auto-approved based on settings.
     */
    protected function shouldAutoApprove(PurchaseRequest $purchaseRequest, ProcurementSettings $settings): bool
    {
        if (!$settings->require_finance_approval) {
            return false;
        }

        if ($settings->auto_pr_approval_threshold === null) {
            return false;
        }

        return $purchaseRequest->total_cost <= $settings->auto_pr_approval_threshold;
    }

    /**
     * Auto-approve a purchase request.
     */
    protected function autoApprovePurchaseRequest(PurchaseRequest $purchaseRequest): void
    {
        $purchaseRequest->status = 'approved';
        $purchaseRequest->approved_by = $purchaseRequest->requested_by;
        $purchaseRequest->approved_date = now();
        $purchaseRequest->notes = 'Auto-approved based on procurement settings threshold.';
        $purchaseRequest->save();

        Log::info('Purchase request auto-approved', [
            'pr_id' => $purchaseRequest->id,
            'pr_number' => $purchaseRequest->pr_number,
            'total_cost' => $purchaseRequest->total_cost
        ]);

        $this->dispatchPurchaseRequestApprovalNotifications($purchaseRequest->fresh(), 'pending_finance', 'Auto-approved by threshold rule');
    }

    private function notifyPurchaseRequestSubmitted(PurchaseRequest $purchaseRequest): void
    {
        $payload = $this->buildPurchaseRequestNotificationData($purchaseRequest);

        $this->notificationService->notifyPurchaseRequestSubmitted((int) $purchaseRequest->shop_owner_id, [
            'reference' => $payload['reference'],
            'total_cost' => $payload['total_cost'],
            'product_name' => $payload['product_name'],
            'requested_by' => $payload['requested_by_name'],
        ]);
    }

    private function dispatchPurchaseRequestApprovalNotifications(PurchaseRequest $purchaseRequest, string $previousStatus, ?string $notes): void
    {
        $payload = $this->buildPurchaseRequestNotificationData($purchaseRequest, null, $notes);
        $shopOwnerId = (int) $purchaseRequest->shop_owner_id;

        if ($purchaseRequest->status === 'pending_shop_owner' && $previousStatus === 'pending_finance') {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $shopOwnerId,
                type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
                title: 'Purchase Request Awaiting Approval',
                message: "{$payload['reference']} ({$payload['product_name']}) now requires shop owner approval.",
                data: $payload,
                actionUrl: '/shop-owner/purchase-requests',
                priority: 'medium'
            );

            return;
        }

        if ($purchaseRequest->status === 'pending_finance_final' && $previousStatus === 'pending_shop_owner') {
            $this->notificationService->sendToErpRole(
                roleName: 'Finance',
                shopId: $shopOwnerId,
                type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
                title: 'Purchase Request Returned To Finance',
                message: "{$payload['reference']} was approved by shop owner and requires final Finance review.",
                data: $payload,
                actionUrl: '/erp/procurement/purchase-requests',
                priority: 'medium'
            );

            return;
        }

        if ($purchaseRequest->status === 'approved') {
            $requesterId = (int) ($purchaseRequest->requested_by ?? 0);
            if ($requesterId > 0) {
                $this->notificationService->sendToUser(
                    userId: $requesterId,
                    type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
                    title: 'Purchase Request Approved',
                    message: "{$payload['reference']} has been approved.",
                    data: $payload,
                    actionUrl: '/erp/procurement/purchase-requests',
                    shopId: $shopOwnerId
                );
            }

            if ($previousStatus === 'pending_finance') {
                $this->notificationService->sendToShopOwner(
                    shopOwnerId: $shopOwnerId,
                    type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
                    title: 'Purchase Request Finalized by Finance',
                    message: "{$payload['reference']} was approved directly by Finance (owner approval not required).",
                    data: $payload,
                    actionUrl: '/shop-owner/purchase-requests',
                    priority: 'medium'
                );
            }
        }
    }

    private function dispatchPurchaseRequestRejectionNotifications(PurchaseRequest $purchaseRequest, string $previousStatus, string $reason): void
    {
        $payload = $this->buildPurchaseRequestNotificationData($purchaseRequest, null, $reason);
        $shopOwnerId = (int) $purchaseRequest->shop_owner_id;

        $requesterId = (int) ($purchaseRequest->requested_by ?? 0);
        if ($requesterId > 0) {
            $this->notificationService->sendToUser(
                userId: $requesterId,
                type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
                title: 'Purchase Request Rejected',
                message: "{$payload['reference']} was rejected. Reason: {$reason}",
                data: $payload,
                actionUrl: '/erp/procurement/purchase-requests',
                shopId: $shopOwnerId
            );
        }

        if (in_array($previousStatus, ['pending_finance', 'pending_finance_final'], true)) {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $shopOwnerId,
                type: NotificationType::PURCHASE_REQUEST_SUBMITTED,
                title: 'Purchase Request Rejected by Finance',
                message: "{$payload['reference']} was rejected by Finance. Reason: {$reason}",
                data: $payload,
                actionUrl: '/shop-owner/purchase-requests',
                priority: 'medium'
            );
        }
    }

    private function buildPurchaseRequestNotificationData(PurchaseRequest $purchaseRequest, ?int $actorId = null, ?string $reason = null): array
    {
        return [
            'purchase_request_id' => $purchaseRequest->id,
            'reference' => $purchaseRequest->pr_number,
            'product_name' => $purchaseRequest->product_name,
            'quantity' => (int) $purchaseRequest->quantity,
            'total_cost' => number_format((float) $purchaseRequest->total_cost, 2),
            'status' => (string) $purchaseRequest->status,
            'requested_by' => $purchaseRequest->requested_by,
            'requested_by_name' => $purchaseRequest->requester?->name ?? 'Procurement',
            'action_by' => $actorId,
            'rejection_reason' => $reason,
        ];
    }

    /**
     * Get purchase requests requiring urgent attention.
     */
    public function getUrgentRequests(int $shopOwnerId): \Illuminate\Database\Eloquent\Collection
    {
        return PurchaseRequest::where('shop_owner_id', $shopOwnerId)
            ->where('priority', 'high')
            ->whereIn('status', ['draft', 'pending_finance'])
            ->orderBy('requested_date', 'asc')
            ->get();
    }

    /**
     * Get aging report for pending requests.
     */
    public function getAgingReport(int $shopOwnerId): array
    {
        $pendingRequests = PurchaseRequest::where('shop_owner_id', $shopOwnerId)
            ->pendingFinance()
            ->get();

        $aging = [
            '0-7_days' => 0,
            '8-14_days' => 0,
            '15-30_days' => 0,
            'over_30_days' => 0,
        ];

        foreach ($pendingRequests as $pr) {
            $days = $pr->days_pending;
            
            if ($days <= 7) {
                $aging['0-7_days']++;
            } elseif ($days <= 14) {
                $aging['8-14_days']++;
            } elseif ($days <= 30) {
                $aging['15-30_days']++;
            } else {
                $aging['over_30_days']++;
            }
        }

        return $aging;
    }
}
