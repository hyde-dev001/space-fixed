<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CanonicalOwnerRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_kill_switch_returns_complete_existing_presentation_for_canonical_bookmarks(): void
    {
        $owner = $this->owner('company', 'both');
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.enforcement_enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ownerShell.presentation', 'canonical')
                ->where('ownerShell.context', 'company'));

        config([
            'owner_shell.enabled' => false,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('ownerShell.presentation', 'existing')
                ->where('ownerShell.selection_reason', 'global_disabled')
                ->where('ownerShell.context', null)
                ->where('ownerShell.groups', [])
                ->where('ownerShell.compatibility.show_erp_fallback', false));
    }

    public function test_canonical_bookmarks_stay_on_their_underlying_capability_without_redirect_loops(): void
    {
        $owner = $this->owner('company', 'both');
        config([
            'owner_shell.enabled' => false,
            'shop_modules.enforcement_enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        foreach ($this->canonicalBookmarkPaths() as $path) {
            $response = $this->actingAs($owner, 'shop_owner')->get($path);

            $this->assertSame(200, $response->status(), "Canonical bookmark {$path} must remain usable.");
            $this->assertFalse($response->isRedirection(), "Canonical bookmark {$path} must not redirect.");
            $response->assertInertia(fn (Assert $page) => $page
                ->where('ownerShell.presentation', 'existing')
                ->where('ownerShell.groups', []));
        }
    }

    public function test_rollback_does_not_change_shop_module_rows(): void
    {
        $owner = $this->owner('company', 'both');
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        $before = $this->moduleSnapshot($owner);

        config([
            'owner_shell.enabled' => false,
            'shop_modules.enforcement_enabled' => true,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/operate/retail')
            ->assertOk();

        $this->assertSame($before, $this->moduleSnapshot($owner));
    }

    /**
     * @return array<int, string>
     */
    private function canonicalBookmarkPaths(): array
    {
        return [
            '/shop-owner/home',
            '/shop-owner/operate/retail',
            '/shop-owner/operate/repair',
            '/shop-owner/operate/customers',
            '/shop-owner/operate/payments',
            '/shop-owner/oversee/finance',
            '/shop-owner/oversee/workforce',
            '/shop-owner/oversee/inventory',
            '/shop-owner/oversee/procurement',
            '/shop-owner/oversee/logistics',
            '/shop-owner/reports',
            '/shop-owner/audit',
            '/shop-owner/settings/profile',
            '/shop-owner/settings/modules-team',
            '/shop-owner/settings/payments-approvals',
            '/shop-owner/settings/operations',
            '/shop-owner/settings/policies-compliance',
            '/shop-owner/settings/subscription',
        ];
    }

    /** @return array<int, array{module_key: string, enabled: bool}> */
    private function moduleSnapshot(ShopOwner $owner): array
    {
        return ShopOwnerModule::query()
            ->where('shop_owner_id', $owner->getKey())
            ->orderBy('module_key')
            ->get()
            ->map(static fn (ShopOwnerModule $module): array => [
                'module_key' => (string) $module->module_key,
                'enabled' => (bool) $module->enabled,
            ])
            ->all();
    }

    private function owner(string $registrationType, string $businessType): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => $registrationType,
            'business_type' => $businessType,
        ]);
    }
}
