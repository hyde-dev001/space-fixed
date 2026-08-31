<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp\Manager;

use App\Enums\EmployeeLifecycleRequestStatus;
use App\Enums\EmployeeLifecycleRequestType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeLifecycleRequest;
use App\Services\HR\EmployeeLifecycleRequestPresenter;
use App\Services\HR\EmployeeLifecycleWorkflowService;
use App\Services\Manager\ManagerAuthorizationService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

final class EmployeeLifecycleApprovalController extends Controller
{
    public function __construct(
        private readonly ManagerAuthorizationService $authorization,
        private readonly EmployeeLifecycleWorkflowService $workflow,
        private readonly EmployeeLifecycleRequestPresenter $presenter,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function indexTermination(Request $request): JsonResponse
    {
        return $this->index($request, EmployeeLifecycleRequestType::TERMINATION);
    }

    public function indexRehire(Request $request): JsonResponse
    {
        return $this->index($request, EmployeeLifecycleRequestType::REHIRE);
    }

    public function showTermination(int $id): JsonResponse
    {
        return $this->show($id, EmployeeLifecycleRequestType::TERMINATION);
    }

    public function showRehire(int $id): JsonResponse
    {
        return $this->show($id, EmployeeLifecycleRequestType::REHIRE);
    }

    public function reviewTermination(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, EmployeeLifecycleRequestType::TERMINATION);
    }

