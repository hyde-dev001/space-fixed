<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Employee;
use App\Models\HR\SalaryChange;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\SalaryChangeAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SalaryChangeAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_pending_salary_changes_use_snapshot_and_tenant_scope(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $otherOwner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $proposer = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id]);

        $actionable = $this->createChange($owner, $employee, $proposer, true);
        $this->createChange($owner, $employee, $proposer, false);
        $otherEmployee = Employee::factory()->active()->create(['shop_owner_id' => $otherOwner->id]);
        $this->createChange($otherOwner, $otherEmployee, User::factory()->create(['shop_owner_id' => $otherOwner->id]), true);
        $this->createChange($owner, $employee, $proposer, true, SalaryChange::STATUS_APPROVED);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('salary_change:'.$actionable->id.':salary_change_approval', $item->attentionKey);
        $this->assertSame(200.0, $item->comparableMonetaryExposure);
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=salary_change:'.$actionable->id,
            $item->destinationUrl,
        );
    }

    public function test_owner_self_proposed_salary_changes_are_not_actionable_for_that_owner(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $ownerUser = User::factory()->create([
            'shop_owner_id' => $owner->id,
            'email' => $owner->email,
            'role' => 'Shop Owner',
        ]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id]);

        $this->createChange($owner, $employee, $ownerUser, true);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(0, $result->qualifyingCount);
        $this->assertCount(0, $result->items);
    }

    public function test_salary_change_projection_has_bounded_query_count(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $proposer = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id]);
        $this->createChange($owner, $employee, $proposer, true);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createChange($owner, $employee, $proposer, true);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): SalaryChangeAttentionAdapter
    {
        return app(SalaryChangeAttentionAdapter::class);
    }

    private function createChange(
        ShopOwner $owner,
        Employee $employee,
        User $proposer,
        bool $requiresOwnerApproval,
        string $status = SalaryChange::STATUS_PENDING,
    ): SalaryChange {
        return SalaryChange::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $owner->id,
            'proposed_by' => $proposer->id,
            'previous_salary' => 1000,
            'new_salary' => 1200,
            'change_percent' => 20,
            'change_type' => SalaryChange::TYPE_MAJOR,
            'effective_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Action Center salary projection',
            'status' => $status,
            'requires_owner_approval' => $requiresOwnerApproval,
        ]);
    }
}
