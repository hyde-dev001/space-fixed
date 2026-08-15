<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CanonicalOwnerRouteParityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('moduleRouteProvider')]
    public function test_eligible_enabled_owner_receives_the_same_module_component_from_both_routes(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => true,
        ]);

        $canonical = $this->actingAs($owner, 'shop_owner')
            ->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/ModuleLanding', false));

        $compatibility = $this->actingAs($owner, 'shop_owner')
            ->get($compatibilityPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/ModuleLanding', false));

        $this->assertSame($canonical->status(), $compatibility->status());
    }

    #[DataProvider('moduleRouteProvider')]
    public function test_canonical_module_route_works_when_erp_workspace_flag_is_off(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/ModuleLanding', false));

        $this->actingAs($owner, 'shop_owner')
            ->getJson($compatibilityPath)
            ->assertNotFound();
    }

    #[DataProvider('moduleRouteProvider')]
    public function test_ineligible_and_disabled_module_outcomes_match_the_compatibility_route(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);
        $ineligible = $this->owner('partnership', 'retail');

        $canonicalIneligible = $this->actingAs($ineligible, 'shop_owner')->getJson($canonicalPath);
        $compatibilityIneligible = $this->actingAs($ineligible, 'shop_owner')->getJson($compatibilityPath);
        $this->assertSame($compatibilityIneligible->status(), $canonicalIneligible->status());
        $this->assertSame($compatibilityIneligible->json('code'), $canonicalIneligible->json('code'));

        $disabled = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $disabled->getKey(),
            'module_key' => $moduleKey,
            'enabled' => false,
        ]);

        $canonicalDisabled = $this->actingAs($disabled, 'shop_owner')->getJson($canonicalPath);
        $compatibilityDisabled = $this->actingAs($disabled, 'shop_owner')->getJson($compatibilityPath);
        $this->assertSame($compatibilityDisabled->status(), $canonicalDisabled->status());
        $this->assertSame($compatibilityDisabled->json('code'), $canonicalDisabled->json('code'));
    }

    #[DataProvider('moduleRouteProvider')]
    public function test_unauthenticated_and_dual_session_requests_select_the_owner_actor_for_both_routes(
        string $slug,
        string $canonicalRoute,
        string $canonicalPath,
        string $compatibilityPath,
        string $moduleKey,
    ): void {
        config([
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => $moduleKey,
            'enabled' => true,
        ]);

        $canonicalGuest = $this->getJson($canonicalPath);
        $compatibilityGuest = $this->getJson($compatibilityPath);
        $this->assertSame($compatibilityGuest->status(), $canonicalGuest->status());
        $this->assertSame($compatibilityGuest->json('code'), $canonicalGuest->json('code'));

        $employee = \App\Models\User::factory()->create(['shop_owner_id' => $owner->getKey()]);
        $this->actingAs($owner, 'shop_owner');
        $this->actingAs($employee, 'user');

        $this->get($canonicalPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/ModuleLanding', false));
        $this->get($compatibilityPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/ModuleLanding', false));
    }

    public function test_payments_landing_exposes_only_authorized_owner_safe_operation_links(): void
    {
        config([
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/operate/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Payments/CanonicalPaymentsLanding', false)
                ->where('links.retail', route('shop-owner.point-of-sale'))
                ->where('links.repair', null)
            );

        config(['shop_modules.owner_erp_workspace_enabled' => true]);
        $repairOwner = $this->owner('company', 'repair');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $repairOwner->getKey(),
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        $this->actingAs($repairOwner, 'shop_owner')
            ->get('/shop-owner/operate/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('links.retail', null)
                ->where('links.repair', route('shop-owner.erp.repair.point-of-sale'))
            );
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function moduleRouteProvider(): array
    {
        return [
            'retail' => [
                'retail',
                'shop-owner.shell.operate.retail',
                '/shop-owner/operate/retail',
                '/shop-owner/erp/retail',
                'retail_operations',
            ],
            'repair' => [
                'repair',
                'shop-owner.shell.operate.repair',
                '/shop-owner/operate/repair',
                '/shop-owner/erp/repair',
                'repair_operations',
            ],
            'customers' => [
                'crm',
                'shop-owner.shell.operate.customers',
                '/shop-owner/operate/customers',
                '/shop-owner/erp/crm',
                'crm',
            ],
            'finance' => [
                'finance',
                'shop-owner.shell.oversee.finance',
                '/shop-owner/oversee/finance',
                '/shop-owner/erp/finance',
                'finance',
            ],
            'workforce' => [
                'hr',
                'shop-owner.shell.oversee.workforce',
                '/shop-owner/oversee/workforce',
                '/shop-owner/erp/hr',
                'hr_employees',
            ],
            'inventory' => [
                'inventory',
                'shop-owner.shell.oversee.inventory',
                '/shop-owner/oversee/inventory',
                '/shop-owner/erp/inventory',
                'inventory',
            ],
            'procurement' => [
                'procurement',
                'shop-owner.shell.oversee.procurement',
                '/shop-owner/oversee/procurement',
                '/shop-owner/erp/procurement',
                'procurement',
            ],
            'logistics' => [
                'logistics',
                'shop-owner.shell.oversee.logistics',
                '/shop-owner/oversee/logistics',
                '/shop-owner/erp/logistics',
                'logistics',
            ],
        ];
    }

    private function owner(string $registrationType, string $businessType): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => $registrationType,
            'business_type' => $businessType,
        ]);
    }
}
