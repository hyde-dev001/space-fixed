<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\Approval;
use App\Models\ShopOwner;
use App\Models\User;
use App\Enums\ApprovalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceRouteContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['access-finance-invoices', 'access-finance-expenses', 'access-finance-dashboard', 'manage-finance-tax', 'access-approval-workflow', 'access-repair-price-approval'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
    }

    public function test_repair_pricing_notification_destination_renders_the_canonical_finance_page(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);
        $financeUser = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'force_password_change' => false,
        ]);
        $financeUser->givePermissionTo('access-repair-price-approval');

        $this->actingAs($financeUser, 'user')
            ->get('/finance?section=repair-pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Finance/Finance')
                ->where('auth.user.id', $financeUser->id));
    }

    public function test_finance_role_can_open_the_repair_pricing_notification_destination(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);
        $financeUser = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'FINANCE',
            'force_password_change' => false,
        ]);
        $financeRole = Role::findOrCreate('Finance', 'user');
        $financeRole->givePermissionTo('access-repair-price-approval');
        $financeUser->assignRole($financeRole);

        $this->actingAs($financeUser, 'user')
            ->get('/finance?section=repair-pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Finance/Finance'));
    }

    public function test_canonical_finance_routes_are_the_only_active_write_family(): void
    {
        $routes = collect(Route::getRoutes());

        $this->assertTrue($this->hasRoute($routes, 'api/finance/invoices/{id}/mark-sent', 'markSent'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/invoices/{id}/payments', 'recordPayment'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/expenses/{id}/settlements', 'recordSettlement'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/dashboard', 'FinanceSummaryController'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/invoices/{id}/mark-paid', 'Closure'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/invoices/{id}/post', 'Closure'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/session/{path?}', 'Closure'));
        $this->assertTrue($this->hasRoute($routes, 'api/finance/approvals/pending', 'ApprovalController'));
        $this->assertFalse($routes->contains(fn (RoutingRoute $route): bool => str_contains($route->uri(), 'api/finance/reports/')));
        $this->assertFalse($routes->contains(fn (RoutingRoute $route): bool => str_contains($route->getActionName(), 'FinancialReportController')));
        $this->assertFalse($routes->contains(fn (RoutingRoute $route): bool => $route->uri() === 'api/finance/session/expenses'));
    }

    public function test_mark_sent_changes_only_internal_invoice_status_and_audit_history(): void
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('access-finance-invoices');
        $invoice = Invoice::create([
            'reference' => 'INV-MARK-SENT-1',
            'customer_name' => 'Contract Test',
            'date' => now()->toDateString(),
            'total' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'draft',
            'shop_id' => $shop->id,
        ]);

        $response = $this->actingAs($user, 'user')->postJson(
            "/api/finance/invoices/{$invoice->id}/mark-sent"
        );

        $response->assertOk()->assertJsonPath('message', 'Invoice marked as sent.');
        $this->assertDatabaseHas('finance_invoices', ['id' => $invoice->id, 'status' => 'sent']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mark_invoice_sent',
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_retired_finance_writes_and_session_aliases_return_410(): void
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('access-finance-invoices');

        $this->actingAs($user, 'user')->postJson('/api/finance/invoices/1/mark-paid')
            ->assertStatus(410)
            ->assertJsonPath('code', 'PAYMENT_ROUTE_MOVED');
        $this->actingAs($user, 'user')->postJson('/api/finance/invoices/1/send')
            ->assertStatus(410)
            ->assertJsonPath('code', 'FINANCE_ROUTE_MOVED');
        $this->actingAs($user, 'user')->postJson('/api/finance/invoices/1/post')
            ->assertStatus(410)
            ->assertJsonPath('code', 'FINANCE_ROUTE_MOVED');
        $this->actingAs($user, 'user')->postJson('/api/finance/session/expenses', [])
            ->assertStatus(410)
            ->assertJsonPath('code', 'FINANCE_ROUTE_MOVED');
    }

    public function test_pending_approvals_are_tenant_scoped(): void
    {
        $firstShop = ShopOwner::factory()->create();
        $secondShop = ShopOwner::factory()->create();
        $firstOwner = User::factory()->create(['id' => $firstShop->id, 'shop_owner_id' => $firstShop->id, 'role' => 'Shop Owner']);
        $secondOwner = User::factory()->create(['id' => $secondShop->id, 'shop_owner_id' => $secondShop->id, 'role' => 'Shop Owner']);
        $firstUser = User::factory()->create(['shop_owner_id' => $firstShop->id]);
        $firstUser->givePermissionTo('access-approval-workflow');

        foreach ([[$firstShop, $firstOwner, 'FIRST'], [$secondShop, $secondOwner, 'SECOND']] as [$shop, $owner, $suffix]) {
            $invoice = Invoice::create([
                'reference' => "APPROVAL-INVOICE-{$suffix}",
                'customer_name' => 'Approval Contract',
                'date' => now()->toDateString(),
                'total' => '100.00',
                'tax_amount' => '0.00',
                'status' => 'draft',
                'shop_id' => $shop->id,
            ]);
            Approval::create([
                'shop_owner_id' => $owner->id,
                'approvable_type' => Invoice::class,
                'approvable_id' => $invoice->id,
                'reference' => "APPROVAL-{$suffix}",
                'description' => 'Tenant scope contract',
                'amount' => 100,
                'requested_by' => $firstUser->id,
                'current_level' => 1,
                'total_levels' => 1,
                'status' => ApprovalStatus::PENDING,
                'approval_roles' => ['1' => 'finance'],
                'current_approver_role' => 'finance',
                'level_reviewers' => [],
            ]);
        }

        $this->actingAs($firstUser, 'user')
            ->getJson('/api/finance/approvals/pending')
            ->assertOk()
            ->assertJsonCount(1, 'approvals')
            ->assertJsonPath('approvals.0.reference', 'APPROVAL-FIRST');
    }

    public function test_shared_approval_errors_do_not_expose_exception_details(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/ApprovalController.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("'message' => \$e->getMessage()", $source);
    }

    /** @param \Illuminate\Routing\RouteCollection $routes */
    private function hasRoute($routes, string $uri, string $actionPart): bool
    {
        return $routes->contains(function (RoutingRoute $route) use ($uri, $actionPart): bool {
            return $route->uri() === $uri && str_contains($route->getActionName(), $actionPart);
        });
    }
}
