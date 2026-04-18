<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\SuspensionRequest;
use App\Models\Employee;
use App\Enums\EmployeeStatus;
use App\Enums\NotificationType;
use App\Enums\SuspensionStatus;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspensionFinalApprovalController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    private function shopOwnerId(): int
    {
        return (int) (auth('shop_owner')->id() ?? 0);
    }

    private function shopScopedRequestQuery()
    {
        $shopOwnerId = $this->shopOwnerId();

        return SuspensionRequest::with(['employee', 'requester', 'manager'])
            ->whereHas('employee', function ($query) use ($shopOwnerId) {
                $query->forShopOwner($shopOwnerId);
            });
    }

    /**
     * Display a listing of suspension requests for shop owner review.
     */
    public function index(Request $request)
    {
        try {
            Log::info('Shop Owner accessing suspension requests', [
                'user_id' => $this->shopOwnerId(),
                'status_filter' => $request->input('status'),
                'search' => $request->input('search'),
            ]);

            $query = $this->shopScopedRequestQuery()
                ->where('manager_status', 'approved'); // Only show manager-approved requests

            // Filter by owner status if provided
            if ($request->has('status') && $request->status !== 'all') {
                $statusMapping = [
                    'pending' => SuspensionStatus::PENDING_OWNER,
                    'approved' => SuspensionStatus::APPROVED,
                    'rejected' => SuspensionStatus::REJECTED_OWNER
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
                // Use SuspensionStatus enum's toFrontend() method for mapping
                $frontendStatus = $request->status->toFrontend();

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
            $request = $this->shopScopedRequestQuery()
                ->with('owner')
                ->findOrFail($id);

            $frontendStatus = $request->status->toFrontend();

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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Suspension request not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching suspension request: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch suspension request',
                'error' => $e->getMessage(),
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
            $suspensionRequest = $this->shopScopedRequestQuery()
                ->with(['employee.user', 'requester'])
                ->findOrFail($id);

            if (in_array($suspensionRequest->status, [SuspensionStatus::APPROVED, SuspensionStatus::REJECTED_OWNER])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has already been reviewed',
                ], 400);
            }

            if ($suspensionRequest->manager_status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has not been approved by the manager',
                ], 400);
            }

            $action = $request->input('action');
            $note = $request->input('note');
            $newStatus = $action === 'approve' ? SuspensionStatus::APPROVED : SuspensionStatus::REJECTED_OWNER;

            $suspensionRequest->update([
                'status' => $newStatus,
                'owner_id' => auth('shop_owner')->id(),
                'owner_status' => $action === 'approve' ? 'approved' : 'rejected',
                'owner_note' => $note,
                'owner_reviewed_at' => now(),
            ]);

            $employee = $suspensionRequest->employee;
            if ($employee) {
                if ($action === 'approve') {
                    $employee->update([
                        'status' => EmployeeStatus::SUSPENDED,
                        'suspension_reason' => $suspensionRequest->reason,
                    ]);
                } else {
                    $employee->update([
                        'status' => EmployeeStatus::ACTIVE,
                        'suspension_reason' => null,
                    ]);
                }
            }

            DB::commit();

            $shopOwnerId = (int) (auth('shop_owner')->id() ?? 0);
            $employeeName = (string) ($suspensionRequest->employee?->name ?? 'Employee');
            $decisionText = $action === 'approve' ? 'approved' : 'rejected';

            if ($shopOwnerId > 0) {
                $this->notificationService->notifyEmployeeSuspensionRequest($shopOwnerId, [
                    'suspension_request_id' => (int) $suspensionRequest->id,
                    'employee_id' => (int) $suspensionRequest->employee_id,
                    'employee_name' => $employeeName,
                    'requested_by' => (string) ($suspensionRequest->requester?->name ?? 'HR'),
                    'owner_decision' => $decisionText,
                ]);
            }

            $recipientUserIds = collect([
                (int) ($suspensionRequest->requested_by ?? 0),
                (int) ($suspensionRequest->employee?->user?->id ?? 0),
            ])->filter(fn (int $id): bool => $id > 0)->unique()->values();

            $hrDashboardPermissions = [
                'access-hr-dashboard',
                'access-employee-directory',
                'access-attendance-records',
                'access-leave-approvals',
                'access-overtime-approvals',
                'access-payslip-generation',
                'access-view-payslip',
            ];

            foreach ($recipientUserIds as $recipientUserId) {
                $recipientUser = User::find((int) $recipientUserId);
                $canOpenHrSuspensions = $recipientUser
                    ? $recipientUser->hasAnyPermission($hrDashboardPermissions)
                    : false;

                $this->notificationService->sendToUser(
                    userId: (int) $recipientUserId,
                    type: NotificationType::TASK_ASSIGNED,
                    title: 'Suspension Request Reviewed',
                    message: "Suspension request for {$employeeName} was {$decisionText} by the shop owner.",
                    data: [
                        'suspension_request_id' => (int) $suspensionRequest->id,
                        'employee_id' => (int) $suspensionRequest->employee_id,
                        'employee_name' => $employeeName,
                        'decision' => $decisionText,
                        'note' => $note,
                    ],
                    actionUrl: $canOpenHrSuspensions ? '/erp/hr?section=suspensions' : '/erp/notifications',
                    shopId: $shopOwnerId > 0 ? $shopOwnerId : null,
                    priority: 'high'
                );
            }

            return response()->json([
                'success' => true,
                'message' => $action === 'approve'
                    ? 'Suspension request approved successfully'
                    : 'Suspension request rejected successfully',
                'data' => $suspensionRequest,
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Suspension request not found',
            ], 404);
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
