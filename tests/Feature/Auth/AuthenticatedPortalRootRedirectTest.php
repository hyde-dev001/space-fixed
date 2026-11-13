<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class AuthenticatedPortalRootRedirectTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_completed_super_admin_root_redirects_to_monitoring_without_losing_session(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);
        $sessionId = session()->getId();

        $response = $this->get('/');
        $location = (string) $response->headers->get('Location');

        $response->assertRedirect(route('admin.system-monitoring'));
        self::assertSame('/admin/system-monitoring', parse_url($location, PHP_URL_PATH));
        self::assertStringNotContainsString('/public', $location);

        $this->get('/admin/system-monitoring')->assertOk();
        $this->assertAuthenticatedAs($admin, 'super_admin');
        self::assertSame($sessionId, session()->getId());
        self::assertDatabaseHas('privileged_sessions', [
            'session_id' => $sessionId,
            'super_admin_id' => $admin->id,
        ]);
    }

    public function test_guest_and_customer_keep_the_landing_page_at_root(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('UserSide/Products/LandingPage'));

        $customer = User::factory()->create();

        $this->actingAs($customer, 'user')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('UserSide/Products/LandingPage'));
    }

    public function test_shop_owner_keeps_existing_root_redirect(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        $this->actingAs($owner, 'shop_owner')
            ->get('/')
            ->assertRedirect(route('shop-owner.dashboard'));
    }

    public function test_employee_keeps_existing_root_redirect(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);

        $this->actingAs($employee, 'user')
            ->get('/')
            ->assertRedirect(route('erp.time-in'));
    }

    public function test_non_admin_cannot_open_system_monitoring(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'user')
            ->get('/admin/system-monitoring')
            ->assertRedirect(route('admin.login'));
    }

    public function test_system_monitoring_accepts_both_trailing_slash_variants(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/admin/system-monitoring')->assertOk();
        $this->get('/admin/system-monitoring/')->assertOk();
    }
}
