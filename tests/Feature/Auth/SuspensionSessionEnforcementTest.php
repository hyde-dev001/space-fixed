<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SuspensionSessionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_owner_guard_does_not_destroy_valid_employee_session(): void
    {
        $employeeOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create([
            'email' => 'active.employee@example.test',
            'status' => 'active',
            'shop_owner_id' => $employeeOwner->id,
        ]);
        Employee::factory()->active()->create([
            'shop_owner_id' => $employeeOwner->id,
            'email' => $employee->email,
        ]);
        $pendingOwner = ShopOwner::factory()->pending()->create();

        Permission::findOrCreate('access-product-upload-staff', 'user');
        $employee->givePermissionTo('access-product-upload-staff');

        $this->actingAs($pendingOwner, 'shop_owner')
            ->actingAs($employee, 'user')
            ->getJson('/api/products/meta/showroom-entitlement')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson(route('user.me'))
            ->assertOk()
            ->assertJsonPath('user.id', $employee->id);

        $this->assertGuest('shop_owner');
        $this->assertAuthenticatedAs($employee, 'user');
    }

    public function test_unavailable_user_guard_does_not_destroy_valid_owner_product_session(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $staleUser = User::factory()->create(['status' => 'suspended']);

        $this->actingAs($staleUser, 'user')
            ->actingAs($owner, 'shop_owner')
            ->getJson(route('shop_owner.products.showroom-entitlement'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertGuest('user');
        $this->assertAuthenticatedAs($owner, 'shop_owner');
    }

    public function test_pending_owner_guard_does_not_destroy_valid_super_admin_session(): void
    {
        Route::middleware(['web', 'auth:super_admin'])
            ->get('/testing/privileged-session-survives', fn () => response()->json(['ok' => true]));

        $pendingOwner = ShopOwner::factory()->pending()->create();
        $admin = SuperAdmin::factory()->superAdmin()->create();

        $this->actingAs($pendingOwner, 'shop_owner')
            ->actingAs($admin, 'super_admin')
            ->getJson('/testing/privileged-session-survives')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertGuest('shop_owner');
        $this->assertAuthenticatedAs($admin, 'super_admin');
    }

    public function test_pending_owner_can_view_pending_page_but_not_operational_routes(): void
    {
        $pendingOwner = ShopOwner::factory()->pending()->create();

        $this->actingAs($pendingOwner, 'shop_owner')
            ->get(route('shop-owner.pending-approval'))
            ->assertOk();

        $this->assertAuthenticatedAs($pendingOwner, 'shop_owner');

        $this->getJson(route('shop_owner.products.showroom-entitlement'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_unavailable');

        $this->assertGuest('shop_owner');
    }

    public function test_employee_with_same_email_in_another_shop_does_not_invalidate_current_employee(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $user = User::factory()->create([
            'email' => 'shared.employee@example.test',
            'status' => 'active',
            'shop_owner_id' => $owner->id,
        ]);

        Employee::factory()->active()->create([
            'shop_owner_id' => $owner->id,
            'email' => $user->email,
        ]);
        Employee::factory()->suspended()->create([
            'shop_owner_id' => $otherOwner->id,
            'email' => strtoupper($user->email),
        ]);

        $this->actingAs($user, 'user')
            ->getJson(route('user.me'))
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user, 'user');
    }

    public function test_duplicate_employee_records_in_same_shop_are_denied(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $user = User::factory()->create([
            'email' => 'duplicate.employee@example.test',
            'status' => 'active',
            'shop_owner_id' => $owner->id,
        ]);

        Employee::factory()->count(2)->active()->create([
            'shop_owner_id' => $owner->id,
            'email' => $user->email,
        ]);

        $this->actingAs($user, 'user')
            ->getJson(route('user.me'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_unavailable');

        $this->assertGuest('user');
    }

    public function test_suspended_shop_owner_is_forced_logged_out_on_next_request(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/me');

        $response
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'account_suspended',
            ]);

        $this->assertGuest('shop_owner');
    }

    public function test_employee_of_suspended_shop_is_forced_logged_out_on_next_request(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'suspended',
        ]);

        $user = User::factory()->create([
            'email' => 'employee@example.com',
            'status' => 'active',
            'shop_owner_id' => $shopOwner->id,
        ]);

        Employee::factory()->active()->create([
            'shop_owner_id' => $shopOwner->id,
            'email' => $user->email,
        ]);

        $response = $this->actingAs($user, 'user')
            ->getJson('/erp/time-in');

        $response
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'account_suspended',
            ]);

        $this->assertGuest('user');
    }

    public function test_employee_login_is_blocked_when_shop_is_suspended(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'suspended',
        ]);

        $user = User::factory()->create([
            'email' => 'blocked-employee@example.com',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'shop_owner_id' => $shopOwner->id,
            'email_verified_at' => now(),
        ]);

        Employee::factory()->active()->create([
            'shop_owner_id' => $shopOwner->id,
            'email' => $user->email,
        ]);

        $response = $this->post('/user/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('user');
    }

    public function test_standalone_suspended_user_is_forced_logged_out_on_next_request(): void
    {
        $user = User::factory()->create([
            'status' => 'suspended',
            'shop_owner_id' => null,
        ]);

        $response = $this->actingAs($user, 'user')
            ->getJson('/erp/profile');

        $response
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'account_suspended',
            ]);

        $this->assertGuest('user');
    }

    public function test_archived_user_is_forced_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->delete();

        $response = $this->actingAs($user, 'user')
            ->getJson('/erp/profile');

        $response
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'account_unavailable',
            ]);

        $this->assertGuest('user');
    }

    public function test_archived_parent_shop_denies_an_active_staff_user(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $user = User::factory()->create([
            'status' => 'active',
            'shop_owner_id' => $shopOwner->id,
        ]);
        $shopOwner->delete();

        $response = $this->actingAs($user, 'user')
            ->getJson('/erp/time-in');

        $response->assertStatus(403);
    }
}
