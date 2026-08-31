<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Enums\EmployeeLifecycleRequestStatus;
use App\Enums\EmployeeLifecycleRequestType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeLifecycleRequest;
use App\Models\ShopOwner;
use App\Services\HR\EmployeeLifecycleRequestPresenter;
use App\Services\HR\EmployeeLifecycleWorkflowService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EmployeeLifecycleFinalApprovalController extends Controller
{
    public function __construct(
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
        $owner = $this->owner();
        if (! $owner) {
            return $this->forbiddenResponse();
        }

        $query = $this->scopedQuery($owner, $type)
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->latest('created_at');

        $status = (string) $request->query('status', 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        } else {
            $query->where('manager_status', 'approved');
        }

        $requests = $query->paginate(max(5, min((int) $request->query('per_page', 25), 100)));
        $requests->setCollection($requests->getCollection()->map(
            fn (EmployeeLifecycleRequest $lifecycleRequest): array => $this->presenter->toArray($lifecycleRequest),
        ));

        return response()->json(['data' => $requests]);
    }

    private function show(int $id, EmployeeLifecycleRequestType $type): JsonResponse
    {
        $owner = $this->owner();
        if (! $owner) {
            return $this->forbiddenResponse();
        }

        $lifecycleRequest = $this->scopedQuery($owner, $type)
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->whereKey($id)
            ->firstOrFail();

        return response()->json($this->presenter->toArray($lifecycleRequest));
    }

    private function review(Request $request, int $id, EmployeeLifecycleRequestType $type): JsonResponse
    {
        $owner = $this->owner();
        if (! $owner) {
            return $this->forbiddenResponse();
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
            $result = $this->workflow->ownerReview(
                owner: $owner,
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

            if ($exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'EMPLOYEE_REHIRE_ROLE_INVALID',
                ], 422);
            }

            Log::warning('Employee lifecycle owner review conflict.', [
                'request_id' => $id,
                'type' => $type->value,
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['message' => 'Unable to review the employee lifecycle request.'], 409);
        }

        try {
            $requestModel = $result['request'];
            $this->notificationService->notifyEmployeeLifecycleReviewed(
                (int) $owner->getKey(),
                [
                    'employee_lifecycle_request_id' => (int) $requestModel->getKey(),
                    'employee_id' => (int) $requestModel->employee_id,
                    'employee_name' => $requestModel->employee?->name ?? 'Employee',
                    'request_type' => $type->value,
                    'requester_id' => (int) $requestModel->requested_by,
                    'owner_decision' => $result['approved'] ? 'approved' : 'rejected',
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('Employee lifecycle owner notification failed.', [
                'request_id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['approved']
                ? ucfirst($type->value).' request approved successfully.'
                : ucfirst($type->value).' request rejected successfully.',
            'data' => $this->presenter->toArray($result['request']),
        ]);
    }

    private function owner(): ?ShopOwner
    {
        $owner = Auth::guard('shop_owner')->user();

        return $owner instanceof ShopOwner
            && strtolower(trim((string) $owner->registration_type)) === 'company'
            ? $owner
            : null;
    }

    private function scopedQuery(ShopOwner $owner, EmployeeLifecycleRequestType $type)
    {
        return EmployeeLifecycleRequest::query()
            ->where('request_type', $type->value)
            ->where('manager_status', 'approved')
            ->where('status', '!=', EmployeeLifecycleRequestStatus::PENDING_MANAGER->value)
            ->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('shop_owner_id', $owner->getKey()));
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Employee lifecycle approvals are available only to company Shop Owner accounts.',
            'code' => 'EMPLOYEE_LIFECYCLE_NOT_AUTHORIZED',
        ], 403);
    }
}
