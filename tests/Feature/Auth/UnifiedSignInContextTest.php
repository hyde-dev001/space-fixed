<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnifiedSignInContextTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_login_entry_routes_render_the_shared_page_with_a_trusted_context(): void
    {
        $this->get(route('user.login.form'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserSide/Auth/UserLogin')
                ->where('initialAuthContext', 'user'));

        $this->get(route('shop-owner.login.form'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserSide/Auth/UserLogin')
                ->where('initialAuthContext', 'shop_owner'));
    }

    #[Test]
    public function user_login_never_falls_back_to_the_shop_owner_guard(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'email' => 'owner-only@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $this->postJson('/user/login', [
            'email' => $owner->email,
            'password' => 'Password123!',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Invalid email or password.');

        $this->assertGuest('user');
        $this->assertGuest('shop_owner');
    }

    #[Test]
    public function shop_owner_login_never_falls_back_to_the_user_guard(): void
    {
        $user = User::factory()->create([
            'email' => 'user-only@example.test',
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
            'status' => 'active',
            'shop_owner_id' => null,
        ]);

        $this->postJson('/shop-owner/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Invalid email or password.');

        $this->assertGuest('user');
        $this->assertGuest('shop_owner');
    }

    #[Test]
    public function wrong_context_and_invalid_credentials_use_the_same_generic_owner_failure(): void
    {
        $pendingOwner = ShopOwner::factory()->pending()->create([
            'email' => 'pending-owner@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $invalid = $this->postJson('/shop-owner/login', [
            'email' => 'missing-owner@example.test',
            'password' => 'Password123!',
        ]);
        $wrongPassword = $this->postJson('/shop-owner/login', [
            'email' => $pendingOwner->email,
            'password' => 'WrongPassword123!',
        ]);

        $this->assertSame(422, $invalid->status());
        $this->assertSame(422, $wrongPassword->status());
        $this->assertSame(
            $invalid->json('errors.email.0'),
            $wrongPassword->json('errors.email.0'),
        );
        $this->assertSame('Invalid email or password.', $wrongPassword->json('errors.email.0'));
    }

    #[Test]
    public function owner_status_is_revealed_only_after_the_selected_password_is_verified(): void
    {
        $pendingOwner = ShopOwner::factory()->pending()->create([
            'email' => 'pending-owner@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $this->postJson('/shop-owner/login', [
            'email' => $pendingOwner->email,
            'password' => 'Password123!',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Your application is still pending admin approval. Please wait for confirmation.');
    }

    #[Test]
    public function successful_user_login_rotates_the_session_and_honors_remember(): void
    {
        $user = User::factory()->create([
            'email' => 'remember-user@example.test',
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
            'status' => 'active',
            'shop_owner_id' => null,
        ]);
        $before = session()->getId();

        $response = $this->post('/user/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'remember' => true,
        ]);

        $response->assertRedirect(route('landing'))
            ->assertCookie(auth('user')->getRecallerName());
        $this->assertNotSame($before, $response->getSession()->getId());
        $this->assertAuthenticatedAs($user, 'user');
    }

    #[Test]
    public function successful_owner_login_rotates_the_session_and_honors_remember(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'email' => 'remember-owner@example.test',
            'password' => Hash::make('Password123!'),
        ]);
        $before = session()->getId();

        $response = $this->post('/shop-owner/login', [
            'email' => $owner->email,
            'password' => 'Password123!',
            'remember' => true,
        ]);

        $response->assertRedirect(route('shop-owner.dashboard'))
            ->assertCookie(auth('shop_owner')->getRecallerName());
        $this->assertNotSame($before, $response->getSession()->getId());
        $this->assertAuthenticatedAs($owner, 'shop_owner');
    }

    #[Test]
    public function both_login_posts_use_the_same_ten_attempt_per_minute_throttle(): void
    {
        $routes = [
            ['name' => 'user.login', 'uri' => '/user/login', 'ip' => '192.0.2.41'],
            ['name' => 'shop-owner.login', 'uri' => '/shop-owner/login', 'ip' => '192.0.2.42'],
        ];

        foreach ($routes as $route) {
            $this->assertContains('throttle:10,1', Route::getRoutes()->getByName($route['name'])->middleware());

            for ($attempt = 0; $attempt < 10; $attempt++) {
                $this->withServerVariables(['REMOTE_ADDR' => $route['ip']])
                    ->postJson($route['uri'], [])
                    ->assertUnprocessable();
            }

            $this->withServerVariables(['REMOTE_ADDR' => $route['ip']])
                ->postJson($route['uri'], [])
                ->assertTooManyRequests();
        }
    }
}
