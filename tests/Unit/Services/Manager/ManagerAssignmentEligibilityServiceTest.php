<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Manager;

use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\RepairerUnavailability;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Manager\ManagerAssignmentEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ManagerAssignmentEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManagerAssignmentEligibilityService $service;
    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Staff', 'user');
        Role::findOrCreate('Repairer', 'user');
        Permission::findOrCreate('access-repair-job-orders', 'user');
        Permission::findOrCreate('access-repair-stocks', 'user');

        $this->service = app(ManagerAssignmentEligibilityService::class);
        $this->shop = ShopOwner::factory()->create(['business_type' => 'both']);
    }

    public function test_account_and_separation_states_return_stable_reason_codes(): void
    {
        foreach ([
            'inactive' => ['employee_status' => 'inactive', 'user_status' => 'active'],
            'suspended' => ['employee_status' => 'suspended', 'user_status' => 'active'],
            'terminated' => ['employee_status' => 'terminated', 'user_status' => 'active'],
            'resigned' => ['employee_status' => 'active', 'user_status' => 'resigned'],
            'offboarded' => ['employee_status' => 'active', 'user_status' => 'offboarded'],
        ] as $expectedReason => $state) {
            [$user] = $this->createAssignee(
                employeeStatus: $state['employee_status'],
                userStatus: $state['user_status'],
            );

            $decision = $this->service->evaluate(
                assignee: $user,
                shopOwnerId: $this->shop->id,
                workType: 'order',
                activeWorkDate: '2026-08-28',
            );

            $this->assertFalse($decision['eligible'], $expectedReason);
            $this->assertSame($expectedReason, $decision['reason_code']);
        }
    }

    public function test_approved_leave_covering_active_work_returns_a_reason(): void
    {
        [$user, $employee] = $this->createAssignee();

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $this->shop->id,
            'leave_type' => 'vacation',
            'start_date' => '2026-08-27',
            'end_date' => '2026-08-29',
            'no_of_days' => 3,
            'reason' => 'Approved leave',
            'status' => 'approved',
        ]);

        $decision = $this->service->evaluate(
            assignee: $user,
            shopOwnerId: $this->shop->id,
            workType: 'order',
            activeWorkDate: '2026-08-28',
        );

        $this->assertFalse($decision['eligible']);
        $this->assertSame('approved_leave', $decision['reason_code']);
    }

    public function test_explicit_repairer_unavailability_only_applies_to_repair_work(): void
    {
        [$repairer] = $this->createAssignee(role: 'Repairer');

        RepairerUnavailability::create([
            'repairer_id' => $repairer->id,
            'shop_owner_id' => $this->shop->id,
            'month_key' => '2026-08',
            'unavailable_dates' => ['2026-08-28'],
        ]);

        $repairDecision = $this->service->evaluate(
            assignee: $repairer,
            shopOwnerId: $this->shop->id,
            workType: 'repair',
            activeWorkDate: '2026-08-28',
        );
        $orderDecision = $this->service->evaluate(
            assignee: $repairer,
            shopOwnerId: $this->shop->id,
            workType: 'order',
            activeWorkDate: '2026-08-28',
        );

        $this->assertSame('explicitly_unavailable', $repairDecision['reason_code']);
        $this->assertNotSame('explicitly_unavailable', $orderDecision['reason_code']);
    }

    public function test_off_shift_is_not_an_automatic_reassignment_reason(): void
    {
        [$user] = $this->createAssignee();

        $decision = $this->service->evaluate(
            assignee: $user,
            shopOwnerId: $this->shop->id,
            workType: 'order',
            activeWorkDate: '2026-08-28',
        );

        $this->assertTrue($decision['eligible']);
        $this->assertNull($decision['reason_code']);
    }

    public function test_repair_role_and_required_skill_are_checked(): void
    {
        [$staff] = $this->createAssignee(role: 'Staff');
        $roleDecision = $this->service->evaluate(
            assignee: $staff,
            shopOwnerId: $this->shop->id,
            workType: 'repair',
            activeWorkDate: '2026-08-28',
        );

        [$repairer] = $this->createAssignee(role: 'Repairer');
        $skillDecision = $this->service->evaluate(
            assignee: $repairer,
            shopOwnerId: $this->shop->id,
            workType: 'repair',
            activeWorkDate: '2026-08-28',
            requiredSkill: 'access-repair-stocks',
        );

        $this->assertSame('role_ineligible', $roleDecision['reason_code']);
        $this->assertSame('missing_required_skill', $skillDecision['reason_code']);
    }

    public function test_cross_shop_assignee_is_not_eligible_for_the_authorized_shop(): void
    {
        $otherShop = ShopOwner::factory()->create(['business_type' => 'both']);
        [$user] = $this->createAssignee(shop: $otherShop);

        $decision = $this->service->evaluate(
            assignee: $user,
            shopOwnerId: $this->shop->id,
            workType: 'order',
            activeWorkDate: '2026-08-28',
        );

        $this->assertFalse($decision['eligible']);
        $this->assertSame('wrong_shop', $decision['reason_code']);
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function createAssignee(
        ?ShopOwner $shop = null,
        string $employeeStatus = 'active',
        string $userStatus = 'active',
        string $role = 'Staff',
    ): array {
        $shop ??= $this->shop;
        $email = 'worker-' . Str::uuid() . '@example.test';

        $employee = Employee::factory()->for($shop)->create([
            'email' => $email,
            'status' => $employeeStatus,
        ]);
        $user = User::factory()->for($shop)->create([
            'email' => $email,
            'role' => $role,
            'status' => $userStatus,
        ]);
        $user->assignRole($role);

        return [$user, $employee];
    }
}
