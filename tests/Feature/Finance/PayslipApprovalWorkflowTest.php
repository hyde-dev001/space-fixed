<?php

namespace Tests\Feature\Finance;

use App\Models\HR\Payroll;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\Employee;
use App\Services\PayslipApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayslipApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwnerAuth;
    private User $shopOwnerMappedUser;
    private User $requester;
    private User $financeFirst;
    private User $financeSecond;
    private User $financeFinal;
    private PayslipApprovalService $payslipApprovalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('access-payslip-approval', 'user');
        Permission::findOrCreate('approve-expenses', 'user');

        Role::findOrCreate('finance', 'user');
        Role::findOrCreate('shop-owner', 'user');
        Role::findOrCreate('Finance Manager', 'user');

        $this->shopOwnerAuth = ShopOwner::factory()->approved()->create();

        // Map shop_owner guard account to a user account for v4 level-2 approval
        $this->shopOwnerMappedUser = User::factory()->create([
            'id' => $this->shopOwnerAuth->id,
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'role' => 'Shop Owner',
        ]);
        $this->shopOwnerMappedUser->assignRole('shop-owner');

        $this->requester = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);

        $this->financeFirst = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeFirst->assignRole('finance');
        $this->financeFirst->givePermissionTo('access-payslip-approval');

        $this->financeSecond = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeSecond->assignRole('finance');
        $this->financeSecond->givePermissionTo('access-payslip-approval');

        $this->financeFinal = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeFinal->assignRole('Finance Manager');
        $this->financeFinal->givePermissionTo('access-payslip-approval');
        $this->financeFinal->givePermissionTo('approve-expenses');

        $this->payslipApprovalService = app(PayslipApprovalService::class);
    }

    public function test_payslip_v4_workflow_progresses_across_all_levels(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();

        // Level 1: Finance checker
        $l1 = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'L1 finance checked',
            ]);

        $l1->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 2,
            ]);

        // Wrong actor at level 2: finance checker cannot approve owner stage
        $wrongRole = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Attempt owner stage as finance',
            ]);

        $wrongRole->assertStatus(422);

        // Level 2: Shop owner via final-approve endpoint (v4 transition)
        $l2 = $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Owner approved',
            ]);

        $l2->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 3,
            ]);

        // Level 3: Finance checker
        $l3 = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'L3 finance checked',
            ]);

        $l3->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 4,
            ]);

        // Level 4: Finance manager final
        $l4 = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'L4 final finance approval',
            ]);

        $l4->assertStatus(200)
            ->assertJson([
                'is_final' => true,
                'approval_level' => 4,
            ]);

        $payslip->refresh();
        $this->assertSame(4, $payslip->current_approval_level);
        $this->assertSame('approved', $payslip->status);
        $this->assertSame('approved', $payslip->approval_status);
    }

    public function test_finance_can_reject_payslip_at_level_one(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();

        $reject = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/reject", [
                'notes' => 'Numbers mismatch from source docs',
            ]);

        $reject->assertStatus(200)
            ->assertJson([
                'rejection_level' => 1,
            ]);

        $payslip->refresh();
        $this->assertSame(1, $payslip->current_approval_level);
        $this->assertSame('pending', $payslip->status);
        $this->assertSame('rejected', $payslip->approval_status);
    }

    private function createWorkflowBoundPayslip(): Payroll
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'status' => 'active',
        ]);

        $payslip = Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'payroll_period' => now()->format('Y-m'),
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
            'tax_deductions' => 0,
            'sss_contributions' => 0,
            'philhealth' => 0,
            'pag_ibig' => 0,
            'attendance_days' => 22,
            'leave_days' => 0,
            'absent_days' => 0,
            'overtime_hours' => 0,
            'generated_by' => $this->requester->id,
            'generated_at' => now(),
        ]);

        $this->payslipApprovalService->createPayslipApproval(
            $payslip,
            $this->shopOwnerMappedUser,
            $this->requester
        );

        return $payslip->fresh();
    }
}
