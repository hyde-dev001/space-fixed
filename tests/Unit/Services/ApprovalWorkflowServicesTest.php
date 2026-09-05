<?php

namespace Tests\Unit\Services;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Employee;
use App\Models\Finance\Expense;
use App\Models\HR\Payroll;
use App\Models\PriceChangeRequest;
use App\Models\Product;
use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ExpenseApprovalService;
use App\Services\PayslipApprovalService;
use App\Services\PriceChangeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalWorkflowServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;
    private User $financeUser;
    private User $shopOwnerUser;
    private User $financeSecondUser;
    private User $financeFinalUser;
    private ShopOwner $shopOwnerRecord;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('approve-expenses', 'user');
        Role::findOrCreate('finance', 'user');
        Role::findOrCreate('shop-owner', 'user');
        Role::findOrCreate('Finance Manager', 'user');

        $this->shopOwnerRecord = ShopOwner::factory()->create();

        $this->requester = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
        ]);

        $this->financeUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
        ]);
        $this->financeUser->assignRole('finance');

        $this->shopOwnerUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
        ]);
        $this->shopOwnerUser->assignRole('shop-owner');

        $this->financeSecondUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
        ]);
        $this->financeSecondUser->assignRole('finance');

        $this->financeFinalUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
        ]);
        $this->financeFinalUser->assignRole('Finance Manager');
        $this->financeFinalUser->givePermissionTo('approve-expenses');
    }

    public function test_approval_service_transitions_across_four_levels(): void
    {
        $approvalService = app(ApprovalService::class);
        $expense = $this->createExpense();

        $approval = $approvalService->createApproval(
            approvable: $expense,
            approvalRoles: [
                '1' => 'finance',
                '2' => 'shop_owner',
                '3' => 'finance',
                '4' => 'finance_final',
            ],
            requestedBy: $this->requester,
            shopOwner: $this->shopOwnerUser,
            reference: $expense->reference,
            description: 'Expense approval test',
            amount: (float) $expense->amount
        );

        $first = $approvalService->approve($approval->fresh(), $this->financeUser, 'L1 approved');
        $second = $approvalService->approve($approval->fresh(), $this->shopOwnerUser, 'L2 approved');
        $third = $approvalService->approve($approval->fresh(), $this->financeSecondUser, 'L3 approved');
        $fourth = $approvalService->approve($approval->fresh(), $this->financeFinalUser, 'L4 approved');

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue($third['success']);
        $this->assertTrue($fourth['success']);
        $this->assertTrue($fourth['is_final']);

        $approval->refresh();
        $this->assertEquals(4, $approval->current_level);
        $this->assertEquals(ApprovalStatus::APPROVED, $approval->status);
    }

    public function test_expense_approval_service_marks_final_approval(): void
    {
        $this->setExpenseApprovalPolicy(true);
        $service = app(ExpenseApprovalService::class);
        $expense = $this->createExpense();
        $expense->update(['amount' => 6000]);

        $approval = $service->createExpenseApproval($expense, $this->requester);

        $service->approveExpense($expense->fresh(), $this->financeUser, 'ok');
        $result = $service->approveExpense($expense->fresh(), $this->shopOwnerUser, 'final ok');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_final']);

        $expense->refresh();
        $this->assertEquals('approved', $expense->status);
        $this->assertEquals($approval->fresh()->current_level, $expense->current_approval_level);
    }

    public function test_expense_approval_service_freezes_the_manual_owner_policy_role_map(): void
    {
        $service = app(ExpenseApprovalService::class);
        $this->setExpenseApprovalPolicy(true);
        $onExpense = $this->createExpense();
        $onApproval = $service->createExpenseApproval($onExpense, $this->requester);

        $this->assertSame([
            '1' => 'finance',
            '2' => 'shop_owner',
        ], $onApproval->approval_roles);

        $this->setExpenseApprovalPolicy(false);
        $onFirstApproval = $service->approveExpense($onExpense->fresh(), $this->financeUser, 'Finance review');

        $this->assertTrue($onFirstApproval['success']);
        $this->assertFalse($onFirstApproval['is_final']);

        $this->setExpenseApprovalPolicy(false);
        $offExpense = $this->createExpense();
        $offExpense->update(['amount' => 6000]);
        $offApproval = $service->createExpenseApproval($offExpense, $this->requester);

        $this->assertSame(['1' => 'finance'], $offApproval->approval_roles);
    }

    public function test_manual_approval_excludes_payroll_expense_sources(): void
    {
        $expense = $this->createExpense();
        $expense->update(['meta' => ['source' => 'payroll']]);

        $this->expectException(\LogicException::class);
        app(ExpenseApprovalService::class)->createExpenseApproval($expense, $this->requester);
    }

    public function test_payslip_approval_service_marks_final_approval(): void
    {
        $service = app(PayslipApprovalService::class);
        $payroll = $this->createPayroll();

        $service->createPayslipApproval($payroll, $this->shopOwnerUser, $this->requester);

        $service->approvePayslip($payroll->fresh(), $this->financeUser, 'ok');
        $service->approvePayslip($payroll->fresh(), $this->shopOwnerUser, 'ok');
        $service->approvePayslip($payroll->fresh(), $this->financeSecondUser, 'ok');
        $result = $service->approvePayslip($payroll->fresh(), $this->financeFinalUser, 'final ok');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_final']);

        $payroll->refresh();
        $this->assertEquals('approved', $payroll->status);
        $this->assertEquals('approved', $payroll->approval_status);
    }

    public function test_payslip_approval_service_uses_the_disabled_policy_role_map(): void
    {
        $service = app(PayslipApprovalService::class);
        $payroll = $this->createPayroll();
        $settings = ProcurementSettings::getForShopOwner($this->shopOwnerRecord->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['payslip_approval']['enabled'] = false;
        $settings->update(['settings_json' => $settingsJson]);

        $approval = $service->createPayslipApproval($payroll, $this->shopOwnerUser, $this->requester);

        $this->assertSame(3, (int) $approval->total_levels);
        $this->assertSame([
            '1' => 'finance',
            '2' => 'finance',
            '3' => 'finance_final',
        ], $approval->approval_roles);
        $this->assertSame('finance', $approval->current_approver_role);

        $first = $service->approvePayslip($payroll->fresh(), $this->financeUser, 'first finance review');
        $this->assertTrue($first['success']);
        $this->assertFalse($first['is_final']);
        $this->assertSame(2, (int) $approval->fresh()->current_level);
        $this->assertSame('finance', $approval->fresh()->current_approver_role);

        $second = $service->approvePayslip($payroll->fresh(), $this->financeSecondUser, 'second finance review');
        $this->assertTrue($second['success']);
        $this->assertFalse($second['is_final']);
        $this->assertSame(3, (int) $approval->fresh()->current_level);
        $this->assertSame('finance_final', $approval->fresh()->current_approver_role);

        $final = $service->approvePayslip($payroll->fresh(), $this->financeFinalUser, 'final finance review');
        $this->assertTrue($final['success']);
        $this->assertTrue($final['is_final']);
    }

    public function test_price_change_service_creates_workflow_and_finance_can_approve_level_one(): void
    {
        $service = app(PriceChangeApprovalService::class);
        $product = $this->createProduct();

        $priceChange = PriceChangeRequest::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 120,
            'reason' => 'Supplier cost increase',
            'requested_by' => $this->requester->id,
            'status' => 'pending',
            'shop_owner_id' => $this->shopOwnerRecord->id,
        ]);

        $service->createPriceChangeApproval($priceChange, $this->shopOwnerUser, $this->requester);
        $result = $service->approvePriceChange($priceChange->fresh(), $this->financeUser, 'approved by finance');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_final']);

        $priceChange->refresh();
        $this->assertEquals(2, $priceChange->current_approval_level);
        $this->assertEquals('finance_approved', $priceChange->status->value ?? (string) $priceChange->status);
    }

    public function test_approval_service_can_reject_at_current_level(): void
    {
        $approvalService = app(ApprovalService::class);
        $expense = $this->createExpense();

        $approval = $approvalService->createApproval(
            approvable: $expense,
            approvalRoles: [
                '1' => 'finance',
                '2' => 'shop_owner',
                '3' => 'finance',
                '4' => 'finance_final',
            ],
            requestedBy: $this->requester,
            shopOwner: $this->shopOwnerUser,
            reference: $expense->reference,
            description: 'Expense reject test',
            amount: (float) $expense->amount
        );

        $result = $approvalService->reject($approval, $this->financeUser, 'insufficient details');

        $this->assertTrue($result['success']);
        $approval->refresh();
        $this->assertEquals(ApprovalStatus::REJECTED, $approval->status);
    }

    private function createExpense(): Expense
    {
        return Expense::create([
            'reference' => 'EXP-' . now()->timestamp . '-' . random_int(100, 999),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'vendor' => 'Test Vendor',
            'description' => 'Service approval test expense',
            'amount' => 1500,
            'tax_amount' => 0,
            'status' => 'submitted',
        ]);
    }

    private function setExpenseApprovalPolicy(bool $enabled): void
    {
        $settings = ProcurementSettings::getForShopOwner($this->shopOwnerRecord->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['expense_approval']['enabled'] = $enabled;
        $settings->update(['settings_json' => $settingsJson]);
    }

    private function createPayroll(): Payroll
    {
        $employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
            'status' => 'active',
        ]);

        return Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $this->shopOwnerRecord->id,
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
    }

    private function createProduct(): Product
    {
        return Product::create([
            'shop_owner_id' => $this->shopOwnerRecord->id,
            'name' => 'Test Product ' . random_int(1000, 9999),
            'slug' => 'test-product-' . uniqid(),
            'description' => 'Test product for approvals',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }
}
