<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HR;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Services\HR\EmployeeOperationalPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EmployeeOperationalPolicyTest extends TestCase
{
    private EmployeeOperationalPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new EmployeeOperationalPolicy();
    }

    /** @return iterable<string, array{EmployeeStatus, bool, bool, bool}> */
    public static function canonicalStates(): iterable
    {
        yield 'active' => [EmployeeStatus::ACTIVE, true, true, true];
        yield 'inactive' => [EmployeeStatus::INACTIVE, false, false, false];
        yield 'suspended' => [EmployeeStatus::SUSPENDED, false, false, false];
        yield 'terminated' => [EmployeeStatus::TERMINATED, false, false, false];
    }

    #[Test]
    #[DataProvider('canonicalStates')]
    public function operational_decisions_follow_canonical_account_state(
        EmployeeStatus $status,
        bool $canAuthenticate,
        bool $canReceiveNewAssignment,
        bool $eligibleForRoutinePayroll,
    ): void {
        $employee = new Employee(['status' => $status]);

        $this->assertSame($canAuthenticate, $this->policy->canAuthenticate($employee));
        $this->assertSame($canReceiveNewAssignment, $this->policy->canReceiveNewAssignment($employee));
        $this->assertSame($eligibleForRoutinePayroll, $this->policy->isEligibleForRoutinePayroll($employee));
    }

    #[Test]
    public function terminated_employees_cannot_be_moved_back_to_an_operational_state(): void
    {
        $employee = new Employee(['status' => EmployeeStatus::TERMINATED]);

        $this->assertFalse($this->policy->canChangeAccountState($employee, EmployeeStatus::ACTIVE));
        $this->assertFalse($this->policy->canChangeAccountState($employee, EmployeeStatus::INACTIVE));
        $this->assertFalse($this->policy->canChangeAccountState($employee, EmployeeStatus::SUSPENDED));
        $this->assertTrue($this->policy->canChangeAccountState($employee, EmployeeStatus::TERMINATED));
    }

    #[Test]
    public function approved_leave_is_derived_for_the_requested_date_without_changing_account_state(): void
    {
        $employee = new Employee(['status' => EmployeeStatus::ACTIVE]);
        $employee->setRelation('leaveRequests', collect([
            new LeaveRequest([
                'status' => 'approved',
                'start_date' => '2026-08-15',
                'end_date' => '2026-08-17',
            ]),
            new LeaveRequest([
                'status' => 'pending',
                'start_date' => '2026-08-18',
                'end_date' => '2026-08-20',
            ]),
        ]));

        $this->assertTrue($this->policy->isOnLeave($employee, CarbonImmutable::parse('2026-08-16')));
        $this->assertFalse($this->policy->isOnLeave($employee, CarbonImmutable::parse('2026-08-18')));
        $this->assertSame(EmployeeStatus::ACTIVE, $employee->status);
    }
}
