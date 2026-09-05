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
}
