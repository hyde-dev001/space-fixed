<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\HR;

use App\Enums\EmployeeStatus;
use App\Enums\SuspensionStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\SuspensionRequest;
use App\Models\User;
use App\Services\HR\SuspensionWorkflowAuthorizationService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SuspensionRequestController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly SuspensionWorkflowAuthorizationService $authorization,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        if (! $actor || ! $this->authorization->canReadOrSubmit($actor)) {
            return response()->json(['message' => 'Suspension requests are restricted to HR.'], 403);
        }

        $shopOwnerId = $this->authorization->shopOwnerId($actor);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending_manager,pending_owner,approved,rejected_manager,rejected_owner'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = SuspensionRequest::query()
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->whereHas('employee', function ($employeeQuery) use ($shopOwnerId): void {
                $employeeQuery->where('shop_owner_id', $shopOwnerId);
            })
            ->latest('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($employeeQuery) use ($search): void {
                        $employeeQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $requests = $query->paginate((int) ($validated['per_page'] ?? 25));
        $requests->setCollection($requests->getCollection()->map(fn (SuspensionRequest $suspensionRequest): array => $this->mapRequest($suspensionRequest)));

        return response()->json($requests);
    }

    public function show(int $id): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        if (! $actor || ! $this->authorization->canReadOrSubmit($actor)) {
            return response()->json(['message' => 'Suspension requests are restricted to HR.'], 403);
        }

        $shopOwnerId = $this->authorization->shopOwnerId($actor);
        $suspensionRequest = SuspensionRequest::query()
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->whereKey($id)
            ->whereHas('employee', function ($employeeQuery) use ($shopOwnerId): void {
                $employeeQuery->where('shop_owner_id', $shopOwnerId);
            })
            ->firstOrFail();

        return response()->json($this->mapRequest($suspensionRequest));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        if (! $actor || ! $this->authorization->canReadOrSubmit($actor)) {
            return response()->json(['message' => 'Only HR may submit a suspension request.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);

        $shopOwnerId = $this->authorization->shopOwnerId($actor);

        try {
            $suspensionRequest = DB::transaction(function () use ($actor, $shopOwnerId, $validated): SuspensionRequest {
                $employee = Employee::query()
                    ->whereKey((int) $validated['employee_id'])
                    ->where('shop_owner_id', $shopOwnerId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($employee->status === EmployeeStatus::TERMINATED) {
                    throw new \LogicException('Terminated employees must use the Rehire / Reinstate Employee workflow.');
                }

                $employeeEmail = Str::lower(trim((string) $employee->getAttribute('email')));
                $actorEmail = Str::lower(trim((string) $actor->getAttribute('email')));
                $linkedUserId = (int) (User::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereRaw('LOWER(email) = ?', [$employeeEmail])
                    ->value('id') ?? 0);

                if (($linkedUserId > 0 && $linkedUserId === (int) $actor->getKey())
                    || ($employeeEmail !== '' && $employeeEmail === $actorEmail)) {
                    abort(403, 'You cannot file a suspension request for your own account.');
                }

                $hasPending = SuspensionRequest::query()
                    ->where('employee_id', $employee->getKey())
                    ->whereIn('status', [
                        SuspensionStatus::PENDING_MANAGER,
                        SuspensionStatus::PENDING_OWNER,
                    ])
                    ->exists();

                if ($hasPending) {
                    abort(422, 'There is already a pending suspension request for this employee.');
                }

                $created = SuspensionRequest::query()->create([
                    'employee_id' => $employee->getKey(),
                    'requested_by' => $actor->getKey(),
                    'reason' => trim((string) $validated['reason']),
                    'evidence' => isset($validated['evidence']) ? trim((string) $validated['evidence']) : null,
                    'status' => SuspensionStatus::PENDING_MANAGER,
                    'manager_status' => 'pending',
                    'owner_status' => 'pending',
                ]);

                AuditLog::createLog([
                    'shop_owner_id' => $shopOwnerId,
                    'user_id' => $actor->getKey(),
                    'employee_id' => $employee->getKey(),
                    'module' => AuditLog::MODULE_SUSPENSION,
                    'action' => AuditLog::ACTION_CREATED,
                    'entity_type' => SuspensionRequest::class,
                    'entity_id' => $created->getKey(),
                    'description' => "Suspension request submitted for {$this->employeeName($employee)}.",
                    'new_values' => [
                        'status' => SuspensionStatus::PENDING_MANAGER->value,
                        'reason' => $created->reason,
                    ],
                    'severity' => AuditLog::SEVERITY_WARNING,
                    'tags' => ['suspension', 'workflow'],
                ]);

                $created->setRelation('employee', $employee);

                return $created;
            });
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Employee not found.'], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->getStatusCode() === 403
                    ? 'SUSPENSION_REQUEST_FORBIDDEN'
                    : 'SUSPENSION_REQUEST_ALREADY_PENDING',
                ], $exception->getStatusCode());
        } catch (\LogicException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'EMPLOYEE_REHIRE_REQUIRED',
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Failed to submit suspension request.', [
                'actor_id' => $actor->getKey(),
                'shop_owner_id' => $shopOwnerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Unable to submit the suspension request.'], 500);
        }

        try {
            $this->notificationService->notifySuspensionSubmitted($shopOwnerId, [
                'suspension_id' => $suspensionRequest->getKey(),
                'employee_name' => $this->employeeName($suspensionRequest->employee),
                'reason' => $suspensionRequest->reason,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Could not notify Manager of suspension request.', [
                'suspension_id' => $suspensionRequest->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Suspension request submitted successfully.',
            'request' => $suspensionRequest->fresh(['employee', 'requester']),
        ], 201);
    }

    private function mapRequest(SuspensionRequest $suspensionRequest): array
    {
        $status = $suspensionRequest->status instanceof SuspensionStatus
            ? $suspensionRequest->status
            : SuspensionStatus::from((string) $suspensionRequest->status);
        $createdAt = $suspensionRequest->created_at;
        $ageDays = $createdAt ? $createdAt->diffInDays(now()) : 0;

        return [
            'id' => (int) $suspensionRequest->getKey(),
            'employee_id' => (int) $suspensionRequest->employee_id,
            'name' => $this->employeeName($suspensionRequest->employee),
            'email' => $suspensionRequest->employee?->email,
            'position' => $suspensionRequest->employee?->position,
            'reason' => $suspensionRequest->reason,
            'evidence' => $suspensionRequest->evidence,
            'status' => $status->value,
            'approval_stage' => $this->approvalStage($status),
            'requested_at' => optional($createdAt)->toDateTimeString(),
            'requested_by' => $suspensionRequest->requester?->name ?? $suspensionRequest->requester?->email,
            'manager_status' => $suspensionRequest->manager_status,
            'manager_note' => $suspensionRequest->manager_note,
            'manager_name' => $suspensionRequest->manager?->name ?? $suspensionRequest->manager?->email,
            'owner_status' => $suspensionRequest->owner_status,
            'owner_note' => $suspensionRequest->owner_note,
            'age_days' => $ageDays,
            'overdue' => false,
            'sla' => ['configured' => false, 'minutes' => null],
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
            SuspensionStatus::PENDING_MANAGER => 'Waiting for Manager decision',
            SuspensionStatus::PENDING_OWNER => 'Waiting for Shop Owner decision',
            SuspensionStatus::APPROVED, SuspensionStatus::REJECTED_MANAGER, SuspensionStatus::REJECTED_OWNER => 'No further action',
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
}
