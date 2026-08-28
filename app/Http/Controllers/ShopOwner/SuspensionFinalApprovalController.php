<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\SuspensionRequest;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Enums\EmployeeStatus;
use App\Enums\NotificationType;
use App\Enums\SuspensionStatus;
use App\Services\HR\EmployeeLinkedUserSynchronizer;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspensionFinalApprovalController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly EmployeeLinkedUserSynchronizer $linkedUserSynchronizer,
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
            Log::error('Error fetching suspension requests.', [
                'shop_owner_id' => $this->shopOwnerId(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch suspension requests',
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
            Log::error('Error fetching suspension request.', [
                'shop_owner_id' => $this->shopOwnerId(),
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

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
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:1000',
        ]);
        $note = trim((string) ($validated['note'] ?? ''));

        if ($validated['action'] === 'reject' && mb_strlen($note) < 3) {
            return response()->json([
                'success' => false,
                'message' => 'A meaningful rejection reason is required.',
                'code' => 'SUSPENSION_REJECTION_REASON_REQUIRED',
            ], 422);
        }

        $shopOwnerId = $this->shopOwnerId();

        try {
            $result = DB::transaction(function () use ($validated, $note, $id, $shopOwnerId): array {
                $suspensionRequest = SuspensionRequest::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $employee = Employee::query()
                    ->whereKey($suspensionRequest->employee_id)
                    ->where('shop_owner_id', $shopOwnerId)
                    ->lockForUpdate()
                    ->first();

                if (! $employee) {
                    throw (new ModelNotFoundException())->setModel(Employee::class, [$suspensionRequest->employee_id]);
                }

                if ($suspensionRequest->status !== SuspensionStatus::PENDING_OWNER) {
                    throw new \RuntimeException('This request has already reached its final review state.', 409);
                }

                if ($suspensionRequest->manager_status !== 'approved') {
                    throw new \RuntimeException('This request is not ready for Shop Owner review.', 409);
                }

                $approved = $validated['action'] === 'approve';
                $newStatus = $approved ? SuspensionStatus::APPROVED : SuspensionStatus::REJECTED_OWNER;
                $oldValues = [
                    'status' => $suspensionRequest->status->value,
                    'owner_status' => $suspensionRequest->owner_status,
                    'owner_id' => $suspensionRequest->owner_id,
                ];

                $suspensionRequest->forceFill([
                    'status' => $newStatus,
                    'owner_id' => $shopOwnerId,
                    'owner_status' => $approved ? 'approved' : 'rejected',
                    'owner_note' => $note !== '' ? $note : null,
                    'owner_reviewed_at' => now(),
                ])->save();

                $employee->forceFill([
                    'status' => $approved ? EmployeeStatus::SUSPENDED : EmployeeStatus::ACTIVE,
                    'suspension_reason' => $approved ? $suspensionRequest->reason : null,
                    'privileged_suspension_id' => null,
                ])->save();

                AuditLog::createLog([
                    'shop_owner_id' => $shopOwnerId,
                    'user_id' => null,
                    'employee_id' => $employee->getKey(),
                    'module' => AuditLog::MODULE_SUSPENSION,
                    'action' => $approved ? AuditLog::ACTION_APPROVED : AuditLog::ACTION_REJECTED,
                    'entity_type' => SuspensionRequest::class,
                    'entity_id' => $suspensionRequest->getKey(),
                    'description' => $approved
                        ? 'Shop Owner approved an employee suspension request.'
                        : "Shop Owner rejected an employee suspension request: {$note}",
                    'old_values' => $oldValues,
                    'new_values' => [
                        'status' => $newStatus->value,
                        'owner_status' => $suspensionRequest->owner_status,
                        'owner_id' => $shopOwnerId,
                        'reason' => $note !== '' ? $note : null,
                    ],
                    'severity' => $approved ? AuditLog::SEVERITY_CRITICAL : AuditLog::SEVERITY_WARNING,
                    'tags' => ['suspension', 'workflow', 'owner-review', 'actor_type:shop_owner'],
                ]);

                $suspensionRequest->setRelation('employee', $employee->load('user'));

                return [
                    'request' => $suspensionRequest->fresh(['employee.user', 'requester', 'manager']),
                    'employee_id' => (int) $employee->getKey(),
                    'approved' => $approved,
                ];
            });

            $suspensionRequest = $result['request'];
            $employeeName = (string) ($suspensionRequest->employee?->name ?? 'Employee');
            $decisionText = $result['approved'] ? 'approved' : 'rejected';

            $this->linkedUserSynchronizer->sync($suspensionRequest->employee);

            try {
                $this->notificationService->notifyEmployeeSuspensionRequest($shopOwnerId, [
                    'suspension_request_id' => (int) $suspensionRequest->id,
                    'employee_id' => (int) $suspensionRequest->employee_id,
                    'employee_name' => $employeeName,
                    'requested_by' => (string) ($suspensionRequest->requester?->name ?? 'HR'),
                    'owner_decision' => $decisionText,
                ]);
                $recipientUserIds = collect([
                    (int) ($suspensionRequest->requested_by ?? 0),
                    (int) ($suspensionRequest->employee?->user?->id ?? 0),
                ])->filter(fn (int $userId): bool => $userId > 0)->unique()->values();

                foreach ($recipientUserIds as $recipientUserId) {
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
                        actionUrl: '/erp/notifications',
                        shopId: $shopOwnerId > 0 ? $shopOwnerId : null,
                        priority: 'high'
                    );
                }
            } catch (\Throwable $notificationException) {
                Log::warning('Suspension owner decision notifications failed.', [
                    'request_id' => $id,
                    'error' => $notificationException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $result['approved']
                    ? 'Suspension request approved successfully'
                    : 'Suspension request rejected successfully',
            'data' => $suspensionRequest,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Suspension request not found',
            ], 404);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => 'SUSPENSION_REQUEST_ALREADY_DECIDED',
                ], 409);
            }

            Log::error('Error reviewing suspension request.', ['request_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to review suspension request',
            ], 500);
        } catch (\Throwable $e) {
            Log::error('Error reviewing suspension request.', ['request_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to review suspension request',
            ], 500);
        }
    }
}
