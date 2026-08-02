<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Enums\NotificationType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseRequestService
{
    private NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null)
    {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }

    /**
     * Create a new purchase request.
     */
    public function createPurchaseRequest(array $data, bool $submitToFinance = false): PurchaseRequest
    {
        DB::beginTransaction();
        
        try {
            $candidatePrNumber = isset($data['pr_number'])
                ? (string) $data['pr_number']
                : $this->generatePRNumber();

            // Calculate total cost
            $data['total_cost'] = $data['total_cost'] ?? $data['quantity'] * $data['unit_cost'];

            // Align with DB not-null constraint for service-driven PR creation.
            $data['requested_date'] = $data['requested_date'] ?? now();

            // Set default status
            $data['status'] = 'draft';
            unset($data['submit_to_finance']);

            $purchaseRequest = null;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $data['pr_number'] = $candidatePrNumber;

                try {
                    $purchaseRequest = PurchaseRequest::create($data);
                    break;
                } catch (QueryException $queryException) {
                    if (!$this->isPrNumberDuplicateException($queryException) || $attempt === 9) {
                        throw $queryException;
                    }

                    Log::warning('PurchaseRequestService PR number collision; retrying with next sequence.', [
                        'candidate_pr_number' => $candidatePrNumber,
                        'attempt' => $attempt + 1,
                    ]);

                    $candidatePrNumber = $this->incrementPrNumber($candidatePrNumber);
                }
            }

            if (!$purchaseRequest) {
                throw new \RuntimeException('Unable to generate a unique purchase request number.');
            }

            if ($submitToFinance && !$purchaseRequest->submitToFinance()) {
                throw ValidationException::withMessages([
                    'submit_to_finance' => 'Purchase request could not be submitted to Finance.',
                ]);
            }

            DB::commit();
            
            $freshPurchaseRequest = $purchaseRequest->fresh();
            if ($submitToFinance) {
                $this->notifyPurchaseRequestSubmitted($freshPurchaseRequest);
            }

            return $freshPurchaseRequest;

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
    public function generatePRNumber(): string
    {
        $year = (int) date('Y');
        $maxSequence = 0;

        $existingPrNumbers = PurchaseRequest::query()
            ->where('pr_number', 'LIKE', "PR-{$year}-%")
            ->pluck('pr_number');

        foreach ($existingPrNumbers as $prNumber) {
            if (preg_match('/^PR-(\d{4})-(\d+)$/', (string) $prNumber, $matches) !== 1) {
                continue;
            }

            if ((int) $matches[1] !== $year) {
                continue;
            }

            $maxSequence = max($maxSequence, (int) $matches[2]);
        }

        return sprintf('PR-%d-%03d', $year, $maxSequence + 1);
    }

    private function incrementPrNumber(string $prNumber): string
    {
        if (preg_match('/^PR-(\d{4})-(\d+)$/', $prNumber, $matches) !== 1) {
            return $this->generatePRNumber();
        }

        $year = (int) $matches[1];
        $sequence = (int) $matches[2] + 1;

        return sprintf('PR-%d-%03d', $year, $sequence);
    }

    private function isPrNumberDuplicateException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate entry')
            && str_contains($message, 'pr_number');
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

    public function reviewByFinance(int $prId, User $actor, ?string $notes = null): PurchaseRequest
    {
        $purchaseRequest = DB::transaction(function () use ($prId, $actor, $notes) {
            $purchaseRequest = PurchaseRequest::lockForUpdate()->findOrFail($prId);
            if (!$purchaseRequest->reviewByFinance($actor, $notes)) {
                throw ValidationException::withMessages(['status' => 'Only a pending Finance request may be reviewed.']);
            }

            return $purchaseRequest->fresh();
        });

        $this->dispatchPurchaseRequestApprovalNotifications($purchaseRequest, 'pending_finance', $notes);

        return $purchaseRequest;
    }

    public function approveByShopOwner(int $prId, ShopOwner $actor, ?string $notes = null): PurchaseRequest
    {
        $purchaseRequest = DB::transaction(function () use ($prId, $actor, $notes) {
            $purchaseRequest = PurchaseRequest::lockForUpdate()->findOrFail($prId);
            if (!$purchaseRequest->approveByShopOwner($actor, $notes)) {
                throw ValidationException::withMessages(['status' => 'Only a request pending Shop Owner approval may be approved.']);
            }

            return $purchaseRequest->fresh();
        });

        $this->dispatchPurchaseRequestApprovalNotifications($purchaseRequest, 'pending_shop_owner', $notes);

        return $purchaseRequest;
    }

    public function releaseByFinance(int $prId, User $actor, ?string $notes = null): PurchaseRequest
    {
        $purchaseRequest = DB::transaction(function () use ($prId, $actor, $notes) {
            $purchaseRequest = PurchaseRequest::lockForUpdate()->findOrFail($prId);
            if (!$purchaseRequest->releaseByFinance($actor, $notes)) {
                throw ValidationException::withMessages(['status' => 'Only an owner-approved request may receive final Finance release.']);
            }

            return $purchaseRequest->fresh();
        });

        $this->dispatchPurchaseRequestApprovalNotifications($purchaseRequest, 'pending_finance_final', $notes);

        return $purchaseRequest;
    }

    public function rejectByFinance(int $prId, User $actor, string $reason): PurchaseRequest
    {
        $previousStatus = '';
        $purchaseRequest = DB::transaction(function () use ($prId, $actor, $reason, &$previousStatus) {
            $purchaseRequest = PurchaseRequest::lockForUpdate()->findOrFail($prId);
            $previousStatus = (string) $purchaseRequest->status;
            if (!$purchaseRequest->rejectByFinance($actor, $reason)) {
                throw ValidationException::withMessages(['status' => 'This request is not awaiting Finance review.']);
            }

            return $purchaseRequest->fresh();
        });

        $this->dispatchPurchaseRequestRejectionNotifications($purchaseRequest, $previousStatus, $reason);

        return $purchaseRequest;
    }

    public function rejectByShopOwner(int $prId, ShopOwner $actor, string $reason): PurchaseRequest
    {
        $purchaseRequest = DB::transaction(function () use ($prId, $actor, $reason) {
            $purchaseRequest = PurchaseRequest::lockForUpdate()->findOrFail($prId);
            if (!$purchaseRequest->rejectByShopOwner($actor, $reason)) {
                throw ValidationException::withMessages(['status' => 'This request is not awaiting Shop Owner approval.']);
            }

            return $purchaseRequest->fresh();
        });

        $this->dispatchPurchaseRequestRejectionNotifications($purchaseRequest, 'pending_shop_owner', $reason);

        return $purchaseRequest;
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
            ->whereDoesntHave('purchaseOrderItems.purchaseOrder', function ($query) {
                $query->where('status', '!=', 'cancelled');
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

    private function notifyPurchaseRequestSubmitted(PurchaseRequest $purchaseRequest): void
    {
        $payload = $this->buildPurchaseRequestNotificationData($purchaseRequest);

        $this->notificationService->notifyPurchaseRequestSubmitted((int) $purchaseRequest->shop_owner_id, [
            'purchase_request_id' => $purchaseRequest->id,
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
                actionUrl: "/shop-owner/purchase-request-approval?purchase_request={$purchaseRequest->id}",
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
                actionUrl: "/finance/purchase-request-approval?purchase_request={$purchaseRequest->id}",
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
                    actionUrl: "/erp/procurement/purchase-request?purchase_request={$purchaseRequest->id}",
                    shopId: $shopOwnerId
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
                actionUrl: "/erp/procurement/purchase-request?purchase_request={$purchaseRequest->id}",
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
                actionUrl: "/shop-owner/purchase-request-approval?purchase_request={$purchaseRequest->id}",
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