    public function reviewRehire(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, EmployeeLifecycleRequestType::REHIRE);
    }

    private function index(Request $request, EmployeeLifecycleRequestType $type): JsonResponse
    {
        [$actor, $shopOwnerId] = $this->authorizedContext($type, false);
        if (! $actor || $shopOwnerId === null) {
            return $this->forbiddenResponse($type, false);
        }

        $statusFilter = (string) $request->query('status', 'all');
        $allowedStatuses = [
            'all',
            EmployeeLifecycleRequestStatus::PENDING_MANAGER->value,
            EmployeeLifecycleRequestStatus::PENDING_OWNER->value,
            EmployeeLifecycleRequestStatus::APPROVED->value,
            EmployeeLifecycleRequestStatus::REJECTED_MANAGER->value,
            EmployeeLifecycleRequestStatus::REJECTED_OWNER->value,
        ];
        if (! in_array($statusFilter, $allowedStatuses, true)) {
            return response()->json([
                'message' => 'The selected employee lifecycle status is invalid.',
                'code' => 'INVALID_EMPLOYEE_LIFECYCLE_STATUS_FILTER',
            ], 422);
        }

        $query = $this->scopedQuery($shopOwnerId, $type)
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->latest('created_at');

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('reason', 'like', '%'.$search.'%')
                    ->orWhereHas('employee', function ($employeeQuery) use ($search): void {
                        $employeeQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    });
            });
        }

        $perPage = max(5, min((int) $request->query('per_page', 25), 100));
        $requests = $query->paginate($perPage);
        $requests->setCollection($requests->getCollection()->map(
            fn (EmployeeLifecycleRequest $lifecycleRequest): array => $this->presenter->toArray($lifecycleRequest),
        ));

        $metricsQuery = $this->scopedQuery($shopOwnerId, $type);

        return response()->json([
            'data' => $requests,
            'metrics' => [
                'pending' => (clone $metricsQuery)->where('status', EmployeeLifecycleRequestStatus::PENDING_MANAGER->value)->count(),
                'awaiting_owner' => (clone $metricsQuery)->where('status', EmployeeLifecycleRequestStatus::PENDING_OWNER->value)->count(),
                'approved' => (clone $metricsQuery)->where('status', EmployeeLifecycleRequestStatus::APPROVED->value)->count(),
                'rejected' => (clone $metricsQuery)->whereIn('status', [
                    EmployeeLifecycleRequestStatus::REJECTED_MANAGER->value,
                    EmployeeLifecycleRequestStatus::REJECTED_OWNER->value,
                ])->count(),
                'total' => (clone $metricsQuery)->count(),
            ],
        ]);
    }

    private function show(int $id, EmployeeLifecycleRequestType $type): JsonResponse
    {
        [$actor, $shopOwnerId] = $this->authorizedContext($type, false);
        if (! $actor || $shopOwnerId === null) {
            return $this->forbiddenResponse($type, false);
        }

        $lifecycleRequest = $this->scopedQuery($shopOwnerId, $type)
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->whereKey($id)
            ->firstOrFail();

        return response()->json($this->presenter->toArray($lifecycleRequest));
    }

    private function review(Request $request, int $id, EmployeeLifecycleRequestType $type): JsonResponse
    {
        [$actor, $shopOwnerId] = $this->authorizedContext($type, true);
        if (! $actor || $shopOwnerId === null) {
            return $this->forbiddenResponse($type, true);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $note = trim((string) ($validated['note'] ?? $validated['reason'] ?? ''));

        if ($validated['action'] === 'reject' && mb_strlen($note) < 3) {
            return response()->json([
                'message' => 'A meaningful rejection reason is required.',
                'code' => 'EMPLOYEE_LIFECYCLE_REJECTION_REASON_REQUIRED',
            ], 422);
        }

        try {
            $result = $this->workflow->managerReview(
                actor: $actor,
                shopOwnerId: $shopOwnerId,
                requestId: $id,
                type: $type,
                action: $validated['action'],
                note: $note,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Employee lifecycle request not found.'], 404);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 409) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => strtoupper($type->value).'_REQUEST_ALREADY_DECIDED',
                ], 409);
            }

            Log::warning('Employee lifecycle manager review conflict.', [
                'request_id' => $id,
                'type' => $type->value,
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['message' => 'Unable to review the employee lifecycle request.'], 409);
        }

        try {
            $requestModel = $result['request'];
            $this->notificationService->notifyEmployeeLifecycleReviewed(
                $shopOwnerId,
                [
                    'employee_lifecycle_request_id' => (int) $requestModel->getKey(),
                    'employee_id' => (int) $requestModel->employee_id,
                    'employee_name' => $requestModel->employee?->name ?? 'Employee',
                    'request_type' => $type->value,
                    'requester_id' => (int) $requestModel->requested_by,
                    'manager_decision' => $result['approved'] ? 'approved' : 'rejected',
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('Employee lifecycle manager notification failed.', [
                'request_id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $result['approved']
                ? ucfirst($type->value).' request approved and forwarded to shop owner.'
                : ucfirst($type->value).' request rejected.',
            'request' => $this->presenter->toArray($result['request']),
        ]);
    }

    private function scopedQuery(int $shopOwnerId, EmployeeLifecycleRequestType $type)
    {
        return EmployeeLifecycleRequest::query()
            ->where('request_type', $type->value)
            ->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('shop_owner_id', $shopOwnerId));
    }

    /** @return array{0: \App\Models\User|null, 1: int|null} */
    private function authorizedContext(EmployeeLifecycleRequestType $type, bool $decision): array
    {
        $actor = Auth::guard('user')->user();
        $shopOwnerId = $actor ? $this->authorization->shopOwnerId($actor) : null;
        $capability = match ([$type, $decision]) {
            [EmployeeLifecycleRequestType::TERMINATION, false] => ManagerAuthorizationService::TERMINATION_APPROVALS_READ,
            [EmployeeLifecycleRequestType::REHIRE, false] => ManagerAuthorizationService::REHIRE_APPROVALS_READ,
            [EmployeeLifecycleRequestType::TERMINATION, true] => ManagerAuthorizationService::TERMINATION_DECISION,
            [EmployeeLifecycleRequestType::REHIRE, true] => ManagerAuthorizationService::REHIRE_DECISION,
        };

        if (
            ! $actor
            || $shopOwnerId === null
            || ! $this->authorization->isCompanyShop($actor)
            || ! $this->authorization->allows($actor, $capability, $shopOwnerId)
        ) {
            return [null, null];
        }

        return [$actor, $shopOwnerId];
    }

    private function forbiddenResponse(EmployeeLifecycleRequestType $type, bool $decision): JsonResponse
    {
        return response()->json([
            'message' => 'You are not authorized to '.($decision ? 'decide on' : 'view').' this '.$type->value.' request.',
            'code' => $decision
                ? strtoupper($type->value).'_DECISION_FORBIDDEN'
                : strtoupper($type->value).'_APPROVALS_READ_FORBIDDEN',
        ], 403);
    }
}
