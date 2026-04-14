<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuspensionSessionEnforcementTest extends TestCase
{
    use RefreshDatabase;

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
}
