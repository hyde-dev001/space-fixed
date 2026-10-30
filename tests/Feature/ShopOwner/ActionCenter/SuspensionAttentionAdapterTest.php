<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\SuspensionAttentionAdapter;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Services\OwnerActionCenter\OwnerAttentionAdapterRegistry;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SuspensionAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owner_sees_only_manager_approved_pending_requests_for_their_shop(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $otherOwner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id]);

        $actionable = $this->createRequest($employee, $requester, [
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
        ]);
        $this->createRequest($employee, $requester, [
            'status' => SuspensionStatus::PENDING_MANAGER,
            'manager_status' => 'pending',
        ]);
        $this->createRequest($employee, $requester, [
            'status' => SuspensionStatus::APPROVED,
            'manager_status' => 'approved',
        ]);

        $otherEmployee = Employee::factory()->active()->create(['shop_owner_id' => $otherOwner->id]);
        $otherRequester = User::factory()->create(['shop_owner_id' => $otherOwner->id]);
        $this->createRequest($otherEmployee, $otherRequester, [
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('suspension_request', $item->sourceType);
        $this->assertSame('suspensions', $item->coverageSource);
        $this->assertSame('hr', $item->module);
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=suspension_request:'.$actionable->id,
            $item->destinationUrl,
        );
    }

    public function test_individual_owner_does_not_receive_employee_suspension_approvals(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id]);
        $this->createRequest($employee, $requester, [
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(0, $result->qualifyingCount);
        $this->assertCount(0, $result->items);
    }

    public function test_registry_exposes_suspension_approval_source_when_enabled(): void
    {
        config(['owner_action_center.coverage.suspensions' => true]);

        $adapters = app(OwnerAttentionAdapterRegistry::class)
            ->adaptersFor('needs_my_decision', 'suspensions');

        $this->assertCount(1, $adapters);
        $this->assertSame('suspension_requests', $adapters[0]->adapterKey());
    }

    public function test_action_center_service_returns_suspension_requests_as_owner_approvals(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id]);
        $request = $this->createRequest($employee, $requester, [
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
        ]);

        config([
            'owner_action_center.coverage.refunds' => false,
            'owner_action_center.coverage.prices' => false,
            'owner_action_center.coverage.payslips' => false,
            'owner_action_center.coverage.salary_changes' => false,
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.repair_rejections' => false,
            'owner_action_center.coverage.suspensions' => true,
        ]);

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(coverage: 'suspensions'),
        );

        $this->assertSame(1, $result->total);
        $this->assertSame($request->id, $result->items[0]->sourceId);
        $this->assertSame([
            'refunds' => 0,
            'prices' => 0,
            'payslips' => 0,
            'salary_changes' => 0,
            'purchase_requests' => 0,
            'suspensions' => 1,
            'expenses' => 0,
            'repair_rejections' => 0,
        ], $result->coverageCounts);
    }

    private function adapter(): SuspensionAttentionAdapter
    {
        return app(SuspensionAttentionAdapter::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createRequest(Employee $employee, User $requester, array $overrides): SuspensionRequest
    {
        return SuspensionRequest::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'requested_by' => $requester->id,
        ], $overrides));
    }
}
