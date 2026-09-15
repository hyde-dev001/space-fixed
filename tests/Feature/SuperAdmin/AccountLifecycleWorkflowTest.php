<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class AccountLifecycleWorkflowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_user_suspension_has_one_stable_suspension_appeal_and_audit_across_retries(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $first = $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'First verified reason',
        ]);
        $second = $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'First verified reason',
        ]);

        $first->assertOk();
        $second->assertOk();

        $user->refresh();
        $this->assertSame('suspended', $user->getRawOriginal('status'));
        $this->assertNotNull($user->current_suspension_id);
        $this->assertSame(1, AccountSuspension::query()->count());
        $this->assertSame(1, SuspensionAppeal::query()->count());
        $this->assertDatabaseHas('suspension_appeals', [
            'suspension_id' => $user->current_suspension_id,
            'account_type' => AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            'account_id' => $user->id,
            'status' => 'eligible',
        ]);
        $this->assertSame(1, Activity::query()->where('event', 'user_suspended')->count());
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($user): bool {
            return $job->deliveryType === PrivilegedDeliveryType::CUSTOMER_SUSPENSION_NOTICE
                && $job->recipientType === 'user'
                && $job->recipientId === $user->id
                && $job->businessEventId === 'account-suspension:'.$user->current_suspension_id.':notice';
        });
    }

    public function test_conflicting_user_suspension_returns_conflict_without_new_state(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Original reason',
        ])->assertOk();

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Different reason',
        ])->assertStatus(409);

        $this->assertSame(1, AccountSuspension::query()->count());
        $this->assertSame(1, SuspensionAppeal::query()->count());
        $this->assertSame(1, Activity::query()->where('event', 'user_suspended')->count());
        $this->assertSame('Original reason', AccountSuspension::query()->sole()->reason);
    }

    public function test_shop_suspension_requires_approved_source_and_reactivation_does_not_approve_pending_or_rejected_shops(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $approved = $this->approvedPhaseTwoShop();
        $pending = ShopOwner::factory()->pending()->create();
        $rejected = ShopOwner::factory()->rejected()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/shops/{$approved->id}/suspend", [
            'suspension_reason' => 'Shop policy violation',
        ])->assertOk();

        $this->postJson("/admin/shops/{$approved->id}/reactivate", [
            'reactivation_reason' => 'Shop policy remediation verified',
        ])->assertOk();
        $this->assertSame('approved', $approved->fresh()->getRawOriginal('status'));

        foreach ([$pending, $rejected] as $shop) {
            $this->postJson("/admin/shops/{$shop->id}/reactivate", [
                'reactivation_reason' => 'Source-state verification',
            ])
                ->assertStatus(409);
            $this->assertSame(
                $shop->getRawOriginal('status'),
                $shop->fresh()->getRawOriginal('status'),
            );
        }
    }

    public function test_shop_linked_users_are_not_customer_lifecycle_targets(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'shop-linked-lifecycle@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $employee = $this->linkedPhaseTwoEmployee($user, $shop);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'This must remain shop-managed.',
        ])->assertNotFound();

        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertSame('active', $employee->fresh()->getRawOriginal('status'));
        $this->assertSame(0, AccountSuspension::query()->count());
        $this->assertSame(0, SuspensionAppeal::query()->count());
    }

    public function test_mandatory_audit_failure_rolls_back_the_account_suspension(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('userSuspended')->once()->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(PrivilegedAudit::class, $audit);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Audit rollback test',
        ])->assertStatus(500);

        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertSame(0, AccountSuspension::query()->count());
        $this->assertSame(0, SuspensionAppeal::query()->count());
    }

    public function test_archive_and_restore_preserve_relations_and_business_status(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $address = $user->addresses()->create([
            'name' => 'Archive Test',
            'phone' => '555-0100',
            'region' => 'Region',
            'province' => 'Province',
            'city' => 'City',
            'barangay' => 'Barangay',
            'postal_code' => '1000',
            'address_line' => '1 Main Street',
            'is_default' => true,
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [
            'archive_reason' => 'Retention request',
        ])->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('user_addresses', ['id' => $address->id, 'user_id' => $user->id]);

        $this->postJson("/admin/users/{$user->id}/restore", [
            'restore_reason' => 'Retention request reversed',
        ])->assertOk();

        $restored = User::query()->findOrFail($user->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('active', $restored->getRawOriginal('status'));
        $this->assertDatabaseHas('user_addresses', ['id' => $address->id, 'user_id' => $user->id]);
    }

    public function test_shop_archive_and_restore_preserve_approved_status(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $this->actingAsCompletedPrivileged($admin);
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/shops/{$shop->id}/archive", [
            'archive_reason' => 'Shop retention request',
        ])->assertOk();

        $this->assertSoftDeleted('shop_owners', ['id' => $shop->id]);

        $this->postJson("/admin/shops/{$shop->id}/restore", [
            'restore_reason' => 'Shop retention request reversed',
        ])->assertOk();

        $restored = ShopOwner::query()->findOrFail($shop->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('approved', $restored->getRawOriginal('status'));
    }

    public function test_privileged_management_reads_include_archived_accounts_without_changing_normal_scopes(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $shop = $this->approvedPhaseTwoShop();
        $this->actingAsCompletedPrivileged($admin);
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [
            'archive_reason' => 'Archive user for read-model coverage.',
        ])->assertOk();
        $this->postJson("/admin/shops/{$shop->id}/archive", [
            'archive_reason' => 'Archive shop for read-model coverage.',
        ])->assertOk();

        $this->assertNull(User::query()->find($user->id));
        $this->assertNull(ShopOwner::query()->find($shop->id));

        $this->get(route('admin.users.index', ['lifecycle' => 'archived']))
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.id', $user->id)
                ->where('users.data.0.status', 'archived')
                ->where('users.data.0.accountStatus', 'active')
                ->where('users.data.0.archived', true));

        $this->get(route('superAdmin.super-admin-user-management', ['lifecycle' => 'archived']))
            ->assertRedirect(route('admin.users.index', ['lifecycle' => 'archived']));

        $this->get(route('admin.shops.index', ['lifecycle' => 'archived']))
            ->assertInertia(fn ($page) => $page
                ->where('shops.data.0.id', $shop->id)
                ->where('shops.data.0.status', 'archived')
                ->where('shops.data.0.accountStatus', 'approved')
                ->where('shops.data.0.archived', true)
                ->where('stats.archived', 1));

        $this->get(route('admin.shops.show', ['shopOwner' => $shop->id]))
            ->assertOk()
            ->assertJsonPath('shop.archived', true)
            ->assertJsonPath('shop.accountStatus', 'approved');
    }

    public function test_archive_and_restore_require_recent_reauthentication_and_reason(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [])
            ->assertStatus(423);

        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [])
            ->assertStatus(422);
    }

    public function test_archived_suspended_user_restores_as_suspended(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Archive state test',
        ])->assertOk();
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [
            'archive_reason' => 'Archive suspended account',
        ])->assertOk();
        $this->postJson("/admin/users/{$user->id}/restore", [
            'restore_reason' => 'Restore without activation',
        ])->assertOk();

        $this->assertSame('suspended', User::query()->findOrFail($user->id)->getRawOriginal('status'));
    }

    public function test_archive_and_restore_routes_require_recent_reauthentication_and_post(): void
    {
        foreach (['admin.users.archive', 'admin.users.restore', 'admin.shops.archive', 'admin.shops.restore'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertSame(['POST'], $route->methods(), "Unexpected method for {$name}");
            $this->assertContains('privileged.capability:intervene_accounts', $route->middleware());
            $this->assertContains('privileged.recent', $route->middleware());
        }
    }

    public function test_lifecycle_mutations_are_not_available_on_get(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->get("/admin/users/{$user->id}/suspend")->assertStatus(405);
        $this->get("/admin/users/{$user->id}/archive")->assertStatus(405);
        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
    }

    private function markPrivilegedReauthenticated(\App\Models\SuperAdmin $admin): void
    {
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => $admin->security_version,
        ]);
    }
}
