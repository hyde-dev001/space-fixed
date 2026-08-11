<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Models\Finance\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceTaxRateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'access-finance-invoices',
            'access-finance-expenses',
            'access-refund-approval',
            'manage-finance-tax',
        ] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
    }

    public function test_refund_approval_cannot_open_finance_operations_or_tax(): void
    {
        $user = User::factory()->create(['shop_owner_id' => 1]);
        $user->givePermissionTo('access-refund-approval');

        $this->actingAs($user, 'user');

        $this->getJson('/api/finance/invoices')->assertForbidden();
        $this->getJson('/api/finance/expenses')->assertForbidden();
        $this->getJson('/api/finance/tax-rates')->assertForbidden();
    }

    public function test_invoice_and_expense_capabilities_do_not_grant_tax_management(): void
    {
        $user = User::factory()->create(['shop_owner_id' => 1]);
        $user->givePermissionTo(['access-finance-invoices', 'access-finance-expenses']);

        $this->actingAs($user, 'user');

        $this->getJson('/api/finance/invoices')->assertOk();
        $this->getJson('/api/finance/expenses')->assertOk();
        $this->getJson('/api/finance/tax-rates')->assertForbidden();
    }

    public function test_tax_capability_does_not_grant_invoice_or_expense_access(): void
    {
        $user = User::factory()->create(['shop_owner_id' => 1]);
        $user->givePermissionTo('manage-finance-tax');

        $this->actingAs($user, 'user');

        $this->getJson('/api/finance/tax-rates')->assertOk();
        $this->getJson('/api/finance/invoices')->assertForbidden();
        $this->getJson('/api/finance/expenses')->assertForbidden();
    }

    public function test_tax_rate_reads_are_tenant_scoped(): void
    {
        $user = User::factory()->create(['shop_owner_id' => 1]);
        $user->givePermissionTo('manage-finance-tax');
        $otherShopRate = TaxRate::create([
            'shop_id' => 2,
            'name' => 'Other Shop VAT',
            'code' => 'OTHER-VAT',
            'rate' => 12,
            'type' => 'percentage',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'user')
            ->getJson('/api/finance/tax-rates/'.$otherShopRate->id)
            ->assertNotFound();
    }
}
