<?php

namespace Tests\Feature\Finance;

use App\Models\Employee;
use App\Models\Finance\ExpenseSettlement;
use App\Models\HR\Payroll;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayrollDisbursementFinanceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('disburse-payroll', 'user');
        Permission::findOrCreate('access-payslip-approval', 'user');
    }

    public function test_disbursement_creates_one_payroll_expense_and_settlement_atomically(): void
    {
        [$shop, $actor, $payroll] = $this->makeReadyPayroll();

        $response = $this->actingAs($actor, 'user')->postJson('/api/finance/payslip-approvals/disburse', [
            'payrollIds' => [$payroll->id],
            'paymentDate' => '2026-08-11',
            'paymentMethod' => 'bank_transfer',
            'payoutReference' => 'PAYROLL-REF-1',
        ]);

        $response->assertOk()->assertJsonPath('processed', 1);
        $this->assertDatabaseHas('payrolls', ['id' => $payroll->id, 'status' => 'paid']);
        $this->assertDatabaseCount('finance_expenses', 1);
        $this->assertDatabaseHas('finance_expenses', [
            'shop_id' => $shop->id,
            'reference' => 'PAY-EXP-'.$payroll->id,
        ]);
        $this->assertDatabaseCount('finance_expense_settlements', 1);
        $this->assertDatabaseHas('finance_expense_settlements', [
            'source' => ExpenseSettlement::SOURCE_PAYROLL,
            'source_reference' => 'payroll:'.$payroll->id,
        ]);

        $retry = $this->actingAs($actor, 'user')->postJson('/api/finance/payslip-approvals/disburse', [
            'payrollIds' => [$payroll->id],
            'paymentDate' => '2026-08-11',
            'paymentMethod' => 'bank_transfer',
            'payoutReference' => 'PAYROLL-REF-1',
        ]);
        $retry->assertStatus(409);
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_old_approval_permission_cannot_disburse_payroll(): void
    {
        [$shop, $actor, $payroll] = $this->makeReadyPayroll();
        $actor->revokePermissionTo('disburse-payroll');
        $actor->givePermissionTo('access-payslip-approval');

        $response = $this->actingAs($actor, 'user')->postJson('/api/finance/payslip-approvals/disburse', [
            'payrollIds' => [$payroll->id],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('payrolls', ['id' => $payroll->id, 'status' => 'approved']);
        $this->assertDatabaseCount('finance_expense_settlements', 0);
    }

    public function test_finance_sync_failure_rolls_back_paid_state(): void
    {
        [$shop, $actor, $payroll] = $this->makeReadyPayroll(['net_salary' => '0.00']);

        $response = $this->actingAs($actor, 'user')->postJson('/api/finance/payslip-approvals/disburse', [
            'payrollIds' => [$payroll->id],
            'paymentDate' => '2026-08-11',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('payrolls', ['id' => $payroll->id, 'status' => 'approved']);
        $this->assertDatabaseCount('finance_expenses', 0);
        $this->assertDatabaseCount('finance_expense_settlements', 0);
    }

    private function makeReadyPayroll(array $overrides = []): array
    {
        $shop = ShopOwner::factory()->create();
        $actor = User::factory()->create(['shop_owner_id' => $shop->id]);
        $actor->givePermissionTo('disburse-payroll');
        $checker = User::factory()->create(['shop_owner_id' => $shop->id]);
        $owner = User::factory()->create(['shop_owner_id' => $shop->id]);
        $employee = Employee::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        $payroll = Payroll::create(array_merge([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'payroll_period' => '2026-08',
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'basic_salary' => '1000.00',
            'base_salary' => '1000.00',
            'gross_salary' => '1000.00',
            'allowances' => '0.00',
            'deductions' => '0.00',
            'total_deductions' => '0.00',
            'tax_amount' => '0.00',
            'net_salary' => '1000.00',
            'status' => 'approved',
            'approval_status' => 'approved',
            'approved_by' => $checker->id,
            'final_approved_by' => $owner->id,
            'payment_method' => 'bank_transfer',
        ], $overrides));

        return [$shop, $actor, $payroll];
    }
}
