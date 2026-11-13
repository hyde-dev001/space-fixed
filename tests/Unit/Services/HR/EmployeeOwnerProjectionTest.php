<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HR;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Services\HR\EmployeeOperationalPolicy;
use App\Services\HR\EmployeeOwnerProjection;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EmployeeOwnerProjectionTest extends TestCase
{
    #[Test]
    public function projection_exposes_derived_leave_and_probation_without_rewriting_account_state(): void
    {
        $employee = new Employee([
            'status' => EmployeeStatus::ACTIVE,
            'hire_date' => '2026-01-01',
        ]);
        $employee->forceFill(['probation_end_date' => '2026-08-31']);
        $employee->setRelation('leaveRequests', collect([
            new LeaveRequest([
                'status' => 'approved',
                'start_date' => '2026-08-15',
                'end_date' => '2026-08-17',
            ]),
        ]));

        $leaveRequest = $employee->getRelation('leaveRequests')->first();
        $this->assertInstanceOf(LeaveRequest::class, $leaveRequest);
        $this->assertSame('approved', $leaveRequest->getAttribute('status'));
        $this->assertSame('2026-08-15', $leaveRequest->getAttribute('start_date')->toDateString());
        $this->assertSame('2026-08-17', $leaveRequest->getAttribute('end_date')->toDateString());

        $projection = (new EmployeeOwnerProjection(new EmployeeOperationalPolicy()))->project(
            $employee,
            CarbonImmutable::parse('2026-08-16'),
        );

        $this->assertSame('active', $projection['account_state']);
        $this->assertTrue($projection['on_leave']);
        $this->assertTrue($projection['probation']);
        $this->assertSame(EmployeeStatus::ACTIVE, $employee->status);
    }

    #[Test]
    public function probation_does_not_block_authentication_or_change_account_state(): void
    {
        $employee = new Employee([
            'status' => EmployeeStatus::ACTIVE,
            'hire_date' => '2026-01-01',
        ]);
        $employee->forceFill(['probation_end_date' => '2026-08-31']);

        $projection = (new EmployeeOwnerProjection(new EmployeeOperationalPolicy()))->project(
            $employee,
            CarbonImmutable::parse('2026-08-15'),
        );

        $this->assertTrue($projection['probation']);
        $this->assertSame('active', $projection['account_state']);
    }

    #[Test]
    public function legacy_on_leave_projects_as_active_with_a_leave_overlay(): void
    {
        $employee = new Employee(['status' => 'on_leave']);
        $employee->setRelation('leaveRequests', collect([
            new LeaveRequest([
                'status' => 'approved',
                'start_date' => '2026-08-15',
                'end_date' => '2026-08-17',
            ]),
        ]));

        $projection = (new EmployeeOwnerProjection(new EmployeeOperationalPolicy()))->project(
            $employee,
            CarbonImmutable::parse('2026-08-16'),
        );

        $this->assertSame('active', $projection['account_state']);
        $this->assertTrue($projection['on_leave']);
    }
}
