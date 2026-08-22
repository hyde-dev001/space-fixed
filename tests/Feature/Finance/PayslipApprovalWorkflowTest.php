<?php

namespace Tests\Feature\Finance;

use App\Models\Approval;
use App\Models\HR\Payroll;
use App\Models\ProcurementSettings;
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
        Permission::findOrCreate('disburse-payroll', 'user');

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
        $this->financeFirst->givePermissionTo('disburse-payroll');

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

        $this->assertApprovalStage($payslip, 1, 4, 'finance');
        $this->setPayslipApproval(false);

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
        $this->assertApprovalStage($payslip, 2, 4, 'shop_owner');
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'title' => 'Payslip Awaiting Shop Owner Approval',
            'action_url' => "/shop-owner/action-center?bucket=needs_my_decision&approval=payslip:{$payslip->id}",
        ]);

        // Wrong actor at level 2: finance checker cannot approve owner stage
        $wrongRole = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Attempt owner stage as finance',
            ]);

        $wrongRole->assertStatus(422);
        $this->assertApprovalStage($payslip, 2, 4, 'shop_owner');

        // Level 2: linked Shop Owner ERP user via final-approve endpoint
        $l2 = $this->actingAs($this->shopOwnerMappedUser, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Owner approved',
            ]);

        $l2->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 3,
            ]);
        $this->assertApprovalStage($payslip, 3, 4, 'finance');

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
        $this->assertApprovalStage($payslip, 4, 4, 'finance_final');

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

        $stale = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Replay final approval',
            ]);

        $stale->assertStatus(422);
        $this->assertApprovalStage($payslip, 4, 4, 'finance_final');
    }

    public function test_payslip_policy_off_removes_only_the_shop_owner_stage(): void
    {
        $this->setPayslipApproval(false);
        $payslip = $this->createWorkflowBoundPayslip();

        $this->assertApprovalStage($payslip, 1, 3, 'finance');
        $this->assertSame([
            '1' => 'finance',
            '2' => 'finance',
            '3' => 'finance_final',
        ], Approval::findOrFail($payslip->approval_id)->approval_roles);
        $this->setPayslipApproval(true);

        $l1 = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'L1 finance checked',
            ]);

        $l1->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 2,
            ]);
        $this->assertApprovalStage($payslip, 2, 3, 'finance');

        $owner = $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Owner must not approve when disabled',
            ]);

        $owner->assertStatus(422);
        $this->assertApprovalStage($payslip, 2, 3, 'finance');

        $l2 = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Second Finance decision',
            ]);

        $l2->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 3,
            ]);
        $this->assertApprovalStage($payslip, 3, 3, 'finance_final');

        $l3 = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Final Finance approval',
            ]);

        $l3->assertStatus(200)
            ->assertJson([
                'is_final' => true,
                'approval_level' => 3,
            ]);

        $payslip->refresh();
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

    public function test_generic_finance_hr_and_cross_shop_owner_cannot_approve_owner_stage(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'L1 finance checked',
            ])
            ->assertStatus(200);

        $this->actingAs($this->requester, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'HR cannot approve',
            ])
            ->assertStatus(403);

        $otherShopOwner = ShopOwner::factory()->approved()->create();

        $this->actingAs($otherShopOwner, 'shop_owner')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Cross-shop owner cannot approve',
            ])
            ->assertStatus(404);

        $this->assertApprovalStage($payslip, 2, 4, 'shop_owner');
    }

    public function test_legacy_payslip_keeps_the_legacy_two_step_path(): void
    {
        $payslip = $this->createLegacyPayslip();

        $checker = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Legacy checker approval',
            ]);

        $checker->assertStatus(200);
        $payslip->refresh();
        $this->assertSame('approved', $payslip->approval_status);
        $this->assertSame('pending', $payslip->status);
        $this->assertSame($this->financeFirst->id, $payslip->approved_by);

        $final = $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Legacy owner approval',
            ]);

        $final->assertStatus(200);
        $payslip->refresh();
        $this->assertSame('approved', $payslip->status);
        $this->assertSame('approved', $payslip->approval_status);
        $this->assertSame($this->shopOwnerMappedUser->id, $payslip->final_approved_by);
    }

    public function test_batch_approval_preserves_mixed_v4_and_legacy_workflows(): void
    {
        $v4Payslip = $this->createWorkflowBoundPayslip();
        $v4OwnerStagePayslip = $this->createWorkflowBoundPayslip();
        $legacyPayslip = $this->createLegacyPayslip();

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$v4OwnerStagePayslip->id}/approve", [
                'notes' => 'Move second v4 payslip to owner stage',
            ])
            ->assertStatus(200);

        $response = $this->actingAs($this->financeFirst, 'user')
            ->postJson('/api/finance/payslip-approvals/batch/approve', [
                'payslip_ids' => [$v4Payslip->id, $v4OwnerStagePayslip->id, $legacyPayslip->id],
                'notes' => 'Mixed batch approval',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'approved' => 2,
                'failed' => 1,
            ]);

        $this->assertApprovalStage($v4Payslip, 2, 4, 'shop_owner');
        $this->assertApprovalStage($v4OwnerStagePayslip, 2, 4, 'shop_owner');

        $legacyPayslip->refresh();
        $this->assertSame('approved', $legacyPayslip->approval_status);
        $this->assertSame('pending', $legacyPayslip->status);
    }

    public function test_disbursement_is_denied_before_final_approval(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();

        $response = $this->actingAs($this->financeFirst, 'user')
            ->postJson('/api/finance/payslip-approvals/disburse', [
                'payrollIds' => [$payslip->id],
            ]);

        $response->assertStatus(422);
        $payslip->refresh();
        $this->assertSame('pending', $payslip->status);
        $this->assertSame('pending', $payslip->approval_status);
    }

    private function createWorkflowBoundPayslip(): Payroll
    {
        $payslip = $this->createPayrollRecord();

        $this->payslipApprovalService->createPayslipApproval(
            $payslip,
            $this->shopOwnerMappedUser,
            $this->requester
        );

        return $payslip->fresh();
    }

    private function createLegacyPayslip(): Payroll
    {
        return $this->createPayrollRecord();
    }

    private function createPayrollRecord(): Payroll
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

        return $payslip;
    }

    private function setPayslipApproval(bool $enabled): void
    {
        $settings = ProcurementSettings::getForShopOwner($this->shopOwnerAuth->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['payslip_approval']['enabled'] = $enabled;
        $settings->update(['settings_json' => $settingsJson]);
    }

    private function assertApprovalStage(Payroll $payslip, int $level, int $totalLevels, string $role): void
    {
        $payslip->refresh();
        $approval = Approval::findOrFail($payslip->approval_id);

        $this->assertSame($level, (int) $approval->current_level);
        $this->assertSame($totalLevels, (int) $approval->total_levels);
        $this->assertSame($role, $approval->current_approver_role);
        $this->assertSame($level, (int) $payslip->current_approval_level);
    }
}
