<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Enums\EmployeeLifecycleRequestStatus;
use App\Enums\EmployeeLifecycleRequestType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeEmploymentPeriod;
use App\Models\EmployeeLifecycleRequest;
use App\Models\HR\AuditLog;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\BusinessAccessControlService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class EmployeeLifecycleWorkflowService
{
    public function __construct(
        private readonly EmployeeLinkedUserSynchronizer $linkedUserSynchronizer,
        private readonly BusinessAccessControlService $businessAccess,
    ) {
    }

    public function resolveRehireRole(ShopOwner $shopOwner, string $requestedRole): ?string
    {
        $requestedRole = trim($requestedRole);
        if ($requestedRole === '') {
            return null;
        }

        $role = Role::query()
            ->where('guard_name', 'user')
            ->whereRaw('LOWER(name) = ?', [strtolower($requestedRole)])
            ->first();

        if (! $role || in_array(strtolower((string) $role->name), ['shop owner', 'super admin'], true)) {
            return null;
        }

        $allowedRoles = array_map(
            static fn (string $name): string => strtolower(trim($name)),
            $this->businessAccess->getAllowedRoles($shopOwner),
        );

        return in_array(strtolower((string) $role->name), $allowedRoles, true)
            ? (string) $role->name
            : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function submit(
        User $actor,
        Employee $employee,
        EmployeeLifecycleRequestType $type,
        array $attributes,
    ): EmployeeLifecycleRequest {
        return DB::transaction(function () use ($actor, $employee, $type, $attributes): EmployeeLifecycleRequest {
            $lockedEmployee = Employee::query()
                ->whereKey($employee->getKey())
                ->where('shop_owner_id', $employee->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertExpectedEmployeeState($lockedEmployee, $type);
            $this->assertNoPendingRequest($lockedEmployee);

            $request = EmployeeLifecycleRequest::query()->create([
                'employee_id' => $lockedEmployee->getKey(),
                'requested_by' => $actor->getKey(),
                'request_type' => $type,
                'reason' => trim((string) $attributes['reason']),
                'evidence' => isset($attributes['evidence']) && trim((string) $attributes['evidence']) !== ''
                    ? trim((string) $attributes['evidence'])
                    : null,
                'status' => EmployeeLifecycleRequestStatus::PENDING_MANAGER,
                'manager_status' => 'pending',
                'owner_status' => 'pending',
                'rehire_start_date' => $attributes['rehire_start_date'] ?? null,
                'rehire_position' => $attributes['rehire_position'] ?? null,
                'rehire_department' => $attributes['rehire_department'] ?? null,
                'rehire_functional_role' => $attributes['rehire_functional_role'] ?? null,
                'rehire_salary' => $attributes['rehire_salary'] ?? null,
                'rehire_role' => $attributes['rehire_role'] ?? null,
            ]);

            AuditLog::createLog([
                'shop_owner_id' => $lockedEmployee->shop_owner_id,
                'user_id' => $actor->getKey(),
                'employee_id' => $lockedEmployee->getKey(),
                'module' => AuditLog::MODULE_EMPLOYEE,
                'action' => AuditLog::ACTION_CREATED,
                'entity_type' => EmployeeLifecycleRequest::class,
                'entity_id' => $request->getKey(),
                'description' => ucfirst($type->value).' request submitted for '.$this->employeeName($lockedEmployee).'.',
                'new_values' => [
                    'request_type' => $type->value,
                    'status' => EmployeeLifecycleRequestStatus::PENDING_MANAGER->value,
                    'employee_id' => $lockedEmployee->getKey(),
                ],
                'severity' => AuditLog::SEVERITY_WARNING,
                'tags' => ['employee-lifecycle', $type->value, 'workflow'],
            ]);

            return $request->fresh(['employee', 'requester']);
        });
    }

    /**
     * @return array{request: EmployeeLifecycleRequest, approved: bool}
     */
    public function managerReview(
        User $actor,
        int $shopOwnerId,
        int $requestId,
        EmployeeLifecycleRequestType $type,
        string $action,
        string $note,
    ): array {
        return DB::transaction(function () use ($actor, $shopOwnerId, $requestId, $type, $action, $note): array {
            $request = EmployeeLifecycleRequest::query()
                ->whereKey($requestId)
                ->where('request_type', $type->value)
                ->lockForUpdate()
                ->firstOrFail();
            $employee = Employee::query()
                ->whereKey($request->employee_id)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertExpectedEmployeeState($employee, $type);
            if ($request->status !== EmployeeLifecycleRequestStatus::PENDING_MANAGER) {
                throw new \RuntimeException('This request has already reached a decision.', 409);
            }

            $approved = $action === 'approve';
            $newStatus = $approved
                ? EmployeeLifecycleRequestStatus::PENDING_OWNER
                : EmployeeLifecycleRequestStatus::REJECTED_MANAGER;

            $request->forceFill([
                'status' => $newStatus,
                'manager_id' => $actor->getKey(),
                'manager_status' => $approved ? 'approved' : 'rejected',
                'manager_note' => $note !== '' ? $note : null,
                'manager_reviewed_at' => now(),
            ])->save();

            $this->auditDecision(
                actorId: (int) $actor->getKey(),
                shopOwnerId: $shopOwnerId,
                employee: $employee,
                request: $request,
                type: $type,
                stage: 'manager',
                approved: $approved,
                note: $note,
            );

            return [
                'request' => $request->fresh(['employee', 'requester', 'manager']),
                'approved' => $approved,
            ];
        });
    }

    /**
     * @return array{request: EmployeeLifecycleRequest, approved: bool, employee: Employee}
     */
    public function ownerReview(
        ShopOwner $owner,
        int $requestId,
        EmployeeLifecycleRequestType $type,
        string $action,
        string $note,
    ): array {
        return DB::transaction(function () use ($owner, $requestId, $type, $action, $note): array {
            $request = EmployeeLifecycleRequest::query()
                ->whereKey($requestId)
                ->where('request_type', $type->value)
                ->lockForUpdate()
                ->firstOrFail();
            $employee = Employee::query()
                ->whereKey($request->employee_id)
                ->where('shop_owner_id', $owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== EmployeeLifecycleRequestStatus::PENDING_OWNER) {
                throw new \RuntimeException('This request has already reached its final review state.', 409);
            }

            if ($request->manager_status !== 'approved') {
                throw new \RuntimeException('This request is not ready for Shop Owner review.', 409);
            }

            $this->assertExpectedEmployeeState($employee, $type);

            $approved = $action === 'approve';
            $newStatus = $approved
                ? EmployeeLifecycleRequestStatus::APPROVED
                : EmployeeLifecycleRequestStatus::REJECTED_OWNER;

            $request->forceFill([
                'status' => $newStatus,
                'owner_id' => $owner->getKey(),
                'owner_status' => $approved ? 'approved' : 'rejected',
                'owner_note' => $note !== '' ? $note : null,
                'owner_reviewed_at' => now(),
            ])->save();

            if ($approved) {
                if ($type === EmployeeLifecycleRequestType::TERMINATION) {
                    $this->finalizeTermination($employee, $request);
                } else {
                    $this->finalizeRehire($owner, $employee, $request);
                }
            }

            $this->auditDecision(
                actorId: null,
                shopOwnerId: (int) $owner->getKey(),
                employee: $employee,
                request: $request,
                type: $type,
                stage: 'owner',
                approved: $approved,
                note: $note,
            );

            return [
                'request' => $request->fresh(['employee.user', 'requester', 'manager', 'owner']),
                'approved' => $approved,
                'employee' => $employee->fresh(['user', 'employmentPeriods']),
            ];
        });
    }

    private function assertExpectedEmployeeState(Employee $employee, EmployeeLifecycleRequestType $type): void
    {
        $status = $employee->status;

        if ($type === EmployeeLifecycleRequestType::TERMINATION && $status === EmployeeStatus::TERMINATED) {
            throw new \RuntimeException('This employee is already terminated.', 409);
        }

        if ($type === EmployeeLifecycleRequestType::REHIRE && $status !== EmployeeStatus::TERMINATED) {
            throw new \RuntimeException('Only a terminated employee can be rehired.', 409);
        }
    }

    private function assertNoPendingRequest(Employee $employee): void
    {
        $exists = EmployeeLifecycleRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [
                EmployeeLifecycleRequestStatus::PENDING_MANAGER->value,
                EmployeeLifecycleRequestStatus::PENDING_OWNER->value,
            ])
            ->exists();

        if ($exists) {
            throw new \RuntimeException('This employee already has a pending employment lifecycle request.', 409);
        }
    }

    private function finalizeTermination(Employee $employee, EmployeeLifecycleRequest $request): void
    {
        $terminatedAt = now();
        $period = $employee->employmentPeriods()
            ->whereNull('end_date')
            ->latest('start_date')
            ->lockForUpdate()
            ->first();

        if (! $period) {
            $period = EmployeeEmploymentPeriod::query()->create($this->periodSnapshot(
                employee: $employee,
                startDate: $employee->hire_date?->toDateString() ?? $employee->created_at?->toDateString() ?? $terminatedAt->toDateString(),
                endDate: $terminatedAt->toDateString(),
                endReason: $request->reason,
            ));
        } else {
            $period->forceFill([
                'end_date' => $terminatedAt->toDateString(),
                'end_reason' => $request->reason,
            ])->save();
        }

        $employee->forceFill([
            'status' => EmployeeStatus::TERMINATED,
            'terminated_at' => $terminatedAt,
            'suspension_reason' => null,
            'privileged_suspension_id' => null,
        ])->save();

        $this->linkedUserSynchronizer->sync($employee);
    }

    private function finalizeRehire(ShopOwner $owner, Employee $employee, EmployeeLifecycleRequest $request): void
    {
        if (! $request->rehire_start_date || trim((string) $request->rehire_role) === '') {
            throw new \RuntimeException('The rehire request is missing its approved employment terms.', 422);
        }

        $startDate = Carbon::parse($request->rehire_start_date)->startOfDay();
        $terminatedDate = $employee->terminated_at?->copy()->startOfDay();

        if ($terminatedDate !== null && $startDate->lessThanOrEqualTo($terminatedDate)) {
            throw new \RuntimeException('The rehire date must be after the termination date.', 409);
        }

        $role = $this->resolveRehireRole($owner, (string) $request->rehire_role);
        if ($role === null) {
            throw new \RuntimeException('The selected rehire role is not available for this company.', 422);
        }

        $previousPeriod = $employee->employmentPeriods()
            ->whereNull('end_date')
            ->latest('start_date')
            ->lockForUpdate()
            ->first();

        if ($previousPeriod) {
            $previousPeriod->forceFill([
                'end_date' => $startDate->copy()->subDay()->toDateString(),
                'end_reason' => 'Previous termination period closed during rehire.',
            ])->save();
        } elseif (! $employee->employmentPeriods()->exists()) {
            EmployeeEmploymentPeriod::query()->create($this->periodSnapshot(
                employee: $employee,
                startDate: $employee->hire_date?->toDateString() ?? $employee->created_at?->toDateString() ?? $startDate->toDateString(),
                endDate: $startDate->copy()->subDay()->toDateString(),
                endReason: 'Previous termination period recorded during rehire.',
            ));
        }

        $employee->forceFill([
            'status' => EmployeeStatus::ACTIVE,
            'terminated_at' => null,
            'hire_date' => $startDate->toDateString(),
            'position' => $request->rehire_position ?: $employee->position,
            'department' => $request->rehire_department ?: $employee->department,
            'functional_role' => $request->rehire_functional_role ?: $employee->functional_role,
            'salary' => $request->rehire_salary !== null ? $request->rehire_salary : $employee->salary,
            'suspension_reason' => null,
            'privileged_suspension_id' => null,
        ]);
        $employee->save();

        EmployeeEmploymentPeriod::query()->create([
            ...$this->periodSnapshot(
                employee: $employee,
                startDate: $startDate->toDateString(),
                endDate: null,
                endReason: null,
            ),
            'role' => $role,
        ]);

        $linkedUser = User::query()
            ->where('shop_owner_id', $owner->getKey())
            ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $employee->email))])
            ->lockForUpdate()
            ->first();

        if ($linkedUser) {
            $linkedUser->syncRoles([$role]);
            $linkedUser->syncPermissions([]);
            $linkedUser->forceFill([
                'role' => $this->legacyRoleFor($role),
                'status' => 'active',
                'position' => $employee->position,
            ])->save();
        }

        $this->linkedUserSynchronizer->sync($employee);
    }

    /** @return array<string, mixed> */
    private function periodSnapshot(
        Employee $employee,
        string $startDate,
        ?string $endDate,
        ?string $endReason,
    ): array {
        return [
            'employee_id' => $employee->getKey(),
            'shop_owner_id' => $employee->shop_owner_id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'end_reason' => $endReason,
            'position' => $employee->position,
            'department' => $employee->department,
            'functional_role' => $employee->functional_role,
            'salary' => $employee->salary,
            'role' => $this->currentUserRole($employee),
        ];
    }

    private function currentUserRole(Employee $employee): ?string
    {
        $email = strtolower(trim((string) $employee->email));
        if ($email === '') {
            return null;
        }

        $user = User::query()
            ->where('shop_owner_id', $employee->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        return $user?->getRoleNames()->first()
            ?? $user?->role
            ?? null;
    }

    private function legacyRoleFor(string $role): string
    {
        return match (strtoupper(trim($role))) {
            'CASHIER' => 'STAFF',
            'INVENTORY', 'INVENTORY MANAGER' => 'INVENTORY',
            'PROCUREMENT', 'PROCUREMENT MANAGER', 'LOGISTICS DISPATCHER', 'LOGISTICS RIDER' => 'STAFF',
            default => strtoupper(trim($role)),
        };
    }

    private function auditDecision(
        ?int $actorId,
        int $shopOwnerId,
        Employee $employee,
        EmployeeLifecycleRequest $request,
        EmployeeLifecycleRequestType $type,
        string $stage,
        bool $approved,
        string $note,
    ): void {
        AuditLog::createLog([
            'shop_owner_id' => $shopOwnerId,
            'user_id' => $actorId,
            'employee_id' => $employee->getKey(),
            'module' => AuditLog::MODULE_EMPLOYEE,
            'action' => $approved ? AuditLog::ACTION_APPROVED : AuditLog::ACTION_REJECTED,
            'entity_type' => EmployeeLifecycleRequest::class,
            'entity_id' => $request->getKey(),
            'description' => $approved
                ? ucfirst($type->value).' request approved at the '.$stage.' stage.'
                : ucfirst($type->value).' request rejected at the '.$stage.' stage: '.$note,
            'old_values' => [],
            'new_values' => [
                'request_type' => $type->value,
                'status' => $request->status->value,
                'stage' => $stage,
                'decision' => $approved ? 'approved' : 'rejected',
            ],
            'severity' => $approved ? AuditLog::SEVERITY_CRITICAL : AuditLog::SEVERITY_WARNING,
            'tags' => ['employee-lifecycle', $type->value, 'workflow', $stage],
        ]);
    }

    private function employeeName(Employee $employee): string
    {
        return trim((string) ($employee->name ?: implode(' ', array_filter([
            $employee->first_name,
            $employee->last_name,
        ])))) ?: 'Employee';
    }
}
