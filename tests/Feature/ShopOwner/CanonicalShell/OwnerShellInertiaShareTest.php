<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class OwnerShellInertiaShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->canonicalRoutes() as $name => $uri) {
            if (! Route::has($name)) {
                Route::get($uri, static fn (): null => null)->name($name);
            }
        }
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_non_shop_owner_contexts_do_not_receive_owner_shell_metadata(): void
    {
        $this->assertNull($this->shareForGuest()['ownerShell']);

        $customer = User::factory()->create(['shop_owner_id' => null]);
        $this->assertNull($this->shareFor($customer, 'user')['ownerShell']);

        $owner = ShopOwner::factory()->approved()->create();
        $employee = User::factory()->create(['shop_owner_id' => $owner->getKey()]);
        $this->assertNull($this->shareFor($employee, 'user')['ownerShell']);

        $admin = SuperAdmin::create([
            'first_name' => 'Shell',
            'last_name' => 'Admin',
            'email' => 'owner-shell-admin@example.test',
            'phone' => '09170000005',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->assertNull($this->shareFor($admin, 'super_admin')['ownerShell']);
    }

    public function test_flag_off_shares_complete_existing_presentation(): void
    {
        config([
            'owner_shell.enabled' => false,
            'owner_shell.allowlisted_shop_ids' => [],
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $ownerShell = $this->shareFor($owner, 'shop_owner')['ownerShell'];

        $this->assertSame('existing', $ownerShell['presentation']);
        $this->assertSame('global_disabled', $ownerShell['selection_reason']);
        $this->assertNull($ownerShell['context']);
        $this->assertSame([], $ownerShell['groups']);
        $this->assertFalse($ownerShell['compatibility']['show_erp_fallback']);
    }

    public function test_allowlisted_owner_receives_the_server_selected_canonical_presentation(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $ownerShell = $this->shareFor($owner, 'shop_owner')['ownerShell'];

        $this->assertSame('canonical', $ownerShell['presentation']);
        $this->assertSame('shop_allowlisted', $ownerShell['selection_reason']);
        $this->assertSame('company', $ownerShell['context']);
        $this->assertNotEmpty($ownerShell['groups']);
        $this->assertNotContains('settings', array_column($ownerShell['groups'], 'key'));
    }

    public function test_invalid_registration_context_returns_complete_existing_presentation(): void
    {
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [1],
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'partnership',
            'business_type' => 'retail',
        ]);

        $ownerShell = $this->shareFor($owner, 'shop_owner')['ownerShell'];

        $this->assertSame('existing', $ownerShell['presentation']);
        $this->assertSame('invalid_registration_context', $ownerShell['selection_reason']);
        $this->assertNull($ownerShell['context']);
        $this->assertSame([], $ownerShell['groups']);
    }

    public function test_composition_failure_fails_safe_to_existing_presentation(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.modules' => new \stdClass(),
        ]);

        $ownerShell = $this->shareFor($owner, 'shop_owner')['ownerShell'];

        $this->assertSame('existing', $ownerShell['presentation']);
        $this->assertSame('shell_composition_failed', $ownerShell['selection_reason']);
        $this->assertNull($ownerShell['context']);
        $this->assertSame([], $ownerShell['groups']);
        $this->assertFalse($ownerShell['compatibility']['show_erp_fallback']);
    }

    public function test_selection_telemetry_is_written_once_per_session_for_an_unchanged_selection(): void
    {
        config([
            'owner_shell.enabled' => false,
            'owner_shell.allowlisted_shop_ids' => [],
        ]);
        $owner = ShopOwner::factory()->approved()->create();
        $request = Request::create('/shop-owner/dashboard');
        $request->setLaravelSession(app('session')->driver());
        Auth::guard('shop_owner')->logout();
        $this->actingAs($owner, 'shop_owner');
        Log::spy();

        $middleware = app(HandleInertiaRequests::class);
        $middleware->share($request);
        $middleware->share($request);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('shop_owner_shell_selection', Mockery::on(
                static fn (array $context): bool => $context['shop_id'] === $owner->getKey()
                    && $context['presentation'] === 'existing'
                    && $context['reason'] === 'global_disabled'
                    && $context['session_id'] === $request->session()->getId(),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function shareForGuest(): array
    {
        Auth::guard('shop_owner')->logout();
        Auth::guard('user')->logout();
        Auth::guard('super_admin')->logout();

        return app(HandleInertiaRequests::class)->share(Request::create('/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function shareFor(object $actor, string $guard): array
    {
        Auth::guard('shop_owner')->logout();
        Auth::guard('user')->logout();
        Auth::guard('super_admin')->logout();
        $this->actingAs($actor, $guard);

        return app(HandleInertiaRequests::class)->share(Request::create('/'));
    }

    /**
     * @return array<string, string>
     */
    private function canonicalRoutes(): array
    {
        return [
            'shop-owner.shell.home' => '/shop-owner/home',
            'shop-owner.shell.operate.retail' => '/shop-owner/operate/retail',
            'shop-owner.shell.operate.repair' => '/shop-owner/operate/repair',
            'shop-owner.shell.operate.customers' => '/shop-owner/operate/customers',
            'shop-owner.shell.operate.payments' => '/shop-owner/operate/payments',
            'shop-owner.shell.oversee.finance' => '/shop-owner/oversee/finance',
            'shop-owner.shell.oversee.workforce' => '/shop-owner/oversee/workforce',
            'shop-owner.shell.oversee.inventory' => '/shop-owner/oversee/inventory',
            'shop-owner.shell.oversee.procurement' => '/shop-owner/oversee/procurement',
            'shop-owner.shell.oversee.logistics' => '/shop-owner/oversee/logistics',
            'shop-owner.shell.reports' => '/shop-owner/reports',
            'shop-owner.shell.audit' => '/shop-owner/audit',
            'shop-owner.shell.settings.profile' => '/shop-owner/settings/profile',
            'shop-owner.shell.settings.modules-team' => '/shop-owner/settings/modules-team',
            'shop-owner.shell.settings.payments-approvals' => '/shop-owner/settings/payments-approvals',
            'shop-owner.shell.settings.operations' => '/shop-owner/settings/operations',
            'shop-owner.shell.settings.policies-compliance' => '/shop-owner/settings/policies-compliance',
            'shop-owner.shell.settings.subscription' => '/shop-owner/settings/subscription',
        ];
    }
}
