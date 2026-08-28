<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Manager;

use App\Enums\EmployeeStatus;
use App\Enums\SuspensionStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\SuspensionRequest;
use App\Models\User;
use App\Services\HR\EmployeeLinkedUserSynchronizer;
use App\Services\Manager\ManagerAuthorizationService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SuspensionApprovalController extends Controller
{
    public function __construct(
        private readonly ManagerAuthorizationService $authorization,
        private readonly EmployeeLinkedUserSynchronizer $linkedUserSynchronizer,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        $shopOwnerId = $actor ? $this->authorization->shopOwnerId($actor) : null;

        if (! $actor || $shopOwnerId === null || ! $this->authorization->allows(
            $actor,
            ManagerAuthorizationService::SUSPENSION_APPROVALS_READ,
            $shopOwnerId,
        )) {
            return $this->forbiddenResponse('suspension-approvals-read');
        }

        $statusFilter = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $perPage = max(5, min((int) $request->query('per_page', 25), 100));
        $allowedFilters = ['all', 'pending', 'pending_manager', 'pending_owner', 'approved', 'rejected', 'rejected_manager', 'rejected_owner'];

        if (! in_array($statusFilter, $allowedFilters, true)) {
            return response()->json([
                'message' => 'The selected suspension status is invalid.',
                'code' => 'INVALID_SUSPENSION_STATUS_FILTER',
            ], 422);
        }

        $query = $this->scopedRequestQuery($shopOwnerId)
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->latest('created_at');

        if ($statusFilter === 'pending') {
            $query->whereIn('status', [SuspensionStatus::PENDING_MANAGER, SuspensionStatus::PENDING_OWNER]);
        } elseif ($statusFilter === 'approved') {
            $query->whereIn('status', [SuspensionStatus::PENDING_OWNER, SuspensionStatus::APPROVED, SuspensionStatus::REJECTED_OWNER]);
        } elseif ($statusFilter === 'rejected') {
            $query->whereIn('status', [SuspensionStatus::REJECTED_MANAGER, SuspensionStatus::REJECTED_OWNER]);
        } elseif ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($employeeQuery) use ($search): void {
                        $employeeQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $requests = $query->paginate($perPage);
        $requests->setCollection($requests->getCollection()->map(fn (SuspensionRequest $suspensionRequest): array => $this->mapRequest($suspensionRequest)));

        $metricsQuery = $this->scopedRequestQuery($shopOwnerId);
        $pending = (clone $metricsQuery)->where('status', SuspensionStatus::PENDING_MANAGER)->count();
        $awaitingOwner = (clone $metricsQuery)->where('status', SuspensionStatus::PENDING_OWNER)->count();
        $approved = (clone $metricsQuery)->whereIn('status', [
            SuspensionStatus::PENDING_OWNER,
            SuspensionStatus::APPROVED,
        ])->count();
        $rejected = (clone $metricsQuery)->whereIn('status', [SuspensionStatus::REJECTED_MANAGER, SuspensionStatus::REJECTED_OWNER])->count();

        return response()->json([
            'data' => $requests,
            'metrics' => [
                'pending' => $pending,
                'awaiting_owner' => $awaitingOwner,
                'approved' => $approved,
                'rejected' => $rejected,
                'total' => $pending + $approved + $rejected,
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        $shopOwnerId = $actor ? $this->authorization->shopOwnerId($actor) : null;

        if (! $actor || $shopOwnerId === null || ! $this->authorization->allows(
            $actor,
            ManagerAuthorizationService::SUSPENSION_APPROVALS_READ,
            $shopOwnerId,
        )) {
            return $this->forbiddenResponse('suspension-approvals-read');
        }

        $suspensionRequest = $this->scopedRequestQuery($shopOwnerId)
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->whereKey($id)
            ->firstOrFail();

        return response()->json($this->mapRequest($suspensionRequest));
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        $shopOwnerId = $actor ? $this->authorization->shopOwnerId($actor) : null;

        if (! $actor || $shopOwnerId === null || ! $this->authorization->allows(
            $actor,
            ManagerAuthorizationService::SUSPENSION_DECISION,
            $shopOwnerId,
        )) {
            return $this->forbiddenResponse('suspension-decision');
        }

        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $reviewNote = trim((string) ($validated['note'] ?? $validated['reason'] ?? ''));

        if ($validated['action'] === 'reject' && mb_strlen($reviewNote) < 3) {
            return response()->json([
                'message' => 'A meaningful rejection reason is required.',
                'code' => 'SUSPENSION_REJECTION_REASON_REQUIRED',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($actor, $shopOwnerId, $validated, $reviewNote, $id): array {
                $suspensionRequest = SuspensionRequest::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $employee = Employee::query()
                    ->whereKey($suspensionRequest->employee_id)
                    ->where('shop_owner_id', $shopOwnerId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($suspensionRequest->status !== SuspensionStatus::PENDING_MANAGER) {
                    throw new \RuntimeException('This request has already reached a decision.', 409);
                }

                $oldValues = [
                    'status' => $suspensionRequest->status->value,
                    'manager_status' => $suspensionRequest->manager_status,
                    'manager_id' => $suspensionRequest->manager_id,
                ];
                $approved = $validated['action'] === 'approve';
                $newStatus = $approved ? SuspensionStatus::PENDING_OWNER : SuspensionStatus::REJECTED_MANAGER;

                $suspensionRequest->forceFill([
                    'status' => $newStatus,
                    'manager_id' => $actor->getKey(),
                    'manager_status' => $approved ? 'approved' : 'rejected',
                    'manager_note' => $reviewNote !== '' ? $reviewNote : null,
                    'manager_reviewed_at' => now(),
                ])->save();

                if (! $approved) {
                    $employee->forceFill([
                        'status' => EmployeeStatus::ACTIVE,
                        'suspension_reason' => null,
                        'privileged_suspension_id' => null,
                    ])->save();
                }

                AuditLog::createLog([
                    'shop_owner_id' => $shopOwnerId,
                    'user_id' => $actor->getKey(),
                    'employee_id' => $employee->getKey(),
                    'module' => AuditLog::MODULE_SUSPENSION,
                    'action' => $approved ? AuditLog::ACTION_APPROVED : AuditLog::ACTION_REJECTED,
                    'entity_type' => SuspensionRequest::class,
                    'entity_id' => $suspensionRequest->getKey(),
                    'description' => $approved
                        ? 'Manager approved a suspension request for Owner review.'
                        : "Manager rejected a suspension request: {$reviewNote}",
                    'old_values' => $oldValues,
                    'new_values' => [
                        'status' => $newStatus->value,
                        'manager_status' => $suspensionRequest->manager_status,
                        'manager_id' => $actor->getKey(),
                        'reason' => $reviewNote !== '' ? $reviewNote : null,
                        'reference_id' => 'suspension:' . $suspensionRequest->getKey(),
                    ],
                    'severity' => $approved ? AuditLog::SEVERITY_WARNING : AuditLog::SEVERITY_INFO,
                    'tags' => ['suspension', 'workflow', 'manager-review'],
                ]);

                return [
                    'request' => $suspensionRequest->fresh(['employee', 'requester', 'manager']),
                    'employee_id' => (int) $employee->getKey(),
                    'approved' => $approved,
                ];
            });
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Suspension request not found.'], 404);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 409) {
                return response()->json([
                    'message' => 'This suspension request has already been decided.',
                    'code' => 'SUSPENSION_REQUEST_ALREADY_DECIDED',
                ], 409);
            }

            Log::error('Manager suspension review failed.', [
                'request_id' => $id,
                'actor_id' => $actor->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Unable to review the suspension request.'], 500);
        } catch (\Throwable $exception) {
            Log::error('Manager suspension review failed.', [
                'request_id' => $id,
                'actor_id' => $actor->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Unable to review the suspension request.'], 500);
        }

        if (! $result['approved']) {
            $this->linkedUserSynchronizer->sync($result['request']->employee);
        } else {
            try {
                $this->notificationService->notifyEmployeeSuspensionRequest($shopOwnerId, [
                    'suspension_request_id' => $result['request']->getKey(),
                    'employee_id' => $result['employee_id'],
                    'employee_name' => $this->employeeName($result['request']->employee),
                    'requested_by' => $actor->name ?? $actor->email,
                    'manager_decision' => 'approved',
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Could not notify Shop Owner of manager suspension approval.', [
                    'request_id' => $id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $result['approved']
                ? 'Suspension request approved and forwarded to shop owner.'
                : 'Suspension request rejected.',
            'request' => $this->mapRequest($result['request']),
        ]);
    }

    private function scopedRequestQuery(int $shopOwnerId)
    {
        return SuspensionRequest::query()->whereHas('employee', function ($employeeQuery) use ($shopOwnerId): void {
            $employeeQuery->where('shop_owner_id', $shopOwnerId);
        });
    }

    private function mapRequest(SuspensionRequest $suspensionRequest): array
    {
        $status = $suspensionRequest->status instanceof SuspensionStatus
            ? $suspensionRequest->status
            : SuspensionStatus::from((string) $suspensionRequest->status);
        $createdAt = $suspensionRequest->created_at;
        $slaMinutes = config('manager.suspension_sla_minutes');
        $slaConfigured = is_numeric($slaMinutes) && (int) $slaMinutes > 0;
        $ageMinutes = $createdAt ? $createdAt->diffInMinutes(now()) : 0;

        return [
            'id' => (int) $suspensionRequest->getKey(),
            'employee_id' => (int) $suspensionRequest->employee_id,
            'name' => $this->employeeName($suspensionRequest->employee),
            'email' => $suspensionRequest->employee?->email,
            'position' => $suspensionRequest->employee?->position,
            'reason' => $suspensionRequest->reason,
            'evidence' => $suspensionRequest->evidence,
            'status' => $this->mapStatusForManager($status),
            'workflow_status' => $status->value,
            'approval_stage' => $this->approvalStage($status),
            'requested_at' => optional($createdAt)->toDateTimeString(),
            'requestedAt' => optional($createdAt)->toDateTimeString(),
            'requested_by' => $suspensionRequest->requester?->name ?? $suspensionRequest->requester?->email,
            'manager_status' => $suspensionRequest->manager_status,
            'manager_note' => $suspensionRequest->manager_note,
            'manager_name' => $suspensionRequest->manager?->name ?? $suspensionRequest->manager?->email,
            'owner_status' => $suspensionRequest->owner_status,
            'owner_note' => $suspensionRequest->owner_note,
            'approvedBy' => $suspensionRequest->manager?->name ?? $suspensionRequest->manager?->email,
            'approvalDate' => optional($suspensionRequest->manager_reviewed_at)->toDateTimeString(),
            'approvalNote' => $suspensionRequest->manager_note,
            'rejectionReason' => $status === SuspensionStatus::REJECTED_MANAGER ? $suspensionRequest->manager_note : null,
            'age_days' => $createdAt ? $createdAt->diffInDays(now()) : 0,
            'overdue' => $slaConfigured && $ageMinutes > (int) $slaMinutes,
            'sla' => [
                'configured' => $slaConfigured,
                'minutes' => $slaConfigured ? (int) $slaMinutes : null,
            ],
            'next_action' => $this->nextAction($status),
            'previous_decisions' => [
                [
                    'stage' => 'manager',
                    'status' => $suspensionRequest->manager_status,
                    'actor_id' => $suspensionRequest->manager_id,
                    'at' => optional($suspensionRequest->manager_reviewed_at)->toDateTimeString(),
                    'reason' => $suspensionRequest->manager_note,
                ],
                [
                    'stage' => 'owner',
                    'status' => $suspensionRequest->owner_status,
                    'actor_id' => $suspensionRequest->owner_id,
                    'at' => optional($suspensionRequest->owner_reviewed_at)->toDateTimeString(),
                    'reason' => $suspensionRequest->owner_note,
                ],
            ],
        ];
    }

    private function mapStatusForManager(SuspensionStatus $status): string
    {
        return $status->toFrontend();
    }

    private function approvalStage(SuspensionStatus $status): string
    {
        return match ($status) {
            SuspensionStatus::PENDING_MANAGER => 'manager',
            SuspensionStatus::PENDING_OWNER => 'owner',
            default => 'complete',
        };
    }

    private function nextAction(SuspensionStatus $status): string
    {
        return match ($status) {
            SuspensionStatus::PENDING_MANAGER => 'Manager decision required',
            SuspensionStatus::PENDING_OWNER => 'Waiting for Shop Owner decision',
            default => 'No further action',
        };
    }

    private function employeeName(?Employee $employee): string
    {
        if (! $employee) {
            return '';
        }

        $name = trim((string) ($employee->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($employee->first_name ?? '') . ' ' . (string) ($employee->last_name ?? ''))
            ?: (string) ($employee->email ?? '');
    }

    private function forbiddenResponse(string $capability): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to access this Manager capability.',
            'error' => 'INSUFFICIENT_MANAGER_CAPABILITY',
            'capability' => $capability,
        ], 403);
    }
}
