<?php

namespace Tests\Feature\Finance;

use App\Enums\NotificationType;
use App\Models\Approval;
use App\Models\HR\Payroll;
use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\Employee;
use App\Services\PayslipApprovalService;
use App\Services\OwnerActionCenter\Adapters\PayslipAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
        Role::findOrCreate('Shop Owner', 'user');
        Role::findOrCreate('Finance Manager', 'user');

        $this->shopOwnerAuth = ShopOwner::factory()->approved()->create();

        // Map shop_owner guard account to a user account for v4 level-2 approval
        $this->shopOwnerMappedUser = User::factory()->create([
            'id' => $this->shopOwnerAuth->id,
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'role' => 'STAFF',
        ]);
        $this->shopOwnerMappedUser->assignRole('Shop Owner');

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

    public function test_payslip_v4_workflow_completes_once_and_can_be_disbursed(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();

        $this->assertApprovalStage($payslip, 1, 3, 'finance');
        $this->assertDatabaseHas('notifications', [
            'title' => 'Payslip Approval Required',
            'action_url' => "/finance?section=payslip-approvals&payroll={$payslip->id}",
        ]);
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
            ])
            ->assertJsonPath('payslip.workflow_status', 'awaiting_final_approval');
        $this->assertApprovalStage($payslip, 2, 3, 'shop_owner');
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
        $this->assertApprovalStage($payslip, 2, 3, 'shop_owner');

        // Level 2: linked Shop Owner ERP user via final-approve endpoint
        $l2 = $this->actingAs($this->shopOwnerMappedUser, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Owner approved',
            ]);

        $l2->assertStatus(200)
            ->assertJson([
                'is_final' => false,
                'approval_level' => 3,
            ])
            ->assertJsonPath('payslip.workflow_status', 'awaiting_checker');
        $this->assertApprovalStage($payslip, 3, 3, 'finance_final');
        $this->assertDatabaseHas('notifications', [
            'title' => 'Payslip Awaiting Final Finance Approval',
            'action_url' => "/finance?section=payslip-approvals&payroll={$payslip->id}",
            'requires_action' => true,
        ]);

        // Level 3: Finance final approval. The same Finance account may
        // complete the distinct final stage after the Shop Owner decision.
        $l3 = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Final finance approval',
            ]);

        $l3->assertStatus(200)
            ->assertJson([
                'is_final' => true,
                'approval_level' => 3,
            ])
            ->assertJsonPath('payslip.workflow_status', 'ready_for_disbursement');

        $payslip->refresh();
        $this->assertSame(3, $payslip->current_approval_level);
        $this->assertSame('approved', $payslip->status);
        $this->assertSame('approved', $payslip->approval_status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'title' => 'Payslip Fully Approved',
            'action_url' => "/erp/hr?section=payroll-view&payroll={$payslip->id}",
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->financeFirst->id,
            'shop_id' => $this->shopOwnerAuth->id,
            'type' => NotificationType::PAYROLL_GENERATED->value,
            'title' => 'Payslip Ready for Disbursement',
            'action_url' => "/finance?section=payslip-approvals&workflow_status=ready_for_disbursement&payroll={$payslip->id}",
            'group_key' => "payslip-approval-{$payslip->id}-ready-for-disbursement",
            'requires_action' => true,
        ]);

        $disbursement = $this->actingAs($this->financeFirst, 'user')
            ->postJson('/api/finance/payslip-approvals/disburse', [
                'payrollIds' => [$payslip->id],
                'paymentDate' => '2026-09-18',
                'paymentMethod' => 'bank_transfer',
                'payoutReference' => 'PAYROLL-LIFECYCLE-1',
            ]);

        $disbursement->assertOk()->assertJsonPath('processed', 1);
        $this->assertDatabaseHas('payrolls', [
            'id' => $payslip->id,
            'status' => 'paid',
            'disbursed_by' => $this->financeFirst->id,
        ]);

        $stale = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Replay final approval',
            ]);

        $stale->assertStatus(422);
        $this->assertApprovalStage($payslip, 3, 3, 'finance_final');
    }

    public function test_finance_approval_moves_payslip_queue_to_awaiting_shop_owner(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Forward to shop owner',
            ])
            ->assertOk();

        $this->actingAs($this->financeFirst, 'user')
            ->getJson('/api/finance/payslip-approvals?workflow_status=awaiting_final_approval')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $payslip->id)
            ->assertJsonPath('data.0.workflow_status', 'awaiting_final_approval')
            ->assertJsonPath('data.0.approval.current_approver_role', 'shop_owner');

        $this->actingAs($this->financeFirst, 'user')
            ->getJson('/api/finance/payslip-approvals?workflow_status=awaiting_checker')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_inflight_four_level_payslip_is_collapsed_before_final_finance_action(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();
        $approval = Approval::findOrFail($payslip->approval_id);
        $approval->update([
            'approval_roles' => [
                '1' => 'finance',
                '2' => 'shop_owner',
                '3' => 'finance',
                '4' => 'finance_final',
            ],
            'current_level' => 3,
            'total_levels' => 4,
            'current_approver_role' => 'finance',
        ]);
        $payslip->update(['current_approval_level' => 3]);

        $response = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Complete the migrated final Finance stage',
            ]);

        $response->assertOk()->assertJson([
            'is_final' => true,
            'approval_level' => 3,
        ]);

        $this->assertApprovalStage($payslip, 3, 3, 'finance_final');
        $this->assertSame('approved', $payslip->fresh()->status);
    }

    public function test_company_owner_action_center_lists_payslip_after_finance_approval(): void
    {
        $this->shopOwnerAuth->update([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [],
            'owner_action_center.coverage.refunds' => false,
            'owner_action_center.coverage.prices' => false,
            'owner_action_center.coverage.payslips' => true,
            'owner_action_center.coverage.salary_changes' => false,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.suspensions' => false,
            'owner_action_center.coverage.terminations' => false,
            'owner_action_center.coverage.rehires' => false,
        ]);

        $payslip = $this->createWorkflowBoundPayslip();

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Finance approved for owner review',
            ])
            ->assertOk();

        $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalCoverageSources', ['payslips'])
                ->where('ownerActionCenter.health.enabled_adapter_keys', ['payslips'])
                ->where('ownerActionCenter.pagination.total', 1)
                ->where('ownerActionCenter.items.0.source_type', 'payslip')
            ->where('ownerActionCenter.items.0.source_id', $payslip->id));
    }

    public function test_company_owner_can_bulk_approve_all_payslips_waiting_on_the_owner(): void
    {
        $this->shopOwnerAuth->update(['registration_type' => 'company']);
        $first = $this->createWorkflowBoundPayslip();
        $second = $this->createWorkflowBoundPayslip();

        foreach ([$first, $second] as $payslip) {
            $this->actingAs($this->financeFirst, 'user')
                ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                    'notes' => 'Finance approved for owner bulk review',
                ])
                ->assertOk();
        }

        $response = $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson('/api/shop-owner/payslip-approvals/batch/final-approve', [
                'notes' => 'Owner bulk approved',
            ]);

        $response->assertOk()
            ->assertJson([
                'approved' => 2,
                'failed' => 0,
            ]);

        $this->assertApprovalStage($first, 3, 3, 'finance_final');
        $this->assertApprovalStage($second, 3, 3, 'finance_final');
    }

    public function test_payslip_policy_off_removes_only_the_shop_owner_stage(): void
    {
        $this->setPayslipApproval(false);
        $payslip = $this->createWorkflowBoundPayslip();

        $this->assertApprovalStage($payslip, 1, 2, 'finance');
        $this->assertSame([
            '1' => 'finance',
            '2' => 'finance_final',
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
        $this->assertApprovalStage($payslip, 2, 2, 'finance_final');

        $owner = $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Owner must not approve when disabled',
            ]);

        $owner->assertStatus(422);
        $this->assertApprovalStage($payslip, 2, 2, 'finance_final');

        $l2 = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Final Finance decision',
            ]);

        $l2->assertStatus(200)
            ->assertJson([
                'is_final' => true,
                'approval_level' => 2,
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
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'title' => 'Payslip Rejected In Approval Workflow',
            'action_url' => "/erp/hr?section=payroll-view&payroll={$payslip->id}",
        ]);
    }

    public function test_generated_payroll_without_a_mapped_owner_user_creates_the_canonical_owner_stage(): void
    {
        $this->shopOwnerAuth->update(['registration_type' => 'company']);
        $this->shopOwnerMappedUser->delete();
        $payslip = $this->createPayrollRecord();

        $approval = $this->payslipApprovalService->createGeneratedPayrollApproval($payslip, $this->requester);

        $this->assertNotNull($approval);
        $this->assertSame('shop_owner', $approval->approval_roles['2']);

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Finance approved seeded-equivalent payroll',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'title' => 'Payslip Awaiting Shop Owner Approval',
            'group_key' => "payslip-approval-{$payslip->id}-shop_owner-level-2",
        ]);

        $projection = app(PayslipAttentionAdapter::class)->read(
            $this->shopOwnerAuth,
            new OwnerAttentionQuery(coverage: 'payslips'),
        );

        $this->assertSame(1, $projection->qualifyingCount);
        $this->assertSame('payslip:' . $payslip->id . ':payslip_approval', $projection->items[0]->attentionKey);
    }

    public function test_repeated_payslip_stage_notification_is_deduplicated_per_stage(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();
        $before = \App\Models\Notification::query()
            ->where('shop_id', $this->shopOwnerAuth->id)
            ->where('group_key', "payslip-approval-{$payslip->id}-finance-level-1")
            ->count();

        $this->payslipApprovalService->notifyPayslipApprovalRequested($payslip, $this->requester);

        $this->assertSame($before,
            \App\Models\Notification::query()
                ->where('shop_id', $this->shopOwnerAuth->id)
                ->where('group_key', "payslip-approval-{$payslip->id}-finance-level-1")
                ->count()
        );
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

        $this->assertApprovalStage($payslip, 2, 3, 'shop_owner');
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
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->financeFirst->id,
            'title' => 'Payslip Ready for Disbursement',
            'group_key' => "payslip-approval-{$payslip->id}-ready-for-disbursement",
        ]);
    }

    public function test_shop_owner_final_approval_recreates_an_enum_compatible_erp_actor_when_mapping_is_missing(): void
    {
        $this->shopOwnerMappedUser->delete();
        $payslip = $this->createLegacyPayslip();

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Finance checker approval',
            ])
            ->assertOk();

        $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/final-approve", [
                'notes' => 'Shop owner approval without a pre-existing ERP mapping',
            ])
            ->assertOk();

        $actor = User::query()
            ->where('shop_owner_id', $this->shopOwnerAuth->id)
            ->where('email', 'shopowner+' . $this->shopOwnerAuth->id . '@solespace.local')
            ->firstOrFail();

        $this->assertSame('STAFF', $actor->role);
        $this->assertTrue($actor->hasRole('Shop Owner'));
    }

    public function test_batch_legacy_final_approval_notifies_finance_disburser(): void
    {
        $this->shopOwnerAuth->update(['registration_type' => 'company']);
        $payslip = $this->createLegacyPayslip();

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Finance checker approval',
            ])
            ->assertOk();

        $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson('/api/shop-owner/payslip-approvals/batch/final-approve', [
                'payslip_ids' => [$payslip->id],
                'notes' => 'Shop owner batch approval',
            ])
            ->assertOk()
            ->assertJson([
                'approved' => 1,
                'failed' => 0,
            ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->financeFirst->id,
            'title' => 'Payslip Ready for Disbursement',
            'group_key' => "payslip-approval-{$payslip->id}-ready-for-disbursement",
        ]);
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

        $this->assertApprovalStage($v4Payslip, 2, 3, 'shop_owner');
        $this->assertApprovalStage($v4OwnerStagePayslip, 2, 3, 'shop_owner');

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

    public function test_stale_financial_values_cannot_be_approved(): void
    {
        $payslip = $this->createWorkflowBoundPayslip();
        $payslip->update([
            'gross_salary' => '19999.99',
            'net_salary' => '19999.99',
        ]);

        $response = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/payslip-approvals/{$payslip->id}/approve", [
                'notes' => 'Approve changed values',
            ]);

        $response->assertStatus(422);
        $payslip->refresh();
        $this->assertSame('pending', $payslip->status);
        $this->assertSame('pending', $payslip->approval_status);
        $this->assertApprovalStage($payslip, 1, 3, 'finance');
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
