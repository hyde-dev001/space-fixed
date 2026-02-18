<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RepairService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class RepairServiceController extends Controller
{
    /**
     * Display a listing of the repair services.
     */
    public function index(Request $request)
    {
        $query = RepairService::query();

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by category if provided
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Search by name or category
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $services = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Store a newly created repair service in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive,Pending',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service = RepairService::create([
            'name' => $request->name,
            'category' => $request->category,
            'price' => $request->price,
            'duration' => $request->duration,
            'description' => $request->description,
            'status' => $request->status ?? 'Active',
            'created_by' => Auth::guard('user')->id() ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service created successfully',
            'data' => $service,
        ], 201);
    }

    /**
     * Display the specified repair service.
     */
    public function show($id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $service,
        ]);
    }

    /**
     * Update the specified repair service in storage.
     */
    public function update(Request $request, $id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'duration' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:Active,Inactive,Pending,Under Review,Rejected',
            'rejection_reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only(['name', 'category', 'price', 'duration', 'description', 'status', 'rejection_reason']);
        $updateData['updated_by'] = Auth::guard('user')->id() ?? null;

        $service->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Service updated successfully',
            'data' => $service,
        ]);
    }

    /**
     * Remove the specified repair service from storage.
     */
    public function destroy($id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted successfully',
        ]);
    }

    /**
     * Get services pending finance approval
     */
    public function financePending(Request $request)
    {
        $query = RepairService::with(['creator', 'updater', 'financeReviewer', 'ownerReviewer']);

        // Always return all statuses for metrics calculation on frontend
        // The frontend will handle filtering for display
        $query->whereIn('status', ['Under Review', 'Pending Owner Approval', 'Active', 'Rejected']);

        $services = $query->orderBy('updated_at', 'desc')->get();

        // Transform the data to match frontend expectations
        $transformedData = $services->map(function($service) {
            // Determine the status based on backend status
            $frontendStatus = 'pending';
            if ($service->status === 'Under Review') {
                $frontendStatus = 'pending';
            } elseif ($service->status === 'Pending Owner Approval') {
                $frontendStatus = 'finance_approved';
            } elseif ($service->status === 'Active' && $service->owner_reviewed_at) {
                // Only Active services that went through approval workflow
                $frontendStatus = 'owner_approved';
            } elseif ($service->status === 'Active' && !$service->finance_reviewed_at) {
                // Active services that were never reviewed (newly created) - skip these
                return null;
            } elseif ($service->status === 'Rejected' && $service->finance_reviewed_at && !$service->owner_reviewed_at) {
                $frontendStatus = 'finance_rejected';
            } elseif ($service->status === 'Rejected' && $service->owner_reviewed_at) {
                $frontendStatus = 'owner_rejected';
            }

            return [
                'id' => $service->id,
                'service_name' => $service->name,
                'category' => $service->category,
                'current_price' => (float)$service->price,
                'proposed_price' => (float)$service->price,
                'duration' => $service->duration,
                'reason' => $service->description ?? 'Price update request',
                'status' => $frontendStatus,
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'requester' => $service->updater ? [
                    'id' => $service->updater->id,
                    'name' => $service->updater->name,
                ] : ($service->creator ? [
                    'id' => $service->creator->id,
                    'name' => $service->creator->name,
                ] : null),
                'finance_reviewer' => $service->financeReviewer ? [
                    'id' => $service->financeReviewer->id,
                    'name' => $service->financeReviewer->name,
                ] : null,
                'finance_notes' => $service->finance_notes,
                'finance_reviewed_at' => $service->finance_reviewed_at,
                'finance_rejection_reason' => $frontendStatus === 'finance_rejected' ? $service->rejection_reason : null,
                'owner_reviewer' => $service->ownerReviewer ? [
                    'id' => $service->ownerReviewer->id,
                    'name' => $service->ownerReviewer->name,
                ] : null,
                'owner_reviewed_at' => $service->owner_reviewed_at,
                'owner_rejection_reason' => $frontendStatus === 'owner_rejected' ? $service->rejection_reason : null,
            ];
        })->filter(); // Remove null values (newly created Active services)

        return response()->json([
            'success' => true,
            'data' => $transformedData->values(), // Re-index array after filtering
        ]);
    }

    /**
     * Finance approves a service price change
     */
    public function financeApprove(Request $request, $id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service->update([
            'status' => 'Pending Owner Approval',
            'finance_notes' => $request->notes,
            'finance_reviewed_by' => Auth::guard('user')->id(),
            'finance_reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service approved by Finance. Pending Shop Owner approval.',
            'data' => $service,
        ]);
    }

    /**
     * Finance rejects a service price change
     */
    public function financeReject(Request $request, $id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service->update([
            'status' => 'Rejected',
            'rejection_reason' => $request->reason,
            'finance_reviewed_by' => Auth::guard('user')->id(),
            'finance_reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service price change rejected by Finance.',
            'data' => $service,
        ]);
    }

    /**
     * Get services pending owner approval
     */
    public function ownerPending()
    {
        $services = RepairService::where('status', 'Pending Owner Approval')
            ->with(['creator', 'updater'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Get all services for owner review (pending + approved + rejected)
     */
    public function ownerAll()
    {
        $services = RepairService::whereIn('status', ['Pending Owner Approval', 'Active', 'Rejected'])
            ->with(['creator', 'updater'])
            ->orderBy('updated_at', 'desc')
            ->get();

        // Transform status to match frontend expectations
        $services->each(function ($service) {
            $statusMap = [
                'Under Review' => 'pending',
                'Pending Owner Approval' => 'finance_approved',
                'Active' => 'owner_approved',
                'Rejected' => $service->finance_reviewed_at ? 
                    ($service->owner_reviewed_at ? 'owner_rejected' : 'finance_rejected') : 
                    'owner_rejected',
            ];
            $service->mapped_status = $statusMap[$service->status] ?? $service->status;
        });

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Shop Owner gives final approval
     */
    public function ownerApprove($id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $service->update([
            'status' => 'Active',
            'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
            'owner_reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service price change approved and applied.',
            'data' => $service,
        ]);
    }

    /**
     * Shop Owner rejects the service
     */
    public function ownerReject(Request $request, $id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service->update([
            'status' => 'Rejected',
            'rejection_reason' => $request->reason,
            'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
            'owner_reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service price change rejected.',
            'data' => $service,
        ]);
    }
}
