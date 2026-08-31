<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp\HR;

use App\Enums\EmployeeLifecycleRequestType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeLifecycleRequest;
use App\Services\HR\EmployeeLifecycleRequestPresenter;
use App\Services\HR\EmployeeLifecycleWorkflowAuthorizationService;
use App\Services\HR\EmployeeLifecycleWorkflowService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class EmployeeLifecycleRequestController extends Controller
{
    public function __construct(
        private readonly EmployeeLifecycleWorkflowAuthorizationService $authorization,
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

    public function storeTermination(Request $request): JsonResponse
    {
        return $this->store($request, EmployeeLifecycleRequestType::TERMINATION);
    }

    public function storeRehire(Request $request): JsonResponse
    {
        return $this->store($request, EmployeeLifecycleRequestType::REHIRE);
    }

    private function index(Request $request, EmployeeLifecycleRequestType $type): JsonResponse
    {
        $actor = $request->user('user');
        if (! $actor || ! $this->authorization->canSubmit($actor, $type)) {
            return $this->forbiddenResponse();
        }

        $shopOwnerId = $this->authorization->shopOwnerId($actor);
        $query = EmployeeLifecycleRequest::query()
            ->where('request_type', $type->value)
            ->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('shop_owner_id', $shopOwnerId))
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->latest('created_at');

        $requests = $query->paginate(max(5, min((int) $request->query('per_page', 25), 100)));
        $requests->setCollection($requests->getCollection()->map(
            fn (EmployeeLifecycleRequest $lifecycleRequest): array => $this->presenter->toArray($lifecycleRequest),
        ));

        return response()->json(['data' => $requests]);
    }

    private function show(int $id, EmployeeLifecycleRequestType $type): JsonResponse
    {
        $actor = request()->user('user');
        if (! $actor || ! $this->authorization->canSubmit($actor, $type)) {
            return $this->forbiddenResponse();
        }

        $shopOwnerId = $this->authorization->shopOwnerId($actor);
        $lifecycleRequest = EmployeeLifecycleRequest::query()
            ->whereKey($id)
            ->where('request_type', $type->value)
            ->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('shop_owner_id', $shopOwnerId))
            ->with(['employee', 'requester', 'manager', 'owner'])
            ->firstOrFail();

        return response()->json($this->presenter->toArray($lifecycleRequest));
    }

    private function store(Request $request, EmployeeLifecycleRequestType $type): JsonResponse
    {
        $actor = $request->user('user');
        if (! $actor || ! $this->authorization->canSubmit($actor, $type)) {
            return $this->forbiddenResponse();
        }

        $rules = [
            'employee_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ];

        if ($type === EmployeeLifecycleRequestType::REHIRE) {
            $rules += [
                'rehire_start_date' => ['required', 'date'],
                'rehire_position' => ['required', 'string', 'max:100'],
                'rehire_department' => ['nullable', 'string', 'max:100'],
                'rehire_functional_role' => ['nullable', 'string', 'max:100'],
                'rehire_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
                'rehire_role' => ['required', 'string', 'max:100'],
            ];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please correct the lifecycle request details.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $employee = $this->authorization->employeeForActor($actor, (int) $validator->validated()['employee_id']);
        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $validated = $validator->validated();
        if ($type === EmployeeLifecycleRequestType::REHIRE) {
            $shopOwner = $this->authorization->shopOwnerFor($actor);
            $resolvedRole = $shopOwner
                ? $this->workflow->resolveRehireRole($shopOwner, (string) $validated['rehire_role'])
                : null;

            if ($resolvedRole === null) {
                return response()->json([
                    'message' => 'The selected rehire role is not available for this company.',
                    'errors' => ['rehire_role' => ['Select a valid role for this company.']],
                ], 422);
            }

            $rehireDate = Carbon::parse($validated['rehire_start_date'])->startOfDay();
            if ($employee->terminated_at !== null
                && $rehireDate->lessThanOrEqualTo($employee->terminated_at->copy()->startOfDay())) {
                return response()->json([
                    'message' => 'The rehire date must be after the termination date.',
                    'errors' => ['rehire_start_date' => ['The rehire date must be after the termination date.']],
                ], 422);
            }

            $validated['rehire_role'] = $resolvedRole;
        }

        try {
            $lifecycleRequest = $this->workflow->submit($actor, $employee, $type, $validated);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 409) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'EMPLOYEE_LIFECYCLE_REQUEST_CONFLICT',
                ], 409);
            }

            throw $exception;
        }

        try {
            $this->notificationService->notifyEmployeeLifecycleSubmitted(
                (int) $employee->shop_owner_id,
                [
                    'employee_lifecycle_request_id' => (int) $lifecycleRequest->getKey(),
                    'employee_id' => (int) $employee->getKey(),
                    'employee_name' => $this->employeeName($employee),
                    'request_type' => $type->value,
                    'requester_id' => (int) $actor->getKey(),
                    'requested_by' => (string) ($actor->name ?? $actor->email),
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('Employee lifecycle request notification failed.', [
                'request_id' => $lifecycleRequest->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $type === EmployeeLifecycleRequestType::TERMINATION
                ? 'Termination request submitted for Manager review.'
                : 'Rehire request submitted for Manager review.',
            'request' => $this->presenter->toArray($lifecycleRequest),
        ], 201);
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'You are not authorized to submit this employee lifecycle request.',
            'code' => 'EMPLOYEE_LIFECYCLE_NOT_AUTHORIZED',
        ], 403);
    }

    private function employeeName(mixed $employee): string
    {
        return trim((string) ($employee->name ?: implode(' ', array_filter([
            $employee->first_name,
            $employee->last_name,
        ])))) ?: 'Employee';
    }
}
