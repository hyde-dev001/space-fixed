<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceRouteContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['access-finance-invoices', 'access-finance-expenses', 'access-finance-dashboard', 'manage-finance-tax'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
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

    /** @param \Illuminate\Routing\RouteCollection $routes */
    private function hasRoute($routes, string $uri, string $actionPart): bool
    {
        return $routes->contains(function (RoutingRoute $route) use ($uri, $actionPart): bool {
            return $route->uri() === $uri && str_contains($route->getActionName(), $actionPart);
        });
    }
}
