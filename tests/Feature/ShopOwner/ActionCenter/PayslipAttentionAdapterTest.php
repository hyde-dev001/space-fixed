<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Employee;
use App\Models\HR\Payroll;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\PayslipAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PayslipAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_pending_v4_payslips_use_role_map_snapshot_and_tenant_scope(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $otherOwner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);

        $actionable = $this->createPayslip($owner, $requester);
        $this->createPayslip($otherOwner, User::factory()->create(['shop_owner_id' => $otherOwner->id]));
        $this->createPayslip($owner, $requester, 'finance');
        $this->createPayslip($owner, $requester, 'shop_owner', ApprovalStatus::APPROVED);
        $this->createPayslip($owner, $requester, 'shop_owner', ApprovalStatus::PENDING, 'v3_legacy');

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('payslip:'.$actionable->id.':payslip_approval', $item->attentionKey);
        $this->assertSame(20000.0, $item->comparableMonetaryExposure);
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=payslip:'.$actionable->id,
            $item->destinationUrl,
        );
    }

    public function test_payslip_projection_has_bounded_query_count(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->createPayslip($owner, $requester);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createPayslip($owner, $requester);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): PayslipAttentionAdapter
    {
        return app(PayslipAttentionAdapter::class);
    }

    private function createPayslip(
        ShopOwner $owner,
        User $requester,
        string $role = 'shop_owner',
        ApprovalStatus $approvalStatus = ApprovalStatus::PENDING,
        string $workflowVersion = 'v4_multi_level',
    ): Payroll {
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $owner->id,
            'first_name' => 'Payroll',
            'last_name' => 'Employee',
        ]);
        $payroll = Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $owner->id,
            'payroll_period' => '2026-'.str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT).'-'.uniqid(),
            'pay_period_start' => now()->startOfMonth()->toDateString(),
            'pay_period_end' => now()->endOfMonth()->toDateString(),
            'base_salary' => 20000,
            'basic_salary' => 20000,
            'gross_salary' => 20000,
            'allowances' => 0,
            'deductions' => 0,
            'total_deductions' => 0,
            'tax_amount' => 0,
            'overtime_pay' => 0,
            'bonus' => 0,
            'net_salary' => 20000,
            'status' => 'pending',
            'approval_status' => 'pending',
            'payment_method' => 'bank_transfer',
            'generated_by' => $requester->id,
            'generated_at' => now(),
        ]);

        $approval = Approval::create([
            'shop_owner_id' => $owner->id,
            'approvable_type' => Payroll::class,
            'approvable_id' => $payroll->id,
            'reference' => 'PAYROLL-'.uniqid(),
            'description' => 'Payslip projection',
            'amount' => 20000,
            'requested_by' => $requester->id,
            'current_level' => $role === 'shop_owner' ? 2 : 1,
            'total_levels' => 4,
            'status' => $approvalStatus,
            'approval_roles' => ['1' => 'finance', '2' => 'shop_owner', '3' => 'finance', '4' => 'finance_final'],
            'current_approver_role' => $role,
        ]);
        $payroll->update([
            'approval_id' => $approval->id,
            'current_approval_level' => $approval->current_level,
            'approval_workflow_version' => $workflowVersion,
        ]);

        return $payroll->fresh();
    }
}
