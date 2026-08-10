<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Middleware\CheckEmployeeSuspension;
use App\Http\Middleware\EnsureErpAudience;
use App\Http\Middleware\ResolveErpActorContext;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OwnerErpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop_modules.enforcement_enabled' => true]);
    }

    public function test_individual_pending_rejected_and_suspended_owners_are_ineligible_for_owner_erp(): void
    {
        $this->defineOwnerModuleRoute('account-eligibility', 'retail_operations');

        foreach ([
            ['status' => 'approved', 'registration_type' => 'individual'],
            ['status' => 'pending', 'registration_type' => 'company'],
            ['status' => 'rejected', 'registration_type' => 'company'],
            ['status' => 'suspended', 'registration_type' => 'company'],
        ] as $attributes) {
            $owner = ShopOwner::factory()->create($attributes + ['business_type' => 'retail']);
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => 'retail_operations',
                'enabled' => true,
            ]);

            $this->actingAs($owner, 'shop_owner')
                ->getJson('/testing/owner-erp/account-eligibility')
                ->assertForbidden()
                ->assertJson([
                    'code' => 'OWNER_ERP_ACCOUNT_INELIGIBLE',
                    'error' => 'OWNER_ERP_ACCOUNT_INELIGIBLE',
                    'module_keys' => ['retail_operations'],
                ]);
        }
    }

    public function test_wrong_business_type_is_a_module_ineligibility_after_owner_eligibility_passes(): void
    {
        $this->defineOwnerModuleRoute('wrong-business', 'retail_operations');
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/wrong-business')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_INELIGIBLE');
    }

    public function test_owner_denied_route_and_unknown_route_are_not_exposed(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->defineOwnerRoute('denied', $this->entry('shop_owner', ownerAccess: 'denied'));
        Route::middleware([
            EnsureErpAudience::class,
            'auth:shop_owner',
            ResolveErpActorContext::class,
        ])->get('/testing/owner-erp/unknown', fn () => response()->json(['ok' => true]))
            ->name('shop-owner.erp.unknown');

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/denied')
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/unknown')
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');
    }

    public function test_owner_module_boundary_distinguishes_missing_disabled_and_malformed_state(): void
    {
        $this->defineOwnerModuleRoute('missing-state', 'retail_operations');
        $this->defineOwnerModuleRoute('disabled-state', 'retail_operations');
        $this->defineOwnerModuleRoute('unknown-module', 'not-a-module');
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/missing-state')
            ->assertForbidden()
            ->assertJson([
                'code' => 'MODULE_STATE_MISSING',
                'error' => 'MODULE_STATE_MISSING',
                'module_keys' => ['retail_operations'],
            ]);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/disabled-state')
            ->assertForbidden()
            ->assertJson([
                'code' => 'MODULE_DISABLED',
                'error' => 'MODULE_DISABLED',
                'module_keys' => ['retail_operations'],
            ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/unknown-module')
            ->assertForbidden()
            ->assertJson([
                'code' => 'ERP_ROUTE_NOT_ALLOWED',
                'error' => 'ERP_ROUTE_NOT_ALLOWED',
                'module_keys' => ['not-a-module'],
            ]);
    }

    public function test_suspended_owner_uses_erp_boundary_while_non_erp_suspension_behavior_is_unchanged(): void
    {
        $this->defineOwnerModuleRoute('suspended-boundary', 'retail_operations');
        $owner = ShopOwner::factory()->create([
            'status' => 'suspended',
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/owner-erp/suspended-boundary')
            ->assertForbidden()
            ->assertJsonPath('code', 'OWNER_ERP_ACCOUNT_INELIGIBLE');

        Route::middleware([CheckEmployeeSuspension::class])
            ->get('/testing/non-erp-suspension', fn () => response()->json(['ok' => true]))
            ->name('testing.non-erp-suspension');

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/non-erp-suspension')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function defineOwnerRoute(string $name, array $entry): void
    {
        $routeName = 'shop-owner.erp.'.$name;
        $routes = config('shop_modules.routes', []);
        $routes[$routeName] = $entry;
        config(['shop_modules.routes' => $routes]);

        $middleware = [
            CheckEmployeeSuspension::class,
            EnsureErpAudience::class,
            'auth:shop_owner',
            ResolveErpActorContext::class,
        ];
        if (($entry['classification'] ?? null) === 'module') {
            $middleware[] = 'shop.module';
        }

        Route::middleware($middleware)
            ->get('/testing/owner-erp/'.$name, fn () => response()->json(['ok' => true]))
            ->name($routeName);
    }

    private function defineOwnerModuleRoute(string $name, string $moduleKey): void
    {
        $this->defineOwnerRoute($name, $this->entry('shop_owner', [$moduleKey], 'allowed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $guard, array $moduleKeys = [], string $ownerAccess = 'denied'): array
    {
        return [
            'methods' => ['GET'],
            'classification' => $moduleKeys === [] ? 'core' : 'module',
            'audience' => $guard,
            'actor_guard' => $guard,
            'module_keys' => $moduleKeys,
            'mode' => $moduleKeys === [] ? null : 'single',
            'registration_types' => ['company'],
            'business_types' => ['retail', 'repair', 'both'],
            'action' => 'view',
            'owner_access' => $ownerAccess,
            'owner_denial_reason' => $ownerAccess === 'allowed' ? null : 'employee_subject_required',
            'domain_rule' => null,
            'risk_tier' => 'normal',
            'paired_route' => null,
            'navigation_group' => 'testing',
            'self_service' => false,
            'supporting_routes' => [],
            'actor_persistence' => 'not_applicable',
        ];
    }
}
