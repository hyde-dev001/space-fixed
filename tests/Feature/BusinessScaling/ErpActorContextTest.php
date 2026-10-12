<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Middleware\EnsureErpAudience;
use App\Http\Middleware\ResolveErpActorContext;
use App\Models\ShopOwner;
use App\Models\User;
use App\Support\Erp\ErpActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErpActorContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_family_selects_the_required_guard_when_both_sessions_exist(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);

        $this->defineRoute(
            'employee',
            $this->entry('user'),
            [EnsureErpAudience::class, 'auth:user', ResolveErpActorContext::class],
        );
        $this->defineRoute(
            'owner',
            $this->entry('shop_owner', ownerAccess: 'allowed'),
            [EnsureErpAudience::class, 'auth:shop_owner', ResolveErpActorContext::class],
        );

        $employeeResponse = $this->actingAs($owner, 'shop_owner')
            ->actingAs($employee, 'user')
            ->getJson('/testing/erp/employee');

        $employeeResponse
            ->assertOk()
            ->assertJsonPath('guard', 'user')
            ->assertJsonPath('actor_id', $employee->id)
            ->assertJsonPath('employee_id', $employee->id)
            ->assertJsonPath('owner_mode', false)
            ->assertJsonPath('tenant_owner_id', $owner->id);

        $this->getJson('/testing/erp/owner')
            ->assertOk()
            ->assertJsonPath('guard', 'shop_owner')
            ->assertJsonPath('actor_id', $owner->id)
            ->assertJsonPath('owner_id', $owner->id)
            ->assertJsonPath('owner_mode', true)
            ->assertJsonPath('tenant_owner_id', $owner->id);
    }

    public function test_wrong_guard_is_denied_before_authentication_reinterprets_the_request(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->defineRoute(
            'employee-only',
            $this->entry('user'),
            [EnsureErpAudience::class, 'auth:user', ResolveErpActorContext::class],
        );

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/erp/employee-only')
            ->assertForbidden()
            ->assertJson([
                'code' => 'ERP_ROUTE_NOT_ALLOWED',
                'error' => 'ERP_ROUTE_NOT_ALLOWED',
                'module_keys' => [],
            ]);
    }

    public function test_missing_owner_authentication_has_owner_login_for_browsers_and_stable_json_for_api_clients(): void
    {
        $this->defineRoute(
            'owner-auth',
            $this->entry('shop_owner', ownerAccess: 'allowed'),
            [EnsureErpAudience::class, 'auth:shop_owner', ResolveErpActorContext::class],
        );

        $this->getJson('/testing/erp/owner-auth')
            ->assertUnauthorized()
            ->assertJson([
                'code' => 'ERP_AUTH_REQUIRED',
                'error' => 'ERP_AUTH_REQUIRED',
                'module_keys' => [],
            ]);

        $this->get('/testing/erp/owner-auth')
            ->assertRedirect(route('shop-owner.login.form'));
    }

    public function test_owner_session_cannot_open_an_employee_self_service_route(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->defineRoute(
            'employee-self-service',
            $this->entry('user', selfService: true),
            [EnsureErpAudience::class, 'auth:user', ResolveErpActorContext::class],
        );

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/erp/employee-self-service')
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');
    }

    public function test_sequential_requests_receive_distinct_context_instances_and_actors(): void
    {
        $firstOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $secondOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $firstEmployee = User::factory()->create(['shop_owner_id' => $firstOwner->id]);
        $secondEmployee = User::factory()->create(['shop_owner_id' => $secondOwner->id]);

        $this->defineRoute(
            'sequential',
            $this->entry('user'),
            [EnsureErpAudience::class, 'auth:user', ResolveErpActorContext::class],
        );

        $first = $this->actingAs($firstEmployee, 'user')
            ->getJson('/testing/erp/sequential')
            ->assertOk();
        $second = $this->actingAs($secondEmployee, 'user')
            ->getJson('/testing/erp/sequential')
            ->assertOk();

        $this->assertNotSame(
            $first->json('context_id'),
            $second->json('context_id'),
        );
        $this->assertSame($firstEmployee->id, $first->json('actor_id'));
        $this->assertSame($secondEmployee->id, $second->json('actor_id'));
        $this->assertSame($firstOwner->id, $first->json('tenant_owner_id'));
        $this->assertSame($secondOwner->id, $second->json('tenant_owner_id'));
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function defineRoute(string $name, array $entry, array $middleware): void
    {
        $routeName = ($entry['audience'] ?? null) === 'shop_owner'
            ? 'shop-owner.erp.testing.'.$name
            : 'testing.erp.'.$name;
        $routes = config('shop_modules.routes', []);
        $routes[$routeName] = $entry;
        config(['shop_modules.routes' => $routes]);

        Route::middleware($middleware)
            ->get('/testing/erp/'.$name, function () {
                /** @var ErpActorContext $context */
                $context = app(ErpActorContext::class);

                return response()->json([
                    'context_id' => spl_object_id($context),
                    'guard' => $context->guard(),
                    'actor_id' => $context->actor()->getAuthIdentifier(),
                    'employee_id' => $context->employeeActor()?->getAuthIdentifier(),
                    'owner_id' => $context->ownerActor()?->getAuthIdentifier(),
                    'owner_mode' => $context->isOwnerMode(),
                    'tenant_owner_id' => $context->tenantOwner()->getKey(),
                ]);
            })
            ->name($routeName);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $guard, string $ownerAccess = 'denied', bool $selfService = false): array
    {
        return [
            'methods' => ['GET'],
            'classification' => 'core',
            'audience' => $guard,
            'actor_guard' => $guard,
            'module_keys' => [],
            'mode' => null,
            'registration_types' => ['company'],
            'business_types' => ['retail', 'repair', 'both'],
            'action' => 'view',
            'owner_access' => $ownerAccess,
            'owner_denial_reason' => $ownerAccess === 'allowed' ? null : 'employee_subject_required',
            'domain_rule' => null,
            'risk_tier' => 'normal',
            'paired_route' => null,
            'navigation_group' => null,
            'self_service' => $selfService,
            'supporting_routes' => [],
            'actor_persistence' => 'not_applicable',
        ];
    }
}
