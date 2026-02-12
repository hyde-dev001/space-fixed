<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\SuspensionRequest;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspensionFinalApprovalController extends Controller
{
    /**
     * Display a listing of suspension requests for shop owner review.
     */
    public function index(Request $request)
    {
        try {
            Log::info('Shop Owner accessing suspension requests', [
                'user_id' => auth('shop_owner')->id(),
                'status_filter' => $request->input('status'),
                'search' => $request->input('search'),
            ]);

            $query = SuspensionRequest::with(['employee', 'requester', 'manager'])
                ->where('manager_status', 'approved'); // Only show manager-approved requests

            // Filter by owner status if provided
            if ($request->has('status') && $request->status !== 'all') {
                $statusMapping = [
                    'pending' => 'pending_owner',
                    'approved' => 'approved',
                    'rejected' => 'rejected_owner'
                ];
                
                $dbStatus = $statusMapping[$request->status] ?? $request->status;
                $query->where('status', $dbStatus);
            }

            // Search by name or email
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $suspensionRequests = $query->orderBy('created_at', 'desc')->get();

            Log::info('Fetched suspension requests', ['count' => $suspensionRequests->count()]);

            // Transform the data
            $transformedData = $suspensionRequests->map(function ($request) {
                // Map database status to frontend status
                $frontendStatus = match($request->status) {
                    'pending_owner' => 'pending',
                    'approved' => 'approved',
                    'rejected_owner' => 'rejected',
                    'rejected_manager' => 'rejected',
                    default => 'pending'
                };

                return [
                    'id' => $request->id,
                    'employee_id' => $request->employee_id,
                    'name' => $request->employee->name ?? 'N/A',
                    'email' => $request->employee->email ?? 'N/A',
                    'position' => $request->employee->position ?? 'N/A',
                    'reason' => $request->reason,
                    'evidence' => $request->evidence,
                    'status' => $frontendStatus,
                    'requested_at' => $request->created_at->format('M d, Y'),
                    'requested_by' => $request->requester->name ?? 'N/A',
                    'manager_status' => $request->manager_status,
                    'manager_note' => $request->manager_note,
                    'manager_name' => $request->manager->name ?? 'N/A',
                    'owner_status' => $request->owner_status,
                    'owner_note' => $request->owner_note,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedData,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching suspension requests: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch suspension requests',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified suspension request.
     */
    public function show($id)
    {
        try {
            $request = SuspensionRequest::with(['employee', 'requester', 'manager', 'owner'])
                ->findOrFail($id);

            // Map database status to frontend status
            $frontendStatus = match($request->status) {
                'pending_owner' => 'pending',
                'approved' => 'approved',
                'rejected_owner' => 'rejected',
                'rejected_manager' => 'rejected',
                default => 'pending'
            };

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $request->id,
                    'employee_id' => $request->employee_id,
                    'name' => $request->employee->name ?? 'N/A',
                    'email' => $request->employee->email ?? 'N/A',
                    'position' => $request->employee->position ?? 'N/A',
                    'reason' => $request->reason,
                    'evidence' => $request->evidence,
                    'status' => $frontendStatus,
                    'requested_at' => $request->created_at->format('M d, Y H:i:s'),
                    'requested_by' => $request->requester->name ?? 'N/A',
                    'manager_status' => $request->manager_status,
                    'manager_note' => $request->manager_note,
                    'manager_name' => $request->manager->name ?? 'N/A',
                    'owner_status' => $request->owner_status,
                    'owner_note' => $request->owner_note,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching suspension request: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch suspension request',
            ], 500);
        }
    }

    /**
     * Review (approve or reject) a suspension request.
     */
    public function review(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $suspensionRequest = SuspensionRequest::with('employee')->findOrFail($id);

            // Check if already reviewed by owner
            if (in_array($suspensionRequest->status, ['approved', 'rejected_owner'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has already been reviewed',
                ], 400);
            }

            // Check if manager approved
            if ($suspensionRequest->manager_status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has not been approved by the manager',
                ], 400);
            }

            $action = $request->input('action');
            $note = $request->input('note');

            // Update suspension request with proper status
            $newStatus = $action === 'approve' ? 'approved' : 'rejected_owner';
            
            $suspensionRequest->update([
                'status' => $newStatus,
                'owner_id' => auth('shop_owner')->id(),
                'owner_status' => $action === 'approve' ? 'approved' : 'rejected',
                'owner_note' => $note,
                'owner_reviewed_at' => now(),
            ]);

            // If approved, suspend the employee account
            if ($action === 'approve') {
                $employee = $suspensionRequest->employee;
                if ($employee) {
                    $employee->update([
                        'status' => 'suspended',
                        'suspension_reason' => $suspensionRequest->reason,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $action === 'approve' 
                    ? 'Suspension request approved successfully' 
                    : 'Suspension request rejected successfully',
                'data' => $suspensionRequest,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error reviewing suspension request: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to review suspension request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
