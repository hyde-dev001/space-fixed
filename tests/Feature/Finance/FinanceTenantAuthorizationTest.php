<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Support\Finance\FinanceShopContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Tests\TestCase;

class FinanceTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_context_comes_from_its_assigned_shop(): void
    {
        $employee = User::factory()->create(['shop_owner_id' => 41, 'role' => 'FINANCE']);

        $this->assertSame(41, $this->resolveFor($employee, ['shop_id' => 999]));
    }

    public function test_shop_owner_role_uses_the_actor_id_as_tenant(): void
    {
        $owner = User::factory()->create(['shop_owner_id' => null, 'role' => 'shop_owner']);

        $this->assertSame((int) $owner->id, $this->resolveFor($owner));
    }

    public function test_missing_shop_context_fails_closed_without_a_default_shop(): void
    {
        $employee = User::factory()->create(['shop_owner_id' => null, 'role' => 'FINANCE']);
        $request = Request::create('/api/finance/invoices');
        $request->setUserResolver(fn (?string $guard = null) => $employee);

        $this->expectException(HttpResponseException::class);
        try {
            app(FinanceShopContext::class)->id($request);
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('TENANT_CONTEXT_REQUIRED', $exception->getResponse()->getData(true)['error']);
            throw $exception;
        }
    }

    private function resolveFor(User $user, array $query = []): int
    {
        $request = Request::create('/api/finance/invoices', 'GET', $query);
        $request->setUserResolver(fn (?string $guard = null) => $user);

        return app(FinanceShopContext::class)->id($request);
    }
}
