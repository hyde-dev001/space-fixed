<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffArticlesRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_eligible_retail_staff_user_can_open_the_hub_and_a_detail_route(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user, 'user')
            ->get('/erp/articles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Articles/Index', false)
                ->where('articleSlug', null));

        $this->actingAs($user, 'user')
            ->get('/erp/articles/staff-workspace-permissions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Articles/Index', false)
                ->where('articleSlug', 'staff-workspace-permissions'));
    }

    public function test_articles_require_authentication(): void
    {
        $this->get('/erp/articles')->assertRedirect(route('login'));
    }

    public function test_articles_require_a_regular_staff_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'STAFF',
            'shop_owner_id' => ShopOwner::factory()->create(['business_type' => 'retail'])->id,
        ]);

        $this->actingAs($user, 'user')
            ->get('/erp/articles')
            ->assertForbidden();
    }

    public function test_specialized_staff_accounts_cannot_open_the_regular_staff_catalog(): void
    {
        $permission = Permission::findOrCreate('access-staff-dashboard', 'user');

        foreach ([
            'Cashier',
            'Repairer',
            'Logistics Dispatcher',
            'Logistics Rider',
            'Inventory',
            'Procurement',
            'Manager',
            'HR',
            'Finance',
            'CRM',
        ] as $roleName) {
            $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
            $user = User::factory()->create([
                'role' => 'STAFF',
                'shop_owner_id' => $shop->id,
            ]);
            $role = Role::findOrCreate($roleName, 'user');
            $user->assignRole($role);
            $user->givePermissionTo($permission);

            $this->actingAs($user, 'user')
                ->get('/erp/articles')
                ->assertForbidden();
        }
    }

    public function test_articles_are_limited_to_retail_capable_businesses(): void
    {
        $repairStaff = $this->staffUser('repair');
        $bothStaff = $this->staffUser('both');

        $this->actingAs($repairStaff, 'user')
            ->get('/erp/articles')
            ->assertForbidden();

        $this->actingAs($bothStaff, 'user')
            ->get('/erp/articles')
            ->assertOk();
    }

    public function test_forced_password_change_redirects_before_rendering_articles(): void
    {
        $user = $this->staffUser('retail', ['force_password_change' => true]);

        $this->actingAs($user, 'user')
            ->get('/erp/articles')
            ->assertRedirect(route('erp.profile'));
    }

    public function test_each_specialized_employee_account_gets_only_its_own_article_route(): void
    {
        $accounts = [
            ['audience' => 'manager', 'path' => '/erp/manager/articles', 'role' => 'Manager'],
            ['audience' => 'finance', 'path' => '/finance/articles', 'role' => 'Finance'],
            ['audience' => 'hr', 'path' => '/erp/hr/articles', 'role' => 'HR'],
            ['audience' => 'crm', 'path' => '/crm/articles', 'role' => 'CRM'],
            ['audience' => 'cashier', 'path' => '/erp/cashier/articles', 'role' => 'Cashier'],
            ['audience' => 'repairer', 'path' => '/erp/repairer/articles', 'role' => 'Repairer', 'business_type' => 'repair'],
            ['audience' => 'inventory', 'path' => '/erp/inventory/articles', 'role' => 'Inventory'],
            ['audience' => 'procurement', 'path' => '/erp/procurement/articles', 'role' => 'Procurement'],
            ['audience' => 'logistics-dispatcher', 'path' => '/erp/logistics/articles', 'role' => 'Logistics Dispatcher'],
        ];

        foreach ($accounts as $account) {
            $user = $this->specializedUser(
                $account['role'],
                $account['business_type'] ?? 'retail',
            );

            $this->actingAs($user, 'user')
                ->get($account['path'])
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('ERP/Articles/Index', false)
                    ->where('articleAudience', $account['audience'])
                    ->where('articleSlug', null));
        }
    }

    public function test_specialized_article_detail_keeps_the_active_audience(): void
    {
        $user = $this->specializedUser('Manager');

        $this->actingAs($user, 'user')
            ->get('/erp/manager/articles/staff-workspace-permissions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('articleAudience', 'manager')
                ->where('articleSlug', 'staff-workspace-permissions'));
    }

    public function test_repairer_articles_keep_the_repair_business_boundary(): void
    {
        $retailRepairer = $this->specializedUser('Repairer', 'retail');

        $this->actingAs($retailRepairer, 'user')
            ->get('/erp/repairer/articles')
            ->assertForbidden();
    }

    public function test_approved_shop_owners_can_open_the_owner_catalog_for_each_business_variant(): void
    {
        foreach ([
            ['registration_type' => 'company', 'business_type' => 'retail'],
            ['registration_type' => 'individual', 'business_type' => 'repair'],
            ['registration_type' => 'company', 'business_type' => 'both'],
        ] as $attributes) {
            $owner = ShopOwner::factory()->approved()->create($attributes);

            $this->actingAs($owner, 'shop_owner')
                ->get('/shop-owner/erp/articles')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('ERP/Articles/Index', false)
                    ->where('articleAudience', 'shop-owner')
                    ->where('articleSlug', null));
        }
    }

    public function test_pending_shop_owners_cannot_open_the_owner_catalog(): void
    {
        $owner = ShopOwner::factory()->pending()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/articles')
            ->assertRedirect(route('shop-owner.pending-approval'));
    }

    public function test_article_route_contracts_stay_separate(): void
    {
        $routes = config('shop_modules.routes');
        $entry = $routes['shop-owner.erp.articles.index'];
        $this->assertSame('core', $entry['classification']);
        $this->assertSame('shop_owner', $entry['actor_guard']);
        $this->assertSame('allowed', $entry['owner_access']);
        $this->assertSame(['company', 'individual'], $entry['registration_types']);
        $this->assertSame(['retail', 'repair', 'both'], $entry['business_types']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function staffUser(string $businessType = 'retail', array $attributes = []): User
    {
        $shop = ShopOwner::factory()->create(['business_type' => $businessType]);
        $permission = Permission::findOrCreate('access-staff-dashboard', 'user');

        $user = User::factory()->create(array_merge([
            'role' => 'STAFF',
            'shop_owner_id' => $shop->id,
        ], $attributes));
        $user->givePermissionTo($permission);

        return $user;
    }

    private function specializedUser(string $roleName, string $businessType = 'retail'): User
    {
        $shop = ShopOwner::factory()->create(['business_type' => $businessType]);
        $user = User::factory()->create([
            'role' => strtoupper(str_replace(' ', '_', $roleName)),
            'shop_owner_id' => $shop->id,
        ]);

        $user->assignRole(Role::findOrCreate($roleName, 'user'));

        return $user;
    }
}
