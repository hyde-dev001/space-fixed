<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Finance\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceTaxRateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->shop = ShopOwner::factory()->create();

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
        $user = User::factory()->create(['shop_owner_id' => $this->shop->id]);
        $user->givePermissionTo('access-refund-approval');

        $this->actingAs($user, 'user');

        $this->getJson('/api/finance/invoices')->assertForbidden();
        $this->getJson('/api/finance/expenses')->assertForbidden();
        $this->getJson('/api/finance/tax-rates')->assertForbidden();
    }

    public function test_invoice_and_expense_capabilities_do_not_grant_tax_management(): void
    {
        $user = User::factory()->create(['shop_owner_id' => $this->shop->id]);
        $user->givePermissionTo(['access-finance-invoices', 'access-finance-expenses']);

        $this->actingAs($user, 'user');

        $this->getJson('/api/finance/invoices')->assertOk();
        $this->getJson('/api/finance/expenses')->assertOk();
        $this->getJson('/api/finance/tax-rates')->assertForbidden();
    }

    public function test_tax_capability_does_not_grant_invoice_or_expense_access(): void
    {
        $user = User::factory()->create(['shop_owner_id' => $this->shop->id]);
        $user->givePermissionTo('manage-finance-tax');

        $this->actingAs($user, 'user');

        $this->getJson('/api/finance/tax-rates')->assertOk();
        $this->getJson('/api/finance/invoices')->assertForbidden();
        $this->getJson('/api/finance/expenses')->assertForbidden();
    }

    public function test_tax_rate_reads_are_tenant_scoped(): void
    {
        $user = User::factory()->create(['shop_owner_id' => $this->shop->id]);
        $user->givePermissionTo('manage-finance-tax');
        $otherShop = ShopOwner::factory()->create();
        $otherShopRate = TaxRate::create([
            'shop_id' => $otherShop->id,
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
