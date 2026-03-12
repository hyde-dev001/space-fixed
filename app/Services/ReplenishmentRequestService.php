<?php

namespace App\Services;

use App\Models\ReplenishmentRequest;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReplenishmentRequestService
{
    /**
     * Create a new replenishment request.
     */
    public function createReplenishmentRequest(array $data): ReplenishmentRequest
    {
        DB::beginTransaction();
        
        try {
            // Generate request number if not provided
            if (!isset($data['request_number'])) {
                $data['request_number'] = $this->generateRequestNumber($data['shop_owner_id']);
            }

            $data['status'] = $data['status'] ?? 'pending';

            $replenishmentRequest = ReplenishmentRequest::create($data);

            DB::commit();

            Log::info('Replenishment request created', [
                'request_id' => $replenishmentRequest->id,
                'request_number' => $replenishmentRequest->request_number,
                'inventory_item_id' => $replenishmentRequest->inventory_item_id
            ]);

            return $replenishmentRequest->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create replenishment request', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Generate unique request number for shop owner.
     */
    public function generateRequestNumber(int $shopOwnerId): string
    {
        $year = date('Y');
        
        $lastRequest = ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)
            ->where('request_number', 'LIKE', "RR-{$year}-%")
            ->orderBy('request_number', 'desc')
            ->first();

        if ($lastRequest) {
            $lastNumber = intval(substr($lastRequest->request_number, -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "RR-{$year}-{$newNumber}";
    }

    /**
     * Accept a replenishment request.
     */
    public function acceptRequest(int $requestId, int $userId, ?string $notes = null): ReplenishmentRequest
    {
        DB::beginTransaction();

        try {
            $request = ReplenishmentRequest::findOrFail($requestId);

            if (!$request->canBeAccepted()) {
                throw new \Exception('Replenishment request cannot be accepted in its current state.');
            }

            $request->accept($userId, $notes);

            DB::commit();

            Log::info('Replenishment request accepted', [
                'request_id' => $requestId,
                'request_number' => $request->request_number,
                'accepted_by' => $userId
            ]);

            return $request->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept replenishment request', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reject a replenishment request.
     */
    public function rejectRequest(int $requestId, int $userId, ?string $notes = null): ReplenishmentRequest
    {
        DB::beginTransaction();

        try {
            $request = ReplenishmentRequest::findOrFail($requestId);

            if (!$request->canBeRejected()) {
                throw new \Exception('Replenishment request cannot be rejected in its current state.');
            }

            $request->reject($userId, $notes);

            DB::commit();

            Log::info('Replenishment request rejected', [
                'request_id' => $requestId,
                'request_number' => $request->request_number,
                'rejected_by' => $userId
            ]);

            return $request->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject replenishment request', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Request additional details for a replenishment request.
     */
    public function requestAdditionalDetails(int $requestId, int $userId, string $notes): ReplenishmentRequest
    {
        DB::beginTransaction();

        try {
            $request = ReplenishmentRequest::findOrFail($requestId);

            $request->requestDetails($userId, $notes);

            DB::commit();

            Log::info('Additional details requested for replenishment request', [
                'request_id' => $requestId,
                'request_number' => $request->request_number,
                'requested_by' => $userId
            ]);

            return $request->fresh();

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
     * Auto-create purchase request from accepted replenishment request.
     */
    public function autoCreatePRFromAccepted(int $requestId, int $supplierId, float $unitCost, string $justification): ?PurchaseRequest
    {
        DB::beginTransaction();

        try {
            $replenishmentRequest = ReplenishmentRequest::findOrFail($requestId);

            if ($replenishmentRequest->status !== 'accepted') {
                throw new \Exception('Only accepted replenishment requests can be converted to purchase requests.');
            }

            // Create PR data from replenishment request
            $prData = [
                'shop_owner_id' => $replenishmentRequest->shop_owner_id,
                'supplier_id' => $supplierId,
                'product_name' => $replenishmentRequest->product_name,
                'inventory_item_id' => $replenishmentRequest->inventory_item_id,
                'quantity' => $replenishmentRequest->quantity_needed,
                'unit_cost' => $unitCost,
                'priority' => $replenishmentRequest->priority,
                'justification' => $justification,
                'requested_by' => $replenishmentRequest->reviewed_by ?? $replenishmentRequest->requested_by,
                'requested_date' => now(),
                'notes' => "Auto-created from Replenishment Request: {$replenishmentRequest->request_number}",
            ];

            $purchaseRequestService = new PurchaseRequestService();
            $purchaseRequest = $purchaseRequestService->createPurchaseRequest($prData);

            DB::commit();

            Log::info('Purchase request auto-created from replenishment request', [
                'replenishment_request_id' => $requestId,
                'replenishment_request_number' => $replenishmentRequest->request_number,
                'pr_id' => $purchaseRequest->id,
                'pr_number' => $purchaseRequest->pr_number
            ]);

            return $purchaseRequest;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to auto-create PR from replenishment request', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get metrics for replenishment requests.
     */
    public function getMetrics(int $shopOwnerId): array
    {
        return [
            'total_requests' => ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_requests' => ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)->pending()->count(),
            'accepted_requests' => ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)->accepted()->count(),
            'rejected_requests' => ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)->rejected()->count(),
            'needs_details' => ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)->needsDetails()->count(),
            'high_priority_pending' => ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)
                ->pending()
                ->where('priority', 'high')
                ->count(),
        ];
    }

    /**
     * Get urgent replenishment requests.
     */
    public function getUrgentRequests(int $shopOwnerId): \Illuminate\Database\Eloquent\Collection
    {
        return ReplenishmentRequest::where('shop_owner_id', $shopOwnerId)
            ->where('priority', 'high')
            ->whereIn('status', ['pending', 'needs_details'])
            ->orderBy('requested_date', 'asc')
            ->get();
    }
}
