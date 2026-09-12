<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ShopModuleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_passes_through_even_when_module_state_is_missing(): void
    {
        $this->defineRoute('flag-off', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['retail_operations'],
            'actor_guards' => ['shop_owner'],
            'customer_capable' => false,
        ]);
        config(['shop_modules.enforcement_enabled' => false]);
        $owner = ShopOwner::factory()->approved()->create(['business_type' => 'retail', 'registration_type' => 'individual']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/business-scaling/flag-off')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_owner_allowance_and_stable_denials_are_fail_closed(): void
    {
        $this->defineRoute('owner-access', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['retail_operations'],
            'actor_guards' => ['shop_owner'],
            'customer_capable' => false,
        ]);
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create(['business_type' => 'retail', 'registration_type' => 'individual']);
        ShopOwnerModule::factory()->create(['shop_owner_id' => $owner->id, 'module_key' => 'retail_operations', 'enabled' => true]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/business-scaling/owner-access')
            ->assertOk();

        $owner->modules()->update(['enabled' => false]);
        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/business-scaling/owner-access')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED')
            ->assertJsonPath('module_keys.0', 'retail_operations');

        $owner->modules()->delete();
        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/business-scaling/owner-access')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_STATE_MISSING');
    }

    public function test_gates_employees_customers_and_conflicting_sessions_use_authoritative_metadata(): void
    {
        $this->defineRoute('all-gate', [
            'classification' => 'module',
            'mode' => 'all_of',
            'module_keys' => ['retail_operations', 'repair_operations'],
            'actor_guards' => ['user'],
            'customer_capable' => false,
        ]);
        $this->defineRoute('any-gate', [
            'classification' => 'module',
            'mode' => 'any_of',
            'module_keys' => ['retail_operations', 'repair_operations'],
            'actor_guards' => ['user'],
            'customer_capable' => false,
        ]);
        $this->defineRoute('customer-gate', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['retail_operations'],
            'actor_guards' => ['user'],
            'customer_capable' => true,
        ]);
        config(['shop_modules.enforcement_enabled' => true]);

        $owner = ShopOwner::factory()->approved()->create(['business_type' => 'both', 'registration_type' => 'company']);
        ShopOwnerModule::factory()->create(['shop_owner_id' => $owner->id, 'module_key' => 'retail_operations', 'enabled' => true]);
        ShopOwnerModule::factory()->create(['shop_owner_id' => $owner->id, 'module_key' => 'repair_operations', 'enabled' => false]);
        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);

        $this->actingAs($employee, 'user')
            ->getJson('/testing/business-scaling/all-gate')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
        $this->actingAs($employee, 'user')
            ->getJson('/testing/business-scaling/any-gate')
            ->assertOk();

        $customer = User::factory()->create(['shop_owner_id' => null]);
        $this->actingAs($customer, 'user')
            ->getJson('/testing/business-scaling/customer-gate')
            ->assertOk();

        $owner->modules()->where('module_key', 'repair_operations')->update(['enabled' => true]);
        $this->actingAs($employee, 'user')
            ->getJson('/testing/business-scaling/all-gate')
            ->assertOk();

        $otherOwner = ShopOwner::factory()->approved()->create(['business_type' => 'retail', 'registration_type' => 'individual']);
        $conflictingEmployee = User::factory()->create(['shop_owner_id' => $otherOwner->id]);
        $this->defineRoute('conflict', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['retail_operations'],
            'actor_guards' => ['shop_owner', 'user'],
            'customer_capable' => false,
        ], 'user');
        $this->actingAs($owner, 'shop_owner')->actingAs($conflictingEmployee, 'user')
            ->getJson('/testing/business-scaling/conflict')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_INELIGIBLE');
    }

    public function test_unknown_entry_invalid_owner_state_and_browser_denial_are_safe(): void
    {
        $this->defineRoute('unknown-entry', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['not-a-module'],
            'actor_guards' => ['shop_owner'],
            'customer_capable' => false,
        ]);
        $this->defineRoute('browser-denial', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['retail_operations'],
            'actor_guards' => ['shop_owner'],
            'customer_capable' => false,
        ]);
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->create(['status' => 'pending', 'business_type' => 'retail', 'registration_type' => 'individual']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/business-scaling/unknown-entry')
            ->assertForbidden()
            ->assertJsonPath('code', 'UNKNOWN_MODULE');
        $this->actingAs($owner, 'shop_owner')
            ->getJson('/testing/business-scaling/browser-denial')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_INELIGIBLE');

        Auth::guard('shop_owner')->logout();
        $this->get('/testing/business-scaling/browser-denial')
            ->assertRedirect('/login');
    }

    private function defineRoute(string $name, array $entry, ?string $authGuard = null): void
    {
        $routeName = 'testing.business-scaling.'.$name;
        config(["shop_modules.routes.{$routeName}" => $entry]);
        Route::middleware(['auth:'.($authGuard ?? ($entry['actor_guards'][0] === 'shop_owner' ? 'shop_owner' : 'user')), 'shop.module'])
            ->get('/testing/business-scaling/'.$name, fn () => response()->json(['ok' => true]))
            ->name($routeName);
    }
}
