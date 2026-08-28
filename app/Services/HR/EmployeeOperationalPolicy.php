<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class EmployeeOperationalPolicy
{
    public function canAuthenticate(Employee $employee): bool
    {
        return $this->accountState($employee) === EmployeeStatus::ACTIVE;
    }

    public function canReceiveNewAssignment(Employee $employee): bool
    {
        return $this->accountState($employee) === EmployeeStatus::ACTIVE;
    }

    public function assignmentIneligibilityReason(Employee $employee, CarbonInterface $date): ?string
    {
        $status = $this->accountState($employee);

        $statusReason = match ($status) {
            EmployeeStatus::INACTIVE => 'inactive',
            EmployeeStatus::SUSPENDED => 'suspended',
            EmployeeStatus::TERMINATED => 'terminated',
            default => null,
        };

        if ($statusReason !== null) {
            return $statusReason;
        }

        if ((string) ($employee->getAttributes()['status'] ?? '') === 'on_leave') {
            return 'approved_leave';
        }

        return $this->isOnLeave($employee, $date) ? 'approved_leave' : null;
    }

    public function isEligibleForRoutinePayroll(Employee $employee): bool
    {
        return $this->accountState($employee) === EmployeeStatus::ACTIVE;
    }

    public function canChangeAccountState(Employee $employee, EmployeeStatus|string $targetStatus): bool
    {
        $target = $targetStatus instanceof EmployeeStatus
            ? $targetStatus
            : EmployeeStatus::tryFrom(strtolower(trim($targetStatus)));

        if ($target === null) {
            return false;
        }

        return $this->accountState($employee) !== EmployeeStatus::TERMINATED
            || $target === EmployeeStatus::TERMINATED;
    }

    public function isOnLeave(Employee $employee, CarbonInterface $date): bool
    {
        $targetDate = CarbonImmutable::parse($date->toDateString());

        if ($employee->relationLoaded('leaveRequests')) {
            foreach ($employee->getRelation('leaveRequests') as $leaveRequest) {
                if ($leaveRequest instanceof LeaveRequest && $this->leaveCoversDate($leaveRequest, $targetDate)) {
                    return true;
                }
            }

            return false;
        }

        if (! $employee->exists || $employee->getKey() === null) {
            return false;
        }

        return $employee->leaveRequests()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $targetDate->toDateString())
            ->whereDate('end_date', '>=', $targetDate->toDateString())
            ->exists();
    }

    private function accountState(Employee $employee): ?EmployeeStatus
    {
        $rawStatus = $employee->getAttributes()['status'] ?? $employee->getRawOriginal('status');

        if ($rawStatus instanceof EmployeeStatus) {
            return $rawStatus;
        }

        if ($rawStatus instanceof \BackedEnum) {
            $rawStatus = $rawStatus->value;
        }

        return is_string($rawStatus)
            ? EmployeeStatus::tryFrom(strtolower(trim($rawStatus)))
            : null;
    }

    private function leaveCoversDate(LeaveRequest $leaveRequest, CarbonImmutable $date): bool
    {
        if ((string) $leaveRequest->getAttribute('status') !== 'approved') {
            return false;
        }

        $startDate = $leaveRequest->getAttribute('start_date');
        $endDate = $leaveRequest->getAttribute('end_date');

        if ($startDate === null || $endDate === null) {
            return false;
        }

        return CarbonImmutable::parse($startDate)->startOfDay()->lessThanOrEqualTo($date)
            && CarbonImmutable::parse($endDate)->startOfDay()->greaterThanOrEqualTo($date);
    }
}
