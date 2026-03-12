<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\ReplenishmentRequest;
use App\Http\Requests\StoreReplenishmentRequestRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReplenishmentRequestController extends Controller
{
    /**
     * Display a listing of replenishment requests.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', ReplenishmentRequest::class);

        $query = ReplenishmentRequest::query()
            ->with(['shopOwner', 'inventoryItem', 'requester', 'reviewer'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'LIKE', "%{$search}%")
                    ->orWhere('product_name', 'LIKE', "%{$search}%")
                    ->orWhere('sku_code', 'LIKE', "%{$search}%")
                    ->orWhereHas('requester', function ($rq) use ($search) {
                        $rq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Priority filter
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'requested_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $replenishmentRequests = $query->paginate($request->get('per_page', 15));

        return response()->json($replenishmentRequests);
    }

    /**
     * Store a newly created replenishment request.
     */
    public function store(StoreReplenishmentRequestRequest $request)
    {
        $this->authorize('create', ReplenishmentRequest::class);

        try {
            DB::beginTransaction();

            $data = $request->validated();
            $data['shop_owner_id'] = Auth::user()->shop_owner_id;
            $data['requested_by'] = Auth::id();
            $data['requested_date'] = now();
            $data['status'] = 'pending';
            
            // Generate request number
            $data['request_number'] = $this->generateRequestNumber();

            $replenishmentRequest = ReplenishmentRequest::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Replenishment request created successfully.',
                'replenishment_request' => $replenishmentRequest->load(['shopOwner', 'inventoryItem', 'requester'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create replenishment request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified replenishment request.
     */
    public function show($id)
    {
        $replenishmentRequest = ReplenishmentRequest::with([
            'shopOwner', 
            'inventoryItem', 
            'requester', 
            'reviewer'
        ])->findOrFail($id);

        $this->authorize('view', $replenishmentRequest);

        return response()->json($replenishmentRequest);
    }

    /**
     * Update the specified replenishment request.
     */
    public function update(StoreReplenishmentRequestRequest $request, $id)
    {
        $replenishmentRequest = ReplenishmentRequest::findOrFail($id);
        
        $this->authorize('update', $replenishmentRequest);

        // Can only update if pending or needs_details
        if (!in_array($replenishmentRequest->status, ['pending', 'needs_details'])) {
            return response()->json([
                'message' => 'Only pending or needs-details replenishment requests can be updated.'
            ], 403);
        }

        try {
            $data = $request->validated();
            $replenishmentRequest->update($data);

            return response()->json([
                'message' => 'Replenishment request updated successfully.',
                'replenishment_request' => $replenishmentRequest->fresh(['shopOwner', 'inventoryItem', 'requester'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update replenishment request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified replenishment request.
     */
    public function destroy($id)
    {
        $replenishmentRequest = ReplenishmentRequest::findOrFail($id);
        
        $this->authorize('delete', $replenishmentRequest);

        // Can only delete if pending
        if ($replenishmentRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending replenishment requests can be deleted.'
            ], 403);
        }

        $replenishmentRequest->delete();

        return response()->json([
            'message' => 'Replenishment request deleted successfully.'
        ]);
    }

    /**
     * Accept replenishment request.
     */
    public function accept(Request $request, $id)
    {
        $replenishmentRequest = ReplenishmentRequest::findOrFail($id);
        
        $this->authorize('accept', $replenishmentRequest);

        if (!$replenishmentRequest->canBeAccepted()) {
            return response()->json([
                'message' => 'Replenishment request cannot be accepted in its current state.'
            ], 403);
        }

        $validatedData = $request->validate([
            'response_notes' => 'nullable|string',
        ]);

        try {
            $replenishmentRequest->accept(Auth::id(), $validatedData['response_notes'] ?? null);

            return response()->json([
                'message' => 'Replenishment request accepted successfully.',
                'replenishment_request' => $replenishmentRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'reviewer'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to accept replenishment request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject replenishment request.
     */
    public function reject(Request $request, $id)
    {
        $replenishmentRequest = ReplenishmentRequest::findOrFail($id);
        
        $this->authorize('reject', $replenishmentRequest);

        if (!$replenishmentRequest->canBeRejected()) {
            return response()->json([
                'message' => 'Replenishment request cannot be rejected in its current state.'
            ], 403);
        }

        $validatedData = $request->validate([
            'response_notes' => 'required|string|min:10',
        ]);

        try {
            $replenishmentRequest->reject(Auth::id(), $validatedData['response_notes']);

            return response()->json([
                'message' => 'Replenishment request rejected successfully.',
                'replenishment_request' => $replenishmentRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'reviewer'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reject replenishment request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request additional details for replenishment request.
     */
    public function requestDetails(Request $request, $id)
    {
        $replenishmentRequest = ReplenishmentRequest::findOrFail($id);
        
        $this->authorize('accept', $replenishmentRequest);

        $validatedData = $request->validate([
            'response_notes' => 'required|string|min:10',
        ]);

        try {
            $replenishmentRequest->requestDetails(Auth::id(), $validatedData['response_notes']);

            return response()->json([
                'message' => 'Additional details requested successfully.',
                'replenishment_request' => $replenishmentRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'reviewer'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to request additional details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique request number.
     */
    private function generateRequestNumber()
    {
        $year = date('Y');
        $shopOwnerId = Auth::user()->shop_owner_id;
        
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
}
