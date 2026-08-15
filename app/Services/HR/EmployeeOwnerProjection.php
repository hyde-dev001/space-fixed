<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class EmployeeOwnerProjection
{
    private readonly EmployeeOperationalPolicy $policy;

    public function __construct(?EmployeeOperationalPolicy $policy = null)
    {
        $this->policy = $policy ?? new EmployeeOperationalPolicy();
    }

    /**
     * Project employee state for owner-facing presentation only.
     *
     * This method does not change the Employee or authorize a mutation.
     *
     * @return array{account_state: string|null, on_leave: bool, probation: bool}
     */
    public function project(Employee $employee, ?CarbonInterface $date = null): array
    {
        $accountState = $this->accountState($employee);
        $effectiveDate = $date ?? CarbonImmutable::now();

        return [
            'account_state' => $accountState,
            'on_leave' => $this->policy->isOnLeave($employee, $effectiveDate),
            'probation' => $this->isOnProbation($employee, $accountState, $effectiveDate),
        ];
    }

    private function accountState(Employee $employee): ?string
    {
        $rawStatus = $employee->getAttributes()['status'] ?? $employee->getRawOriginal('status');

        if ($rawStatus instanceof EmployeeStatus) {
            return $rawStatus->value;
        }

        if ($rawStatus instanceof \BackedEnum) {
            $rawStatus = $rawStatus->value;
        }

        if (! is_string($rawStatus)) {
            return null;
        }

        return EmployeeStatus::tryFrom(strtolower(trim($rawStatus)))?->value;
    }

    private function isOnProbation(Employee $employee, ?string $accountState, CarbonInterface $date): bool
    {
        if ($accountState !== EmployeeStatus::ACTIVE->value) {
            return false;
        }

        $attributes = $employee->getAttributes();
        $explicitProbation = $attributes['probation'] ?? null;

        if ($explicitProbation !== null) {
            return filter_var($explicitProbation, FILTER_VALIDATE_BOOLEAN);
        }

        $probationEndDate = $employee->getAttribute('probation_end_date');
        $hireDate = $employee->getAttribute('hire_date');

        if ($probationEndDate === null && $hireDate !== null) {
            $probationMonths = $employee->getAttribute('probation_period_months');

            if ($probationMonths !== null && (int) $probationMonths > 0) {
                $probationEndDate = CarbonImmutable::parse($hireDate)->addMonths((int) $probationMonths);
            }
        }

        if ($probationEndDate === null) {
            return false;
        }

        $targetDate = CarbonImmutable::parse($date->toDateString());
        $probationEnd = CarbonImmutable::parse($probationEndDate)->startOfDay();

        if ($hireDate !== null && $targetDate->lessThan(CarbonImmutable::parse($hireDate)->startOfDay())) {
            return false;
        }

        return $targetDate->lessThanOrEqualTo($probationEnd);
    }
}
